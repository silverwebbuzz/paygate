<?php

namespace App\Domain\Transaction\Actions;

use App\Domain\Allocation\Actions\ReleaseAllocation;
use App\Domain\Allocation\UsageCounters;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\StatementMatcher;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin moves a waiting deposit to the account the money really reached
 * (the customer paid one branch's account, then submitted their UTR on a
 * later request that was given another branch).
 *
 * Only before a decision (submitted / detected / on hold): nothing is booked
 * yet, as commission and ledger use the branch at approval. Today's capacity
 * moves with it, without limit checks (the money has already arrived). The
 * deposit goes back to "pending" at the new branch, which is alerted, and a
 * bank line already showing its UTR there is linked.
 */
class MovePayin
{
    private const MOVABLE = [PayinStatus::PaymentSubmitted, PayinStatus::PaymentDetected, PayinStatus::UnderReview];

    public function __construct(
        private ReleaseAllocation $allocation,
        private UsageCounters $usage,
        private StatementMatcher $matcher,
        private AlertDispatcher $alerts,
    ) {}

    public function handle(User $actor, Transaction $payin, PaymentAccount $to, string $reason): Transaction
    {
        return DB::transaction(function () use ($actor, $payin, $to, $reason) {
            /** @var Transaction $locked */
            $locked = Transaction::query()->whereKey($payin->id)->lockForUpdate()->firstOrFail();

            if ($locked->direction !== 'payin' || ! in_array($locked->payinStatus(), self::MOVABLE, true)) {
                throw ValidationException::withMessages(['payment_account_id' => __('Only a deposit waiting for the branch’s decision can be moved; :reference is :status.', [
                    'reference' => $locked->reference,
                    'status' => str_replace('_', ' ', $locked->status),
                ])]);
            }

            if ($locked->payment_account_id === $to->id) {
                throw ValidationException::withMessages(['payment_account_id' => __('The deposit is already on this account.')]);
            }

            if (StatementEntry::query()->where('transaction_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['payment_account_id' => __('A bank statement line of the current account already shows this payment, so it can’t be moved.')]);
            }

            $mapping = PartnerBranchMapping::query()->where(['partner_id' => $locked->partner_id, 'branch_id' => $to->branch_id])->first();

            if ($mapping === null) {
                throw ValidationException::withMessages(['payment_account_id' => __('This branch isn’t mapped to the partner, so the deposit can’t be booked there.')]);
            }

            $this->allocation->handle($locked, 'moved');

            $date = UsageCounters::businessDate();
            $scopes = [['account', $to->id, false], ['branch', $to->branch_id, false], ['mapping', $mapping->id, false], ['partner', $locked->partner_id, false]];

            foreach ($scopes as [$type, $id]) {
                $this->usage->reserve($type, $id, $date, Direction::Deposit, $locked->amount);
            }

            $from = ['branch_id' => $locked->branch_id, 'payment_account_id' => $locked->payment_account_id, 'status' => $locked->status];

            $locked->forceFill([
                'branch_id' => $to->branch_id,
                'payment_account_id' => $to->id,
                'status' => PayinStatus::PaymentSubmitted->value,
                'status_reason_code' => null,
                'status_note' => null,
            ])->save();

            // The new reservation, read by ReleaseAllocation when it is approved or declined.
            TransactionEvent::record($locked, 'allocated', $from['status'], $locked->status, 'user', $actor->id, $reason, [
                'account_id' => $to->id,
                'branch_id' => $to->branch_id,
                'method' => $locked->method,
                'reservation' => ['date' => $date, 'amount' => $locked->amount, 'scopes' => $scopes],
            ]);
            TransactionEvent::record($locked, 'moved', $from['status'], $locked->status, 'user', $actor->id, $reason, [
                'from_branch_id' => $from['branch_id'],
                'from_account_id' => $from['payment_account_id'],
                'branch_id' => $to->branch_id,
                'account_id' => $to->id,
            ]);
            AuditLog::record('payin.moved', $locked, $from, [
                'branch_id' => $to->branch_id,
                'payment_account_id' => $to->id,
                'status' => $locked->status,
                'reason' => $reason,
            ], $actor);

            $this->matcher->transactionSubmitted($locked);
            $this->alerts->depositMoved($locked, $reason);

            $payin->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }
}
