<?php

namespace App\Domain\Payout\Actions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\CommissionCalculator;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Partner\Models\Partner;
use App\Domain\Payout\Enums\PayoutStatus;
use App\Domain\Payout\Models\PayoutPayment;
use App\Domain\Payout\PayoutReservation;
use App\Domain\Payout\PayoutRouter;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Reconciliation\StatementMatcher;
use App\Domain\Transaction\Actions\SubmitPayinProof;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Domain\Transaction\Utr;
use App\Domain\Webhook\Actions\QueueWebhook;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What happens to a payout in the branch's queue (decided 2026-09-27):
 *
 * - start: an operator takes it and pays from their bank (processing);
 * - pay: one bank transfer (amount, UTR, date, proof; 2026-10-23: a payout
 *   may be paid in several parts, hours or days apart). Until the parts add
 *   up it stays processing; the last one makes it success: in one DB
 *   transaction the commission is snapshotted (option W-A: the partner pays
 *   amount + fee, the branch earns its commission on top), the balance hold
 *   ends and usage is confirmed, and the webhook is queued. Each transfer is
 *   booked on the day it is recorded (decided 2026-10-23, so each day's
 *   settlement matches the branch's bank): partner −amount, branch +amount,
 *   releasing that much of the hold; the last one also books the fees;
 * - fail: the branch couldn't pay → failed, the hold is released and the
 *   partner is notified; the partner sends a new request to retry. Not once
 *   a part was paid (the money left the bank: pay the rest);
 * - cancel (partner, before the branch starts) and reassign (Admin, before
 *   the branch pays any of it; the hold moves to the new branch).
 */
class ProcessPayout
{
    public function __construct(
        private PayoutRouter $router,
        private CommissionCalculator $commissions,
        private Ledger $ledger,
        private QueueWebhook $webhooks,
        private StatementMatcher $matcher,
        private AlertDispatcher $alerts,
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

    /**
     * The branch sent (part of) the payout: one bank transfer with its
     * amount, UTR, date and proof, booked the day it is recorded. The
     * payout is `processing` until the parts add up to its amount, then
     * `success`.
     */
    public function pay(User $actor, Transaction $payout, int $amount, string $bankUtr, CarbonInterface $paidAt, UploadedFile $proof, ?string $note = null): Transaction
    {
        $utr = Transaction::normaliseUtr($bankUtr);

        if (preg_match(SubmitPayinProof::UTR_PATTERN, $utr) !== 1) {
            throw ValidationException::withMessages(['bank_utr' => __('Enter the UTR of your transfer (6–22 letters or digits).')]);
        }

        if ($paidAt->isFuture()) {
            throw ValidationException::withMessages(['paid_at' => __('The transfer date can’t be in the future.')]);
        }

        try {
            return DB::transaction(function () use ($actor, $payout, $amount, $bankUtr, $utr, $paidAt, $proof, $note) {
                $locked = $this->lock($payout, [PayoutStatus::Assigned, PayoutStatus::Processing]);
                $remaining = $locked->amount - self::paidSoFar($locked);

                if ($amount <= 0 || $amount > $remaining) {
                    throw ValidationException::withMessages(['amount' => __('Enter an amount up to what is left to pay (₹:left).', ['left' => number_format($remaining / 100, 2)])]);
                }

                Utr::ensureFree($utr, null);

                if (PayoutReservation::current($locked) === null) {
                    throw ValidationException::withMessages(['status' => __('This payout holds no balance; ask an administrator.')]);
                }

                $from = $locked->status;

                if ($from === PayoutStatus::Assigned->value) {
                    $locked->forceFill(['status' => PayoutStatus::Processing->value, 'decided_by' => $actor->id])->save();
                }

                $part = PayoutPayment::create([
                    'transaction_id' => $locked->id,
                    'branch_id' => $locked->branch_id,
                    'amount' => $amount,
                    'utr' => mb_substr(trim($bankUtr), 0, 50),
                    'utr_normalized' => $utr,
                    'paid_at' => $paidAt->copy()->utc(),
                    'note' => $note === null || trim($note) === '' ? null : trim($note),
                    'created_by' => $actor->id,
                    'created_at' => now(),
                ]);
                $file = StoredFile::store($proof, 'payout_proof', $part, 'user', $actor->id);
                $part->forceFill(['proof_file_id' => $file->id])->save();

                $left = $remaining - $amount;
                $reservation = PayoutReservation::current($locked) ?? throw ValidationException::withMessages(['status' => __('This payout holds no balance; ask an administrator.')]);

                if ($left > 0) {
                    // Not the last transfer: `payout_part` (payout_success stays once per payout).
                    $journalId = $this->ledger->post('payout_part', [
                        $this->ledger->account(Ledger::PARTNER_POSITION, $locked->partner_id, $locked->branch_id) => -$amount,
                        $this->ledger->account(Ledger::BRANCH_POSITION, $locked->partner_id, $locked->branch_id) => $amount,
                    ], $locked->id, "Payout {$locked->reference}: transfer {$utr}", $actor->id);
                    $this->router->releasePart($reservation, $amount);
                    $part->forceFill(['journal_id' => $journalId])->save();
                }

                TransactionEvent::record($locked, 'part_paid', $from, $locked->status, 'user', $actor->id, $part->note, [
                    'bank_utr' => $utr,
                    'amount' => $amount,
                    'left' => $left,
                    'paid_at' => $part->paid_at->toIso8601String(),
                ]);
                AuditLog::record('payout.part_paid', $locked, [], ['amount' => $amount, 'bank_utr' => $utr, 'left' => $left], $actor);

                // Partners hear of each part (status processing, paid_amount
                // and payments filled), then payout.success with the last.
                $left === 0
                    ? $this->finish($actor, $locked, $part, $locked->amount - $remaining, $note)
                    : $this->webhooks->forPayout($locked, 'payout.partial');

                $this->matcher->payoutPartPaid($part, $locked, $actor);

                return $this->sync($payout, $locked);
            });
        } catch (QueryException $exception) {
            if (Utr::isDuplicateError($exception)) {
                Utr::ensureFree($utr, null);
            }

            throw $exception;
        }
    }

    /**
     * What the branch has sent so far on this payout.
     */
    public static function paidSoFar(Transaction $payout): int
    {
        return (int) PayoutPayment::query()->where('transaction_id', $payout->id)->sum('amount');
    }

    /**
     * The last transfer arrived: success. It is booked with the fees (the
     * earlier transfers were booked on their days); the rest of the hold
     * ends. $paidBefore: what earlier transfers booked and released.
     */
    private function finish(User $actor, Transaction $locked, PayoutPayment $last, int $paidBefore, ?string $note): void
    {
        $reservation = PayoutReservation::current($locked) ?? throw ValidationException::withMessages(['status' => __('This payout holds no balance; ask an administrator.')]);
        $mapping = PartnerBranchMapping::query()->findOrFail($reservation['mapping_id']);
        $commission = $this->commissions->calculate($mapping, Direction::Withdrawal, $locked->amount);
        // The first transfer's UTR stays the payout's UTR (API `utr`).
        $first = PayoutPayment::query()->where('transaction_id', $locked->id)->orderBy('paid_at')->orderBy('created_at')->firstOrFail();

        $from = $locked->status;
        $locked->forceFill([
            ...$commission,
            'status' => PayoutStatus::Success->value,
            'bank_utr' => $first->utr,
            'bank_utr_normalized' => $first->utr_normalized,
            'status_note' => $note,
            'decided_at' => now(),
            'decided_by' => $actor->id,
            'succeeded_at' => now(),
        ])->save();

        // Option W-A: the partner owes amount + its fee; the platform owes
        // the branch amount + the branch's commission.
        $journalId = $this->ledger->post('payout_success', [
            $this->ledger->account(Ledger::PARTNER_POSITION, $locked->partner_id, $locked->branch_id) => -($last->amount + $commission['partner_commission']),
            $this->ledger->account(Ledger::BRANCH_POSITION, $locked->partner_id, $locked->branch_id) => $last->amount + $commission['branch_commission'],
            $this->ledger->account(Ledger::PLATFORM_MARGIN) => $commission['platform_margin'],
        ], $locked->id, $paidBefore === 0 ? "Payout {$locked->reference}" : "Payout {$locked->reference}: last transfer {$last->utr_normalized} and fees", $actor->id);
        $last->forceFill(['journal_id' => $journalId, 'fees_booked' => true])->save();

        $this->router->confirm($reservation, $paidBefore);
        TransactionEvent::record($locked, 'reservation_confirmed', $locked->status, $locked->status, 'system');

        TransactionEvent::record($locked, 'paid', $from, $locked->status, 'user', $actor->id, $note, [
            'bank_utr' => $first->utr_normalized,
            'payments' => PayoutPayment::query()->where('transaction_id', $locked->id)->count(),
            'partner_commission' => $commission['partner_commission'],
            'branch_commission' => $commission['branch_commission'],
        ]);
        AuditLog::record('payout.paid', $locked, ['status' => $from], ['status' => $locked->status, 'bank_utr' => $first->utr_normalized], $actor);
        $this->webhooks->forPayout($locked, 'payout.success');
        $this->matcher->payoutCompleted($locked);
    }

    public function fail(User $actor, Transaction $payout, string $reasonCode, ?string $note = null): Transaction
    {
        return DB::transaction(function () use ($actor, $payout, $reasonCode, $note) {
            $locked = $this->lock($payout, [PayoutStatus::Assigned, PayoutStatus::Processing]);
            $this->ensureNothingPaid($locked, __('it can’t be marked failed. Pay the rest'));
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
     * Admin moves a waiting or in-progress payout to another branch, e.g.
     * when the branch says it has no money. No balance check.
     */
    public function reassign(User $actor, Transaction $payout, Branch $to, string $reason): Transaction
    {
        return DB::transaction(function () use ($actor, $payout, $to, $reason) {
            $locked = $this->lock($payout, [PayoutStatus::Assigned, PayoutStatus::Processing]);
            $this->ensureNothingPaid($locked, __('it can’t move to another branch. This branch pays the rest'));

            if ($locked->branch_id === $to->id) {
                throw ValidationException::withMessages(['branch_id' => __('The payout is already with this branch.')]);
            }

            $old = PayoutReservation::current($locked);

            if ($old !== null) {
                $this->router->release($old);
            }

            $partner = Partner::query()->findOrFail($locked->partner_id);
            $new = $this->router->assign($partner, $locked->amount, onlyBranchId: $to->id, checkBalance: false);

            if ($new === null) {
                throw ValidationException::withMessages(['branch_id' => __(':branch can’t take it: not mapped for withdrawals, no withdrawal rate, outside its amount range, or over today’s limits.', ['branch' => $to->code])]);
            }

            $fromBranch = $locked->branch_id;
            $fromStatus = $locked->status;
            $locked->forceFill(['branch_id' => $to->id, 'status' => PayoutStatus::Assigned->value, 'decided_by' => null])->save();

            TransactionEvent::record($locked, 'reassigned', $fromStatus, $locked->status, 'user', $actor->id, $reason, [
                'from_branch_id' => $fromBranch,
                'branch_id' => $to->id,
                'reservation' => $new,
            ]);
            AuditLog::record('payout.reassigned', $locked, ['branch_id' => $fromBranch], ['branch_id' => $to->id, 'reason' => $reason], $actor);
            $this->alerts->payoutAssigned($locked);
            $this->alerts->payoutOnShortBranch($locked, $new);

            return $this->sync($payout, $locked);
        });
    }

    private function ensureNothingPaid(Transaction $payout, string $consequence): void
    {
        $paid = self::paidSoFar($payout);

        if ($paid > 0) {
            throw ValidationException::withMessages(['status' => __('₹:paid of this payout is already paid, so :consequence.', ['paid' => number_format($paid / 100, 2), 'consequence' => $consequence])]);
        }
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
