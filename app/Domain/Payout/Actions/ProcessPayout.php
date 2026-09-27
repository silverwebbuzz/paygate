<?php

namespace App\Domain\Payout\Actions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\CommissionCalculator;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Domain\Payout\Enums\PayoutStatus;
use App\Domain\Payout\PayoutReservation;
use App\Domain\Payout\PayoutRouter;
use App\Domain\Transaction\Actions\SubmitPayinProof;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Domain\Webhook\Actions\QueueWebhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What happens to a payout in the branch's queue (decided 2026-09-27):
 *
 * - start: an operator takes it and pays from their bank (processing);
 * - complete: paid, with the bank UTR → success. In one DB transaction the
 *   commission is snapshotted (option W-A: the partner pays amount + fee,
 *   the branch earns its commission on top), the ledger journal is booked,
 *   the balance hold ends and usage is confirmed, and the webhook is queued;
 * - fail: the branch couldn't pay → failed, the hold is released and the
 *   partner is notified; the partner sends a new request to retry;
 * - cancel (partner, before the branch starts) and reassign (Admin, before
 *   the branch starts; the hold moves to the new branch).
 */
class ProcessPayout
{
    public function __construct(
        private PayoutRouter $router,
        private CommissionCalculator $commissions,
        private Ledger $ledger,
        private QueueWebhook $webhooks,
    ) {}

    public function start(User $actor, Transaction $payout): Transaction
    {
        return DB::transaction(function () use ($actor, $payout) {
            $locked = $this->lock($payout, [PayoutStatus::Assigned]);

            $locked->forceFill(['status' => PayoutStatus::Processing->value, 'decided_by' => $actor->id])->save();

            TransactionEvent::record($locked, 'processing', PayoutStatus::Assigned->value, $locked->status, 'user', $actor->id);

            return $this->sync($payout, $locked);
        });
    }

    public function complete(User $actor, Transaction $payout, string $bankUtr, ?string $note = null): Transaction
    {
        $utr = Transaction::normaliseUtr($bankUtr);

        if (preg_match(SubmitPayinProof::UTR_PATTERN, $utr) !== 1) {
            throw ValidationException::withMessages(['bank_utr' => __('Enter the UTR of your transfer (6–22 letters or digits).')]);
        }

        return DB::transaction(function () use ($actor, $payout, $bankUtr, $utr, $note) {
            $locked = $this->lock($payout, [PayoutStatus::Assigned, PayoutStatus::Processing]);
            $reservation = PayoutReservation::current($locked) ?? throw ValidationException::withMessages(['status' => __('This payout holds no balance; ask an administrator.')]);

            $mapping = PartnerBranchMapping::query()->findOrFail($reservation['mapping_id']);
            $commission = $this->commissions->calculate($mapping, Direction::Withdrawal, $locked->amount);

            $from = $locked->status;
            $locked->forceFill([
                ...$commission,
                'status' => PayoutStatus::Success->value,
                'bank_utr' => mb_substr(trim($bankUtr), 0, 50),
                'bank_utr_normalized' => $utr,
                'status_note' => $note,
                'decided_at' => now(),
                'decided_by' => $actor->id,
                'succeeded_at' => now(),
            ])->save();

            // Option W-A: the partner owes amount + its fee; the platform owes
            // the branch amount + the branch's commission.
            $this->ledger->post('payout_success', [
                $this->ledger->account(Ledger::PARTNER_POSITION, $locked->partner_id, $locked->branch_id) => -($locked->amount + $commission['partner_commission']),
                $this->ledger->account(Ledger::BRANCH_POSITION, $locked->partner_id, $locked->branch_id) => $locked->amount + $commission['branch_commission'],
                $this->ledger->account(Ledger::PLATFORM_MARGIN) => $commission['platform_margin'],
            ], $locked->id, "Payout {$locked->reference}", $actor->id);

            $this->router->confirm($reservation);
            TransactionEvent::record($locked, 'reservation_confirmed', $locked->status, $locked->status, 'system');

            TransactionEvent::record($locked, 'paid', $from, $locked->status, 'user', $actor->id, $note, [
                'bank_utr' => $utr,
                'partner_commission' => $commission['partner_commission'],
                'branch_commission' => $commission['branch_commission'],
            ]);
            AuditLog::record('payout.paid', $locked, ['status' => $from], ['status' => $locked->status, 'bank_utr' => $utr], $actor);
            $this->webhooks->forPayout($locked, 'payout.success');

            return $this->sync($payout, $locked);
        });
    }

    public function fail(User $actor, Transaction $payout, string $reasonCode, ?string $note = null): Transaction
    {
        return DB::transaction(function () use ($actor, $payout, $reasonCode, $note) {
            $locked = $this->lock($payout, [PayoutStatus::Assigned, PayoutStatus::Processing]);
            $this->releaseHold($locked, 'failed');

            $from = $locked->status;
            $locked->forceFill([
                'status' => PayoutStatus::Failed->value,
                'status_reason_code' => $reasonCode,
                'status_note' => $note,
                'decided_at' => now(),
                'decided_by' => $actor->id,
            ])->save();

            TransactionEvent::record($locked, 'failed', $from, $locked->status, 'user', $actor->id, $note, ['reason_code' => $reasonCode]);
            AuditLog::record('payout.failed', $locked, ['status' => $from], ['status' => $locked->status, 'reason_code' => $reasonCode, 'note' => $note], $actor);
            $this->webhooks->forPayout($locked, 'payout.failed');

            return $this->sync($payout, $locked);
        });
    }

    /**
     * The partner cancels a payout no branch has started paying.
     *
     * @return bool false when it had already moved on
     */
    public function cancel(Transaction $payout, string $actorType, ?string $actorId = null): bool
    {
        return DB::transaction(function () use ($payout, $actorType, $actorId) {
            /** @var Transaction $locked */
            $locked = Transaction::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayoutStatus::Assigned->value) {
                return false;
            }

            $this->releaseHold($locked, 'cancelled');
            $locked->forceFill(['status' => PayoutStatus::Cancelled->value, 'status_reason_code' => 'cancelled', 'decided_at' => now()])->save();
            TransactionEvent::record($locked, 'cancelled', PayoutStatus::Assigned->value, $locked->status, $actorType, $actorId);

            $this->sync($payout, $locked);

            return true;
        });
    }

    /**
     * Admin moves a waiting payout to another branch (decided 2026-09-27).
     * The new branch must hold enough of the partner's balance.
     */
    public function reassign(User $actor, Transaction $payout, Branch $to, string $reason): Transaction
    {
        return DB::transaction(function () use ($actor, $payout, $to, $reason) {
            $locked = $this->lock($payout, [PayoutStatus::Assigned]);

            if ($locked->branch_id === $to->id) {
                throw ValidationException::withMessages(['branch_id' => __('The payout is already with this branch.')]);
            }

            $old = PayoutReservation::current($locked);

            if ($old !== null) {
                $this->router->release($old);
            }

            $partner = Partner::query()->findOrFail($locked->partner_id);
            $new = $this->router->assign($partner, $locked->amount, onlyBranchId: $to->id);

            if ($new === null) {
                throw ValidationException::withMessages(['branch_id' => __(':branch can’t take it: not mapped for withdrawals, over its limits, or not enough of this partner’s balance there.', ['branch' => $to->code])]);
            }

            $fromBranch = $locked->branch_id;
            $locked->forceFill(['branch_id' => $to->id])->save();

            TransactionEvent::record($locked, 'reassigned', $locked->status, $locked->status, 'user', $actor->id, $reason, [
                'from_branch_id' => $fromBranch,
                'branch_id' => $to->id,
                'reservation' => $new,
            ]);
            AuditLog::record('payout.reassigned', $locked, ['branch_id' => $fromBranch], ['branch_id' => $to->id, 'reason' => $reason], $actor);

            return $this->sync($payout, $locked);
        });
    }

    private function releaseHold(Transaction $payout, string $reason): void
    {
        $reservation = PayoutReservation::current($payout);

        if ($reservation !== null) {
            $this->router->release($reservation);
            TransactionEvent::record($payout, 'reservation_released', $payout->status, $payout->status, 'system', null, $reason);
        }
    }

    /**
     * @param  list<PayoutStatus>  $allowed
     */
    private function lock(Transaction $payout, array $allowed): Transaction
    {
        /** @var Transaction $locked */
        $locked = Transaction::query()->with('partner')->whereKey($payout->id)->lockForUpdate()->firstOrFail();

        if ($locked->direction !== 'payout' || ! in_array(PayoutStatus::from($locked->status), $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('This payout is :status and can’t be changed now.', ['status' => str_replace('_', ' ', $locked->status)])]);
        }

        return $locked;
    }

    private function sync(Transaction $payout, Transaction $locked): Transaction
    {
        $payout->setRawAttributes($locked->getAttributes(), true);

        return $locked;
    }
}
