<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
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
 *   approved, payouts paid.
 * - approveLate (Admin only): the deposit expired or was declined, but the
 *   money arrived; it is approved now and the partner credited.
 * - moveDeposit (Admin only, "wrong branch" cases): the customer's deposit
 *   is waiting at another branch, but this line shows the money here; the
 *   deposit moves to this line's account (MovePayin) and the line links.
 * - close as "not a customer payment" (bank charges, interest, own
 *   transfers, a line typed by mistake) or "returned to customer" (the
 *   branch sent a credit back outside PayGate).
 *
 * Nothing here changes the ledger except a late approval, which books the
 * deposit like any approval.
 */
class ResolveCase
{
    private const LINKABLE_PAYIN = ['payment_submitted', 'payment_detected', 'under_review', 'success'];

    public function __construct(private StatementMatcher $matcher, private DecidePayin $decide, private MovePayin $move) {}

    public function link(User $actor, ReconciliationCase $case, Transaction $txn, ?string $note = null): void
    {
        DB::transaction(function () use ($actor, $case, $txn, $note) {
            $entry = $this->lockOpen($case);
            $txn = $this->lockTransaction($txn);

            $this->ensureSameMoney($entry, $txn);

            if ($txn->isPayin() && ! in_array($txn->status, self::LINKABLE_PAYIN, true)) {
                throw ValidationException::withMessages(['transaction' => in_array($txn->status, ['rejected', 'expired'], true)
                    ? __(':reference is :status: an administrator can approve it late.', ['reference' => $txn->reference, 'status' => $txn->status])
                    : __(':reference is :status and can’t be linked.', ['reference' => $txn->reference, 'status' => str_replace('_', ' ', $txn->status)])]);
            }

            if (! $txn->isPayin() && $txn->status !== 'success') {
                throw ValidationException::withMessages(['transaction' => __('Only a paid payout can be linked: mark :reference paid first.', ['reference' => $txn->reference])]);
            }

            $this->matcher->link($entry, $txn, $actor, $note ?? __('Linked to :reference by hand.', ['reference' => $txn->reference]));
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
            $exactAmount && $txn->amount !== $entry->amount => __(in_array($txn->status, ['payment_submitted', 'payment_detected', 'under_review'], true) && $txn->isPayin()
                ? 'The amounts differ (bank ₹:bank, :reference ₹:txn). Approve :reference in Deposits for the amount received; this line then links itself.'
                : 'The amounts differ (bank ₹:bank, :reference ₹:txn). Only exact amounts can be linked.', [
                    'bank' => number_format($entry->amount / 100, 2),
                    'reference' => $txn->reference,
                    'txn' => number_format($txn->amount / 100, 2),
                ]),
            StatementEntry::query()->where('transaction_id', $txn->id)->whereKeyNot($entry->id)->exists() => __(':reference is already linked to another bank line.', ['reference' => $txn->reference]),
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['transaction' => $problem]);
        }
    }
}
