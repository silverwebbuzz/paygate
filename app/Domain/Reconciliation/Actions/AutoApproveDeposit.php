<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Platform\Settings;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Transaction\Actions\DecidePayin;
use App\Domain\Transaction\Actions\MovePayin;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Domain\Transaction\Utr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves a deposit by itself when a branch's bank line proves it (decided
 * 2026-10-21; Global Settings switch). The branch enters (or imports) the
 * UTR and amount that reached its account; the customer entered their UTR on
 * the payment page. In whichever order they arrive:
 *
 * 1. Same account: the line is linked (StatementMatcher) → approved.
 * 2. The deposit waits at another branch (the customer paid this account
 *    on an earlier request, then entered the UTR on a new one): the
 *    deposit is moved here (MovePayin) → approved — only if this customer
 *    really was given this account in the last day (an earlier deposit, or
 *    before "Get different details"). Otherwise the UTR may be someone
 *    else's typed in by a fraudster, so Admin decides (Wrong branch case).
 *
 * Only when everything is clean: exact UTR and exact amount, the deposit is
 * waiting (or on hold only because its branch said "not in our bank"), one
 * waiting deposit and one bank line with that UTR, and this branch is mapped
 * to the partner for deposits. Anything else stays in the queues for a
 * person, as before. The approver is the person who entered / imported the
 * line, so the timeline shows who said the money arrived.
 *
 * Call inside the caller's database transaction; a refused approval rolls
 * back to a savepoint and leaves the line and deposit as they were.
 */
class AutoApproveDeposit
{
    private const WAITING = [PayinStatus::PaymentSubmitted, PayinStatus::PaymentDetected];

    public function __construct(
        private DecidePayin $decide,
        private MovePayin $move,
        private Settings $settings,
        private AlertDispatcher $alerts,
    ) {}

    /**
     * A bank line was entered or imported (and matched).
     */
    public function forLine(StatementEntry $entry): ?Transaction
    {
        if (! $this->settings->autoApproveDeposits() || ! $entry->isCredit() || $entry->utr_normalized === null) {
            return null;
        }

        $entry->refresh();

        if ($entry->transaction_id !== null) {
            $payin = Transaction::query()->find($entry->transaction_id);

            return $payin === null ? null : $this->approve($entry, $payin);
        }

        $payin = $this->strayDeposit($entry);

        return $payin === null ? null : $this->moveAndApprove($entry, $payin);
    }

    /**
     * The customer entered their UTR (or Admin moved the deposit): a bank
     * line may already show it, on this account or another branch's.
     */
    public function forPayin(Transaction $payin): ?Transaction
    {
        $utr = $payin->customer_utr_normalized;

        if (! $this->settings->autoApproveDeposits() || $utr === null) {
            return null;
        }

        $linked = StatementEntry::query()->where(['transaction_id' => $payin->id, 'entry_direction' => 'credit'])->first();

        if ($linked !== null) {
            return $this->approve($linked, $payin);
        }

        $lines = StatementEntry::query()->where('utr_normalized', $utr)->get();
        $line = $lines->first();

        if ($lines->count() !== 1 || ! $line->isCredit() || $line->transaction_id !== null || $line->status !== 'unmatched') {
            return null;
        }

        if ($line->amount !== $payin->amount) {
            return null;
        }

        return $this->moveAndApprove($line, $payin);
    }

    /**
     * The one waiting deposit with this line's UTR, on another account.
     */
    private function strayDeposit(StatementEntry $entry): ?Transaction
    {
        $utr = (string) $entry->utr_normalized;

        if ($entry->status !== 'unmatched' || StatementEntry::query()->where('utr_normalized', $utr)->whereKeyNot($entry->id)->exists()) {
            return null;
        }

        // At most one: a UTR on a waiting deposit can't be entered on another (Utr).
        $payin = Transaction::query()
            ->where(['direction' => 'payin', 'customer_utr_normalized' => $utr])
            ->whereIn('status', Utr::CLAIMING)
            ->first();

        if ($payin === null || $payin->payment_account_id === $entry->payment_account_id || $payin->amount !== $entry->amount) {
            return null;
        }

        return $payin;
    }

    private function moveAndApprove(StatementEntry $line, Transaction $payin): ?Transaction
    {
        $actor = $this->actor($line);
        $account = $line->paymentAccount;

        if ($actor === null || ! $this->eligible($payin) || ! $this->mapped($payin, $account->branch_id) || ! $this->wasGiven($payin, $account->id)) {
            return null;
        }

        $fromBranch = (string) $payin->branch_id;

        try {
            $approved = DB::transaction(function () use ($actor, $payin, $account, $line) {
                $this->move->handle($actor, $payin, $account, __('Automatic: the bank line of :account shows UTR :utr for this amount.', [
                    'account' => $account->label,
                    'utr' => $line->utr_normalized,
                ]), alert: false);

                // Moved only to be approved: if it can't be, undo the move too.
                return $this->approve($line->refresh(), $payin->refresh(), rethrow: true)
                    ?? throw ValidationException::withMessages(['payin' => 'Not approvable after the move.']);
            });
        } catch (ValidationException) {
            return null;
        }

        $this->alerts->depositAutoMoved($approved, $fromBranch);

        return $approved;
    }

    private function approve(StatementEntry $line, Transaction $payin, bool $rethrow = false): ?Transaction
    {
        $actor = $this->actor($line);

        if ($actor === null || $line->transaction_id !== $payin->id || $line->amount !== $payin->amount || ! $this->eligible($payin)) {
            return null;
        }

        // The UTR on a second bank line too: a mistake or fraud, for Admin.
        if (StatementEntry::query()->where('utr_normalized', $line->utr_normalized)->whereKeyNot($line->id)->exists()) {
            return null;
        }

        try {
            return DB::transaction(fn () => $this->decide->autoApprove(
                $actor,
                $payin,
                (string) ($line->utr ?? $line->utr_normalized),
                __('Approved automatically: the bank line shows UTR :utr for ₹:amount.', [
                    'utr' => $line->utr_normalized,
                    'amount' => number_format($line->amount / 100, 2),
                ]),
            ));
        } catch (ValidationException $exception) {
            if ($rethrow) {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * Waiting for a decision, or on hold only because its branch said the
     * money isn't in its bank (the case a move fixes). A deliberate hold
     * stays with a person.
     */
    private function eligible(Transaction $payin): bool
    {
        $status = $payin->payinStatus();

        return in_array($status, self::WAITING, true)
            || ($status === PayinStatus::UnderReview && $payin->status_reason_code === DecidePayin::NOT_IN_BANK);
    }

    /**
     * Whether this customer of this partner was shown that account (any of
     * their deposits allocated to it) in the last day: the trace a genuine
     * "paid there, entered the UTR here" leaves.
     */
    private function wasGiven(Transaction $payin, string $accountId): bool
    {
        return TransactionEvent::query()
            ->join('transactions as t', 't.id', '=', 'transaction_events.transaction_id')
            ->where('transaction_events.event', 'allocated')
            ->whereRaw("transaction_events.data->>'account_id' = ?", [$accountId])
            ->where('transaction_events.created_at', '>=', now()->subDay())
            ->where('t.partner_id', $payin->partner_id)
            ->where('t.partner_customer_id', $payin->partner_customer_id)
            ->exists();
    }

    private function mapped(Transaction $payin, string $branchId): bool
    {
        return PartnerBranchMapping::query()
            ->where(['partner_id' => $payin->partner_id, 'branch_id' => $branchId, 'status' => 'active', 'is_deposit_enabled' => true])
            ->exists();
    }

    /**
     * Who said the money arrived: the person who entered or imported the line.
     */
    private function actor(StatementEntry $line): ?User
    {
        $id = $line->created_by ?? $line->import?->imported_by;

        return $id === null ? null : User::query()->find($id);
    }
}
