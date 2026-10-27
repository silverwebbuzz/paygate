<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Payout\Models\PayoutPayment;
use App\Domain\Reconciliation\Enums\CaseType;
use App\Domain\Reconciliation\Enums\Resolution;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\StatementMatcher;
use App\Domain\Transaction\Actions\DecidePayin;
use App\Domain\Transaction\Actions\MovePayin;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A person closes a case of the unsettled queue (decided 2026-09-28,
 * G-25 / G-26). Every resolution is audited.
 *
 * - link: the bank line is this deposit / payout. Same account (deposits)
 *   or branch (payouts), exact amount; deposits waiting for approval or
 *   approved. A payout links through one of its recorded transfers of
 *   that amount with no line yet (the one with the line's UTR first).
 * - approveLate (Admin only): the deposit expired or was declined, but the
 *   money arrived; it is approved now and the partner credited.
 * - moveDeposit (Admin only, "wrong branch" cases): the customer's deposit
 *   is waiting at another branch, but this line shows the money here; the
 *   deposit moves to this line's account (MovePayin) and the line links.
 * - moveLine (decided 2026-10-27): a line typed in under the wrong
 *   account of the branch (e.g. HDFC 1 instead of HDFC 2) moves to the
 *   right one and is matched again. Typed lines only: an imported line's
 *   account is the statement file's.
 * - close as "not a customer payment" (bank charges, interest, own
 *   transfers, a line typed by mistake) or "returned to customer" (the
 *   branch sent a credit back outside PayGate). A line closed as not a
 *   customer payment gives up its UTR: another line with that UTR waiting
 *   as a duplicate is matched again.
 *
 * Nothing here changes the ledger except a late approval, which books the
 * deposit like any approval.
 */
class ResolveCase
{
    private const LINKABLE_PAYIN = ['payment_submitted', 'payment_detected', 'under_review', 'success'];

    public function __construct(private StatementMatcher $matcher, private DecidePayin $decide, private MovePayin $move, private AutoApproveDeposit $autoApprove) {}

    public function link(User $actor, ReconciliationCase $case, Transaction $txn, ?string $note = null): void
    {
        DB::transaction(function () use ($actor, $case, $txn, $note) {
            $entry = $this->lockOpen($case);
            $txn = $this->lockTransaction($txn);

            $this->ensureSameMoney($entry, $txn);
            $part = $txn->isPayin() ? null : $this->transferFor($entry, $txn);

            if ($txn->isPayin() && ! in_array($txn->status, self::LINKABLE_PAYIN, true)) {
                throw ValidationException::withMessages(['transaction' => in_array($txn->status, ['rejected', 'expired'], true)
                    ? __(':reference is :status: an administrator can approve it late.', ['reference' => $txn->reference, 'status' => $txn->status])
                    : __(':reference is :status and can’t be linked.', ['reference' => $txn->reference, 'status' => str_replace('_', ' ', $txn->status)])]);
            }

            $this->matcher->link($entry, $txn, $actor, $note ?? __('Linked to :reference by hand.', ['reference' => $txn->reference]), $part);
        });
    }

    public function moveDeposit(User $actor, ReconciliationCase $case, string $note): Transaction
    {
        return DB::transaction(function () use ($actor, $case, $note) {
            $entry = $this->lockOpen($case);
            $payin = $case->transaction_id === null ? null : Transaction::query()->find($case->transaction_id);

            if ($case->type !== CaseType::WrongBranch || $payin === null || ! $payin->isPayin() || ! $entry->isCredit()) {
                throw ValidationException::withMessages(['case' => __('Only a “wrong branch” case about a deposit can move it here.')]);
            }

            $moved = $this->move->handle($actor, $payin, $entry->paymentAccount, $note);

            // Moving links the line when the UTR and amount agree; otherwise
            // link it now if the amount does.
            $entry->refresh();

            if ($entry->transaction_id === null && $entry->openCase()->first()?->type === CaseType::WrongBranch && $entry->amount === $moved->amount) {
                $this->matcher->link($entry, $moved, $actor, $note);
            }

            return $moved;
        });
    }

    public function approveLate(User $actor, ReconciliationCase $case, Transaction $payin, ?string $bankUtr, ?string $note = null): Transaction
    {
        if (! $actor->isType(UserType::Admin)) {
            throw ValidationException::withMessages(['transaction' => __('Only an administrator can approve a late payment.')]);
        }

        return DB::transaction(function () use ($actor, $case, $payin, $bankUtr, $note) {
            $entry = $this->lockOpen($case);
            $payin = $this->lockTransaction($payin);

            $this->ensureSameMoney($entry, $payin, exactAmount: false);

            $utr = trim((string) $bankUtr) !== '' ? (string) $bankUtr : $entry->utr_normalized;

            if ($utr === null) {
                throw ValidationException::withMessages(['bank_utr' => __('Enter the bank UTR of this credit.')]);
            }

            $approved = $this->decide->approveLate($actor, $payin, $utr, $note ?? __('Late payment, approved from case :case.', ['case' => $case->reference]), $entry->amount);

            // Approval links a line with the same UTR itself; otherwise link this one.
            if ($entry->refresh()->transaction_id === null) {
                $this->matcher->link($entry, $approved, $actor, __('Late payment approved.'));
            }

            return $approved;
        });
    }

    /**
     * The line was typed under the wrong account of its branch: move it to
     * the right one and match it again (it may link and settle, or stay open
     * with the new reason). The line keeps its history; the move is audited.
     */
    public function moveLine(User $actor, ReconciliationCase $case, PaymentAccount $account, string $note): StatementEntry
    {
        return DB::transaction(function () use ($actor, $case, $account, $note) {
            $entry = $this->lockOpen($case);
            $from = $entry->paymentAccount;

            $problem = match (true) {
                $entry->import_id !== null => __('This line came from an imported statement file: its account is the file’s. Close it instead.'),
                $entry->transaction_id !== null => __('This line is already linked to a transaction.'),
                $account->branch_id !== $entry->branch_id => __('Choose an account of the same branch.'),
                $account->id === $entry->payment_account_id => __('The line is already on :account.', ['account' => $account->label]),
                default => null,
            };

            if ($problem !== null) {
                throw ValidationException::withMessages(['payment_account_id' => $problem]);
            }

            $hash = StatementEntry::rowHash($account->id, $entry->value_date->toDateString(), $entry->entry_direction, $entry->amount, $entry->utr_normalized, $entry->description);

            if (StatementEntry::query()->where(['payment_account_id' => $account->id, 'row_hash' => $hash])->exists()) {
                throw ValidationException::withMessages(['payment_account_id' => __(':account already has this line. Close this one as not a customer payment (typed by mistake).', ['account' => $account->label])]);
            }

            $entry->forceFill(['payment_account_id' => $account->id, 'row_hash' => $hash])->save();
            $entry->setRelation('paymentAccount', $account);

            AuditLog::record('statement.entry_moved', $entry, ['account' => $from->label], ['account' => $account->label, 'note' => $note], $actor);

            $this->matcher->match($entry);
            $this->autoApprove->forLine($entry);

            // Keep the move in the case notes (matching may have rewritten them).
            $current = $entry->openCase()->first() ?? $case->refresh();
            $current->forceFill(['notes' => trim(($current->notes ?? '')."\n".__('Moved by :name from :from to :to: :note', ['name' => $actor->name, 'from' => $from->label, 'to' => $account->label, 'note' => $note]))])->save();

            return $entry->refresh();
        });
    }

    public function close(User $actor, ReconciliationCase $case, Resolution $resolution, string $note): void
    {
        if (! in_array($resolution, [Resolution::Rejected, Resolution::Refunded], true)) {
            throw ValidationException::withMessages(['resolution' => __('Choose how to close this case.')]);
        }

        DB::transaction(function () use ($actor, $case, $resolution, $note) {
            $entry = $this->lockOpen($case);

            if ($resolution === Resolution::Refunded && ! $entry->isCredit()) {
                throw ValidationException::withMessages(['resolution' => __('Only money received can be returned to a customer.')]);
            }

            $entry->forceFill(['status' => 'ignored', 'transaction_id' => null])->save();
            $this->matcher->resolve($case, $resolution, $actor, $note);

            // Not a customer payment (e.g. typed under the wrong account): its
            // UTR is free again, so a line waiting as its duplicate can match.
            if ($resolution === Resolution::Rejected && $entry->utr_normalized !== null) {
                $waiting = StatementEntry::query()
                    ->where(['utr_normalized' => $entry->utr_normalized, 'status' => 'duplicate'])
                    ->whereKeyNot($entry->id)
                    ->whereHas('openCase')
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($waiting as $other) {
                    $this->matcher->match($other);
                    $this->autoApprove->forLine($other);
                }
            }
        });
    }

    private function lockOpen(ReconciliationCase $case): StatementEntry
    {
        /** @var ReconciliationCase $locked */
        $locked = ReconciliationCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            throw ValidationException::withMessages(['case' => __('This case was already resolved.')]);
        }

        /** @var StatementEntry $entry */
        $entry = StatementEntry::query()->whereKey($locked->statement_entry_id)->lockForUpdate()->firstOrFail();
        $case->setRawAttributes($locked->getAttributes(), true);

        return $entry;
    }

    /**
     * The payout transfer this debit line is: same amount, no line yet; the
     * one with the line's UTR first.
     */
    private function transferFor(StatementEntry $entry, Transaction $payout): PayoutPayment
    {
        $part = PayoutPayment::query()
            ->where(['transaction_id' => $payout->id, 'amount' => $entry->amount])
            ->whereDoesntHave('statementEntry')
            ->orderByRaw('utr_normalized = ? DESC', [(string) $entry->utr_normalized])
            ->orderBy('paid_at')
            ->first();

        return $part ?? throw ValidationException::withMessages(['transaction' => PayoutPayment::query()->where('transaction_id', $payout->id)->exists()
            ? __(':reference has no recorded transfer of ₹:amount without a bank line. Only exact amounts can be linked.', ['reference' => $payout->reference, 'amount' => number_format($entry->amount / 100, 2)])
            : __('Only a paid payout can be linked: record the transfer on :reference first.', ['reference' => $payout->reference])]);
    }

    private function lockTransaction(Transaction $txn): Transaction
    {
        /** @var Transaction */
        return Transaction::query()->with('partner')->whereKey($txn->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Exact rules only (G-24): the right direction, the same account (or
     * branch for payouts), the same amount, and not linked to another line.
     */
    private function ensureSameMoney(StatementEntry $entry, Transaction $txn, bool $exactAmount = true): void
    {
        $problem = match (true) {
            $entry->isCredit() !== $txn->isPayin() => $entry->isCredit()
                ? __('Money received can only be linked to a deposit.')
                : __('Money paid out can only be linked to a payout.'),
            $txn->isPayin() && $txn->payment_account_id !== $entry->payment_account_id => __(':reference was paid to another account.', ['reference' => $txn->reference]),
            ! $txn->isPayin() && $txn->branch_id !== $entry->branch_id => __(':reference was paid by another branch.', ['reference' => $txn->reference]),
            $exactAmount && $txn->isPayin() && $txn->amount !== $entry->amount => __(in_array($txn->status, ['payment_submitted', 'payment_detected', 'under_review'], true)
                ? 'The amounts differ (bank ₹:bank, :reference ₹:txn). Approve :reference in Deposits for the amount received; this line then links itself.'
                : 'The amounts differ (bank ₹:bank, :reference ₹:txn). Only exact amounts can be linked.', [
                    'bank' => number_format($entry->amount / 100, 2),
                    'reference' => $txn->reference,
                    'txn' => number_format($txn->amount / 100, 2),
                ]),
            $txn->isPayin() && StatementEntry::query()->where('transaction_id', $txn->id)->whereKeyNot($entry->id)->exists() => __(':reference is already linked to another bank line.', ['reference' => $txn->reference]),
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['transaction' => $problem]);
        }
    }
}
