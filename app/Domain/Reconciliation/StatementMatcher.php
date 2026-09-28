<?php

namespace App\Domain\Reconciliation;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Reconciliation\Enums\CaseType;
use App\Domain\Reconciliation\Enums\Resolution;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use Illuminate\Support\Collection;

/**
 * Matches bank statement lines to transactions (Requirements F8; rules
 * decided 2026-09-28, G-24): only an exact UTR + exact amount + the same
 * branch account links a line. Anything else opens a case in the unsettled
 * queue for a person to resolve.
 *
 * - Credits match pay-ins on the receiving account. A credit for a deposit
 *   still waiting for approval is linked ("bank credit found"): the branch
 *   still approves it (G-23). A credit for an approved deposit with the same
 *   bank UTR is reconciled.
 * - Debits match paid payouts of the same branch by the payout's bank UTR.
 *
 * Matching also runs the other way, when a transaction changes: a customer
 * submits a UTR the statement already shows, a branch approves or declines
 * a deposit, or a payout is paid (see the transaction* methods).
 *
 * Call inside the caller's database transaction. Never touches the ledger.
 */
class StatementMatcher
{
    private const WAITING = ['payment_submitted', 'payment_detected', 'under_review'];

    private const CLOSED = ['rejected', 'expired', 'cancelled'];

    /**
     * A new statement line: link it or open a case.
     */
    public function match(StatementEntry $entry): void
    {
        $entry->isCredit() ? $this->matchCredit($entry) : $this->matchDebit($entry);
    }

    /**
     * The customer submitted a UTR: a bank line may already show it (the
     * statement arrived before the claim).
     */
    public function transactionSubmitted(Transaction $payin): void
    {
        if ($payin->customer_utr_normalized !== null) {
            $this->relinkWaitingLine($payin, $payin->customer_utr_normalized);
        }
    }

    /**
     * A deposit was approved with its bank UTR. A line already linked to it
     * becomes reconciled if the UTR agrees, else goes to review; an unlinked
     * line with that UTR is linked now.
     */
    public function transactionApproved(Transaction $payin, ?User $actor = null): void
    {
        $utr = (string) $payin->bank_utr_normalized;
        $linked = $this->linkedLine($payin);

        if ($linked !== null) {
            if ($linked->utr_normalized === $utr) {
                $linked->forceFill(['status' => 'reconciled'])->save();

                return;
            }

            $this->unlink($linked);
            $this->openCase($linked, CaseType::ManualReview, $payin, __('Approved with bank UTR :approved, but this bank line shows :line.', [
                'approved' => $utr,
                'line' => $linked->utr_normalized ?? __('no UTR'),
            ]));
        }

        $this->relinkWaitingLine($payin, $utr, $actor);
    }

    /**
     * A deposit was declined although its bank credit was found: that money
     * is in the branch's account, so the line needs a decision (G-25).
     */
    public function transactionDeclined(Transaction $payin): void
    {
        $linked = $this->linkedLine($payin);

        if ($linked !== null) {
            $this->unlink($linked);
            $this->openCase($linked, CaseType::LatePayment, $payin, __('The deposit was declined, but the bank statement shows the credit.'));
        }
    }

    /**
     * A payout was paid: link the branch's debit line with its UTR, if the
     * statement already has it.
     */
    public function payoutPaid(Transaction $payout, ?User $actor = null): void
    {
        $line = StatementEntry::query()
            ->where(['branch_id' => $payout->branch_id, 'entry_direction' => 'debit', 'utr_normalized' => $payout->bank_utr_normalized])
            ->whereNull('transaction_id')
            ->whereIn('status', ['unmatched'])
            ->lockForUpdate()
            ->first();

        if ($line === null) {
            return;
        }

        $line->amount === $payout->amount
            ? $this->link($line, $payout, $actor)
            : $this->retype($line, CaseType::AmountMismatch, $payout, $this->amountNote($line, $payout));
    }

    /**
     * Links a line to a transaction (automatically, or by a person resolving
     * a case) and closes the line's open case.
     */
    public function link(StatementEntry $entry, Transaction $txn, ?User $actor = null, ?string $note = null): void
    {
        $entry->forceFill([
            'transaction_id' => $txn->id,
            'status' => $txn->status === 'success' ? 'reconciled' : 'matched',
            'matched_at' => now(),
            'matched_by' => $actor?->id,
        ])->save();

        TransactionEvent::record($txn, 'bank_line_matched', $txn->status, $txn->status, $actor === null ? 'system' : 'user', $actor?->id, $note, [
            'statement_entry_id' => $entry->id,
            'utr' => $entry->utr_normalized,
            'value_date' => $entry->value_date->toDateString(),
        ]);

        $case = $entry->openCase()->first();

        if ($case !== null) {
            $this->resolve($case, Resolution::Linked, $actor, $note ?? __('Linked to :reference.', ['reference' => $txn->reference]), $txn);
        }
    }

    /**
     * Closes a case. Every resolution is audited (actor null = the system).
     */
    public function resolve(ReconciliationCase $case, Resolution $resolution, ?User $actor, ?string $note, ?Transaction $txn = null): void
    {
        $case->forceFill([
            'status' => 'resolved',
            'resolution' => $resolution,
            'resolved_by' => $actor?->id,
            'resolved_at' => now(),
            'transaction_id' => $txn->id ?? $case->transaction_id,
            'notes' => trim(($case->notes ?? '')."\n".($note ?? '')) ?: null,
        ])->save();

        AuditLog::record('reconciliation.case_resolved', $case, ['status' => 'open'], [
            'resolution' => $resolution->value,
            'transaction' => $txn?->reference,
            'note' => $note,
            'by' => $actor === null ? 'system' : 'user',
        ], $actor);
    }

    private function matchCredit(StatementEntry $entry): void
    {
        $utr = $entry->utr_normalized;

        if ($utr === null) {
            $this->openCase($entry, CaseType::ManualReview, null, __('This bank line has no UTR: find the deposit and link it.'));

            return;
        }

        if ($this->hasTwin($entry)) {
            $this->openCase($entry, CaseType::DuplicateUtr, null, __('Another bank line of this account has the same UTR.'), 'duplicate');

            return;
        }

        /** @var Collection<int, Transaction> $candidates */
        $candidates = Transaction::query()
            ->where('direction', 'payin')
            ->where(fn ($query) => $query->where('bank_utr_normalized', $utr)->orWhere('customer_utr_normalized', $utr))
            ->with(['branch', 'paymentAccount'])
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->get();

        $own = $candidates->where('payment_account_id', $entry->payment_account_id);
        $live = $own->filter(fn (Transaction $txn) => in_array($txn->status, self::WAITING, true)
            // An approved deposit matches only on the UTR the branch verified.
            || ($txn->status === 'success' && $txn->bank_utr_normalized === $utr));

        if ($live->count() > 1) {
            $this->openCase($entry, CaseType::DuplicateUtr, $live->first(), __('Several deposits claim this UTR: :references.', ['references' => $live->pluck('reference')->join(', ')]));

            return;
        }

        if ($live->count() === 1) {
            $this->linkOrExplain($entry, $live->firstOrFail());

            return;
        }

        $closed = $own->first(fn (Transaction $txn) => in_array($txn->status, self::CLOSED, true));

        if ($closed !== null) {
            $closed->amount === $entry->amount
                ? $this->openCase($entry, CaseType::LatePayment, $closed, __('The deposit is :status; the money arrived anyway.', ['status' => str_replace('_', ' ', $closed->status)]))
                : $this->openCase($entry, CaseType::AmountMismatch, $closed, $this->amountNote($entry, $closed));

            return;
        }

        if ($own->isNotEmpty()) {
            $txn = $own->firstOrFail();
            $this->openCase($entry, CaseType::ManualReview, $txn, $txn->status === 'success'
                ? __(':reference was approved with bank UTR :utr, not this line’s.', ['reference' => $txn->reference, 'utr' => $txn->bank_utr_normalized])
                : __(':reference has this UTR but is :status.', ['reference' => $txn->reference, 'status' => str_replace('_', ' ', $txn->status)]));

            return;
        }

        $elsewhere = $candidates->first();

        if ($elsewhere !== null) {
            $this->openCase($entry, CaseType::WrongBranch, $elsewhere, $elsewhere->branch_id === $entry->branch_id
                ? __(':reference was paid to another account of this branch (:account).', ['reference' => $elsewhere->reference, 'account' => $elsewhere->paymentAccount->label ?? '—'])
                : __(':reference belongs to branch :branch.', ['reference' => $elsewhere->reference, 'branch' => $elsewhere->branch->code ?? '—']));

            return;
        }

        $this->openCase($entry, CaseType::UtrNotFound, null, __('No deposit has this UTR yet. It links itself if the customer submits it later.'));
    }

    private function matchDebit(StatementEntry $entry): void
    {
        $utr = $entry->utr_normalized;

        if ($utr === null) {
            $this->openCase($entry, CaseType::ManualReview, null, __('This bank line has no UTR: link the payout, or mark it as not a customer payment (e.g. bank charges).'));

            return;
        }

        if ($this->hasTwin($entry)) {
            $this->openCase($entry, CaseType::DuplicateUtr, null, __('Another bank line of this account has the same UTR.'), 'duplicate');

            return;
        }

        /** @var Collection<int, Transaction> $payouts */
        $payouts = Transaction::query()
            ->where(['direction' => 'payout', 'bank_utr_normalized' => $utr])
            ->with('branch')
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->get();

        $own = $payouts->where('branch_id', $entry->branch_id);

        if ($own->count() > 1) {
            $this->openCase($entry, CaseType::DuplicateUtr, $own->first(), __('Several payouts were paid with this UTR: :references.', ['references' => $own->pluck('reference')->join(', ')]));

            return;
        }

        if ($own->count() === 1) {
            $this->linkOrExplain($entry, $own->firstOrFail());

            return;
        }

        $elsewhere = $payouts->first();

        $elsewhere !== null
            ? $this->openCase($entry, CaseType::WrongBranch, $elsewhere, __(':reference was paid by branch :branch.', ['reference' => $elsewhere->reference, 'branch' => $elsewhere->branch->code ?? '—']))
            : $this->openCase($entry, CaseType::UtrNotFound, null, __('No paid payout has this UTR yet. It links itself when the payout is marked paid with it.'));
    }

    /**
     * One candidate on the right account: link it if the amounts agree and
     * it isn't linked to another line already.
     */
    private function linkOrExplain(StatementEntry $entry, Transaction $txn): void
    {
        $other = $this->linkedLine($txn);

        if ($other !== null) {
            $this->openCase($entry, CaseType::DuplicateUtr, $txn, __(':reference is already linked to another bank line (:date).', ['reference' => $txn->reference, 'date' => $other->value_date->format('d M Y')]), 'duplicate');

            return;
        }

        if ($txn->amount !== $entry->amount) {
            $this->openCase($entry, CaseType::AmountMismatch, $txn, $this->amountNote($entry, $txn));

            return;
        }

        $this->link($entry, $txn);
    }

    /**
     * An unlinked credit line on the deposit's account with this UTR: link
     * it (resolving its case), or re-point its case at the deposit.
     */
    private function relinkWaitingLine(Transaction $payin, string $utr, ?User $actor = null): void
    {
        if ($this->linkedLine($payin) !== null) {
            return;
        }

        $line = StatementEntry::query()
            ->where(['payment_account_id' => $payin->payment_account_id, 'entry_direction' => 'credit', 'utr_normalized' => $utr, 'status' => 'unmatched'])
            ->whereNull('transaction_id')
            ->lockForUpdate()
            ->first();

        if ($line === null) {
            return;
        }

        $line->amount === $payin->amount
            ? $this->link($line, $payin, $actor, __('The deposit arrived after the bank line.'))
            : $this->retype($line, CaseType::AmountMismatch, $payin, $this->amountNote($line, $payin));
    }

    private function openCase(StatementEntry $entry, CaseType $type, ?Transaction $txn, string $note, string $status = 'unmatched'): void
    {
        $entry->forceFill(['status' => $status, 'transaction_id' => null])->save();

        $case = $entry->openCase()->first();

        if ($case !== null) {
            $this->retype($entry, $type, $txn, $note);

            return;
        }

        $case = ReconciliationCase::create([
            'reference' => ReconciliationCase::newReference(),
            'type' => $type,
            'status' => 'open',
            'branch_id' => $entry->branch_id,
            'transaction_id' => $txn?->id,
            'statement_entry_id' => $entry->id,
            'notes' => $note,
        ]);

        // Only Admin can approve a late payment: tell them.
        if ($type === CaseType::LatePayment) {
            app(AlertDispatcher::class)->latePayment($case);
        }
    }

    /**
     * The line's open case now has a different reason (e.g. the deposit it
     * points at arrived, with another amount).
     */
    private function retype(StatementEntry $entry, CaseType $type, ?Transaction $txn, string $note): void
    {
        $entry->openCase()->first()?->forceFill([
            'type' => $type,
            'transaction_id' => $txn?->id,
            'notes' => $note,
        ])->save();
    }

    private function unlink(StatementEntry $entry): void
    {
        $entry->forceFill(['transaction_id' => null, 'status' => 'unmatched', 'matched_at' => null, 'matched_by' => null])->save();
    }

    private function linkedLine(Transaction $txn): ?StatementEntry
    {
        return StatementEntry::query()->where('transaction_id', $txn->id)->lockForUpdate()->first();
    }

    private function hasTwin(StatementEntry $entry): bool
    {
        return StatementEntry::query()
            ->where(['payment_account_id' => $entry->payment_account_id, 'entry_direction' => $entry->entry_direction, 'utr_normalized' => $entry->utr_normalized])
            ->whereKeyNot($entry->id)
            ->exists();
    }

    private function amountNote(StatementEntry $entry, Transaction $txn): string
    {
        return __('The bank shows ₹:bank; :reference is for ₹:txn.', [
            'bank' => number_format($entry->amount / 100, 2),
            'reference' => $txn->reference,
            'txn' => number_format($txn->amount / 100, 2),
        ]);
    }
}
