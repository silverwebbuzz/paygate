<?php

namespace App\Domain\Transaction\Actions;

use App\Domain\Allocation\Actions\ReleaseAllocation;
use App\Domain\Branch\Models\Branch;
use App\Domain\Branch\Models\BranchLimitTopup;
use App\Domain\Commission\CommissionCalculator;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Reconciliation\StatementMatcher;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Domain\Transaction\Utr;
use App\Domain\Webhook\Actions\QueueWebhook;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The branch's (or Admin's) decision on a pay-in the customer says they paid
 * (Req G-23, Database.md §4 "Branch approves"):
 *
 * - approve: the money is in the bank. With the bank UTR it becomes SUCCESS,
 *   and in the same database transaction the commission is snapshotted, the
 *   ledger journal is booked, the reservation becomes confirmed usage, a
 *   top-up branch's allowance is reduced, and the webhook is queued.
 * - hold: needs a closer look (under_review); decided later.
 * - decline: not paid / invalid; the reservation is released.
 * - approveLate (Admin, from the unsettled queue): a deposit that expired or
 *   was declined, whose money arrived anyway.
 *
 * Bank statement lines are matched after each decision (StatementMatcher).
 * Every decision is on the timeline and in the audit log.
 */
class DecidePayin
{
    private const DECIDABLE = [PayinStatus::PaymentSubmitted, PayinStatus::PaymentDetected, PayinStatus::UnderReview];

    private const LATE_APPROVABLE = [PayinStatus::Rejected, PayinStatus::Expired];

    /** status_reason_code of a deposit the branch says isn't in its bank. */
    public const NOT_IN_BANK = 'not_in_our_bank';


    public function __construct(
        private CommissionCalculator $commissions,
        private Ledger $ledger,
        private ReleaseAllocation $allocation,
        private QueueWebhook $webhooks,
        private StatementMatcher $matcher,
        private AlertDispatcher $alerts,
    ) {}

    public function approve(User $actor, Transaction $payin, string $bankUtr, ?string $note = null, ?int $received = null): Transaction
    {
        return $this->succeed($actor, $payin, $bankUtr, $note, late: false, received: $received);
    }

    /**
     * Approved by itself (AutoApproveDeposit): the bank line `$actor` entered
     * or imported shows this deposit's UTR and exact amount. Same bookings as
     * approve; the timeline and audit say it was automatic.
     */
    public function autoApprove(User $actor, Transaction $payin, string $bankUtr, string $note): Transaction
    {
        return $this->succeed($actor, $payin, $bankUtr, $note, late: false, received: null, auto: true);
    }

    /**
     * A bank credit arrived for a deposit that already expired or was
     * declined (decided 2026-09-28, G-25): Admin approves it late from the
     * unsettled queue. Same bookings as approve; the partner receives
     * payin.success again with `late: true`.
     */
    public function approveLate(User $actor, Transaction $payin, string $bankUtr, ?string $note = null, ?int $received = null): Transaction
    {
        return $this->succeed($actor, $payin, $bankUtr, $note, late: true, received: $received);
    }

    private function succeed(User $actor, Transaction $payin, string $bankUtr, ?string $note, bool $late, ?int $received, bool $auto = false): Transaction
    {
        $utr = Transaction::normaliseUtr($bankUtr);

        if (preg_match(SubmitPayinProof::UTR_PATTERN, $utr) !== 1) {
            throw ValidationException::withMessages(['bank_utr' => __('Enter the UTR exactly as your bank statement shows it (6–22 letters or digits).')]);
        }

        if ($received !== null && $received <= 0) {
            throw ValidationException::withMessages(['received_amount' => __('Enter the amount that reached your bank account.')]);
        }

        try {
            return DB::transaction(function () use ($actor, $payin, $bankUtr, $utr, $note, $late, $received, $auto) {
                $locked = $this->lock($payin, $late ? self::LATE_APPROVABLE : self::DECIDABLE);
                Utr::ensureFree($utr, $locked->id);
                $order = $locked->orderAmount();
                $received ??= $order;
                $differs = $received !== $order;

                if ($differs && trim((string) $note) === '') {
                    throw ValidationException::withMessages(['note' => __('Say why the amount received differs from the order amount.')]);
                }

                // The bank's UTR isn't the one the customer gave: say why; Admin is told.
                $utrDiffers = ! $auto && $locked->customer_utr_normalized !== null && $locked->customer_utr_normalized !== $utr;

                if ($utrDiffers && trim((string) $note) === '') {
                    throw ValidationException::withMessages(['note' => __('The bank UTR is not the customer’s UTR (:utr). Say why you approve it.', ['utr' => $locked->customer_utr_normalized])]);
                }

                $mapping = PartnerBranchMapping::query()
                    ->where(['partner_id' => $locked->partner_id, 'branch_id' => $locked->branch_id])
                    ->firstOrFail();
                $commission = $this->commissions->calculate($mapping, Direction::Deposit, $received);

                $from = $locked->status;
                $locked->forceFill([
                    ...$commission,
                    'status' => PayinStatus::Success->value,
                    // Marks a late approval (PayinData `late`); a decline reason no longer applies.
                    'status_reason_code' => $late ? 'late_payment' : null,
                    'bank_utr' => mb_substr(trim($bankUtr), 0, 50),
                    'bank_utr_normalized' => $utr,
                    'amount' => $received,
                    'order_amount' => $differs ? $order : null,
                    'received_amount' => $received,
                    'status_note' => $note,
                    'decided_at' => now(),
                    'decided_by' => $actor->id,
                    'succeeded_at' => now(),
                ])->save();

                // Branch owes the platform the amount less its commission;
                // the platform owes the partner the amount less the partner's.
                $this->ledger->post('payin_success', [
                    $this->ledger->account(Ledger::PARTNER_POSITION, $locked->partner_id, $locked->branch_id) => $locked->amount - $commission['partner_commission'],
                    $this->ledger->account(Ledger::BRANCH_POSITION, $locked->partner_id, $locked->branch_id) => -($locked->amount - $commission['branch_commission']),
                    $this->ledger->account(Ledger::PLATFORM_MARGIN) => $commission['platform_margin'],
                ], $locked->id, "Pay-in {$locked->reference}", $actor->id);

                // The reservation becomes confirmed usage; a late payment's
                // reservation was already released, so today's usage grows.
                $late ? $this->allocation->confirmLate($locked) : $this->allocation->confirm($locked);

                // A top-up branch's allowance shrinks by what it received.
                $this->useTopupAllowance($actor, $locked);

                TransactionEvent::record($locked, $late ? 'approved_late' : 'approved', $from, $locked->status, 'user', $actor->id, $note, [
                    'bank_utr' => $utr,
                    'customer_utr' => $locked->customer_utr_normalized,
                    'utr_differs' => $locked->customer_utr_normalized !== null && $locked->customer_utr_normalized !== $utr,
                    'partner_commission' => $commission['partner_commission'],
                    'branch_commission' => $commission['branch_commission'],
                    ...($differs ? ['order_amount' => $order, 'received_amount' => $received] : []),
                    ...($auto ? ['auto' => true] : []),
                ]);
                AuditLog::record(
                    $late ? 'payin.approved_late' : 'payin.approved',
                    $locked,
                    ['status' => $from, ...($differs ? ['amount' => $order] : [])],
                    ['status' => $locked->status, 'bank_utr' => $utr, ...($differs ? ['amount' => $received] : []), ...($auto ? ['auto' => true] : [])],
                    $actor,
                );
                $this->webhooks->forPayin($locked, 'payin.success');
                $this->matcher->transactionApproved($locked, $actor);

                if ($differs) {
                    $this->alerts->payinAmountDiffers($locked, $actor);
                }

                if ($utrDiffers) {
                    $this->alerts->approvedWithOtherUtr($locked, $actor);
                }

                $payin->setRawAttributes($locked->getAttributes(), true);

                return $locked;
            });
        } catch (QueryException $exception) {
            if (Utr::isDuplicateError($exception)) {
                Utr::ensureFree($utr, $payin->id);
            }

            throw $exception;
        }
    }

    public function hold(User $actor, Transaction $payin, string $reason): Transaction
    {
        return DB::transaction(function () use ($actor, $payin, $reason) {
            $locked = $this->lock($payin);

            if ($locked->payinStatus() === PayinStatus::UnderReview) {
                throw ValidationException::withMessages(['reason' => __('This pay-in is already on hold.')]);
            }

            $from = $locked->status;
            $locked->forceFill(['status' => PayinStatus::UnderReview->value, 'status_note' => $reason])->save();

            TransactionEvent::record($locked, 'held', $from, $locked->status, 'user', $actor->id, $reason);
            AuditLog::record('payin.held', $locked, ['status' => $from], ['status' => $locked->status, 'reason' => $reason], $actor);

            $payin->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * The branch can't find the money in its account (the customer paid
     * another branch's account): on hold for Admin to move it to the right
     * account (MovePayin). Admin is alerted.
     */
    public function notInBank(User $actor, Transaction $payin, ?string $note = null): Transaction
    {
        return DB::transaction(function () use ($actor, $payin, $note) {
            $locked = $this->lock($payin);

            if ($locked->status_reason_code === self::NOT_IN_BANK) {
                throw ValidationException::withMessages(['note' => __('Admin has already been asked to move this deposit.')]);
            }

            $from = $locked->status;
            $reason = trim((string) $note) !== '' ? trim((string) $note) : __('The money isn’t in our bank account.');
            $locked->forceFill([
                'status' => PayinStatus::UnderReview->value,
                'status_reason_code' => self::NOT_IN_BANK,
                'status_note' => $reason,
            ])->save();

            TransactionEvent::record($locked, 'not_in_bank', $from, $locked->status, 'user', $actor->id, $reason);
            AuditLog::record('payin.not_in_bank', $locked, ['status' => $from], ['status' => $locked->status, 'reason' => $reason], $actor);
            $this->alerts->depositNotInBank($locked, $actor);

            $payin->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    public function decline(User $actor, Transaction $payin, string $reasonCode, ?string $note = null): Transaction
    {
        return DB::transaction(function () use ($actor, $payin, $reasonCode, $note) {
            $locked = $this->lock($payin);

            $this->allocation->handle($locked, 'rejected');

            $from = $locked->status;
            $locked->forceFill([
                'status' => PayinStatus::Rejected->value,
                'status_reason_code' => $reasonCode,
                'status_note' => $note,
                'decided_at' => now(),
                'decided_by' => $actor->id,
            ])->save();
            $locked->session()->update(['status' => 'expired']);

            TransactionEvent::record($locked, 'declined', $from, $locked->status, 'user', $actor->id, $note, ['reason_code' => $reasonCode]);
            AuditLog::record('payin.declined', $locked, ['status' => $from], ['status' => $locked->status, 'reason_code' => $reasonCode, 'note' => $note], $actor);
            $this->webhooks->forPayin($locked, 'payin.rejected');
            $this->matcher->transactionDeclined($locked);

            $payin->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * @param  list<PayinStatus>  $allowed
     */
    private function lock(Transaction $payin, array $allowed = self::DECIDABLE): Transaction
    {
        /** @var Transaction $locked */
        $locked = Transaction::query()->with('partner')->whereKey($payin->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->payinStatus(), $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('This pay-in is :status and can’t be decided now.', ['status' => str_replace('_', ' ', $locked->status)])]);
        }

        return $locked;
    }

    /**
     * Takes an approved deposit off a top-up branch's allowance (never below
     * zero) and writes it to the allowance history, so the Top-ups tab shows
     * what each approval used up next to the Admin top-ups.
     */
    private function useTopupAllowance(User $actor, Transaction $payin): void
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->whereKey($payin->branch_id)->lockForUpdate()->first();

        if ($branch === null || ! $branch->usesTopup()) {
            return;
        }

        $after = max($branch->deposit_topup_balance - $payin->amount, 0);
        $used = $branch->deposit_topup_balance - $after;

        if ($used === 0) {
            return;
        }

        $branch->update(['deposit_topup_balance' => $after]);

        BranchLimitTopup::create([
            'branch_id' => $branch->id,
            'transaction_id' => $payin->id,
            'amount' => -$used,
            'balance_after' => $after,
            'reason' => __('Deposit :reference approved', ['reference' => $payin->reference]),
            'created_by' => $actor->id,
        ]);
    }
}
