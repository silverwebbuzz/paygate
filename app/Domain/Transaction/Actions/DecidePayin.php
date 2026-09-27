<?php

namespace App\Domain\Transaction\Actions;

use App\Domain\Allocation\Actions\ReleaseAllocation;
use App\Domain\Commission\CommissionCalculator;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
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
 *
 * Every decision is on the timeline and in the audit log.
 */
class DecidePayin
{
    private const DECIDABLE = [PayinStatus::PaymentSubmitted, PayinStatus::PaymentDetected, PayinStatus::UnderReview];

    public function __construct(
        private CommissionCalculator $commissions,
        private Ledger $ledger,
        private ReleaseAllocation $allocation,
        private QueueWebhook $webhooks,
    ) {}

    public function approve(User $actor, Transaction $payin, string $bankUtr, ?string $note = null): Transaction
    {
        $utr = Transaction::normaliseUtr($bankUtr);

        if (preg_match(SubmitPayinProof::UTR_PATTERN, $utr) !== 1) {
            throw ValidationException::withMessages(['bank_utr' => __('Enter the UTR exactly as your bank statement shows it (6–22 letters or digits).')]);
        }

        try {
            return DB::transaction(function () use ($actor, $payin, $bankUtr, $utr, $note) {
                $locked = $this->lock($payin);

                $mapping = PartnerBranchMapping::query()
                    ->where(['partner_id' => $locked->partner_id, 'branch_id' => $locked->branch_id])
                    ->firstOrFail();
                $commission = $this->commissions->calculate($mapping, Direction::Deposit, $locked->amount);

                $from = $locked->status;
                $locked->forceFill([
                    ...$commission,
                    'status' => PayinStatus::Success->value,
                    'bank_utr' => mb_substr(trim($bankUtr), 0, 50),
                    'bank_utr_normalized' => $utr,
                    'received_amount' => $locked->amount,
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

                $this->allocation->confirm($locked);

                // A top-up branch's allowance shrinks by what it received.
                DB::update(
                    "UPDATE branches SET deposit_topup_balance = GREATEST(deposit_topup_balance - ?, 0), updated_at = now() WHERE id = ? AND deposit_limit_type = 'topup'",
                    [$locked->amount, $locked->branch_id],
                );

                TransactionEvent::record($locked, 'approved', $from, $locked->status, 'user', $actor->id, $note, [
                    'bank_utr' => $utr,
                    'customer_utr' => $locked->customer_utr_normalized,
                    'utr_differs' => $locked->customer_utr_normalized !== null && $locked->customer_utr_normalized !== $utr,
                    'partner_commission' => $commission['partner_commission'],
                    'branch_commission' => $commission['branch_commission'],
                ]);
                AuditLog::record('payin.approved', $locked, ['status' => $from], ['status' => $locked->status, 'bank_utr' => $utr], $actor);
                $this->webhooks->forPayin($locked, 'payin.success');

                $payin->setRawAttributes($locked->getAttributes(), true);

                return $locked;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505' && str_contains($exception->getMessage(), 'transactions_unique_payin_bank_utr')) {
                $other = Transaction::query()
                    ->where('payment_account_id', $payin->payment_account_id)
                    ->where('bank_utr_normalized', $utr)
                    ->value('reference');

                throw ValidationException::withMessages(['bank_utr' => __('This UTR was already used to approve :reference on this account.', ['reference' => $other ?? 'another pay-in'])]);
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

            $payin->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    private function lock(Transaction $payin): Transaction
    {
        /** @var Transaction $locked */
        $locked = Transaction::query()->with('partner')->whereKey($payin->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->payinStatus(), self::DECIDABLE, true)) {
            throw ValidationException::withMessages(['status' => __('This pay-in is :status and can’t be decided now.', ['status' => str_replace('_', ' ', $locked->status)])]);
        }

        return $locked;
    }
}
