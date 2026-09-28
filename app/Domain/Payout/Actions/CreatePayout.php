<?php

namespace App\Domain\Payout\Actions;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Customer\Models\PartnerCustomer;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Partner\Models\Partner;
use App\Domain\PartnerApi\Exceptions\ApiException;
use App\Domain\Payout\Enums\PayoutStatus;
use App\Domain\Payout\Models\PayoutBeneficiary;
use App\Domain\Payout\PayoutRouter;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A partner asks us to pay its customer (Req §7.2, G-15). If a mapped branch
 * has enough of this partner's balance for amount + fee, the money is held
 * and the payout goes into that branch's queue (`assigned`). Otherwise it is
 * refused with "balance is low" and nothing is stored.
 *
 * Same order id + same details returns the original (safe retries); with
 * different details → 409, as for pay-ins.
 */
class CreatePayout
{
    public function __construct(private PayoutRouter $router) {}

    /**
     * @param  array{order_id: string, amount: int, customer: array{id: string, name?: string|null, email?: string|null, mobile?: string|null}, beneficiary: array{type: string, name: string, account_number?: string|null, ifsc?: string|null, bank_name?: string|null, upi_id?: string|null, email?: string|null, phone?: string|null}, metadata?: array<string, mixed>|null}  $data
     * @return array{payout: Transaction, created: bool}
     */
    public function handle(Partner $partner, array $data, ?string $apiKeyId = null): array
    {
        $hash = self::requestHash($data);

        if (($existing = $this->existing($partner, $data['order_id'])) !== null) {
            return $this->replay($existing, $hash);
        }

        $this->ensureAllowed($partner, (int) $data['amount']);

        try {
            return DB::transaction(function () use ($partner, $data, $hash, $apiKeyId) {
                $customer = PartnerCustomer::remember($partner, $data['customer']);

                if ($customer->is_blocked) {
                    throw new ApiException('customer_blocked', 'This customer is blocked from withdrawals.', 403);
                }

                $reservation = $this->router->assign($partner, (int) $data['amount']);

                if ($reservation === null) {
                    throw new ApiException('insufficient_balance', 'Balance is low: no branch holds enough of your balance for this amount plus the fee. Try a smaller amount, or settle / top up first.', 422);
                }

                $payout = Transaction::create([
                    'reference' => Transaction::newPayoutReference(),
                    'direction' => 'payout',
                    'origin' => 'api',
                    'partner_id' => $partner->id,
                    'partner_transaction_id' => $data['order_id'],
                    'request_hash' => $hash,
                    'partner_customer_id' => $customer->id,
                    'branch_id' => $reservation['branch_id'],
                    'method' => $data['beneficiary']['type'] === 'upi' ? 'upi' : 'bank_transfer',
                    'amount' => $data['amount'],
                    'currency' => 'INR',
                    'status' => PayoutStatus::Assigned->value,
                    'metadata' => $data['metadata'] ?? null,
                ]);

                $beneficiary = $data['beneficiary'];
                $number = isset($beneficiary['account_number']) ? preg_replace('/\D/', '', $beneficiary['account_number']) : null;

                PayoutBeneficiary::create([
                    'transaction_id' => $payout->id,
                    'type' => $beneficiary['type'],
                    'account_holder_name' => $beneficiary['name'],
                    'account_number_encrypted' => $beneficiary['type'] === 'bank' ? $number : null,
                    'account_number_last4' => $beneficiary['type'] === 'bank' && $number !== null ? substr($number, -4) : null,
                    'ifsc' => $beneficiary['type'] === 'bank' ? strtoupper((string) ($beneficiary['ifsc'] ?? '')) : null,
                    'bank_name' => $beneficiary['bank_name'] ?? null,
                    'upi_id_encrypted' => $beneficiary['type'] === 'upi' ? strtolower(trim((string) ($beneficiary['upi_id'] ?? ''))) : null,
                    'email' => $beneficiary['email'] ?? null,
                    'phone' => $beneficiary['phone'] ?? null,
                ]);

                TransactionEvent::record($payout, 'assigned', null, $payout->status, 'partner_api', $apiKeyId, null, [
                    'branch_id' => $reservation['branch_id'],
                    'reservation' => $reservation,
                ]);
                app(AlertDispatcher::class)->payoutAssigned($payout);

                return ['payout' => $payout, 'created' => true];
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505' && ($existing = $this->existing($partner, $data['order_id'])) !== null) {
                return $this->replay($existing, $hash);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function requestHash(array $data): string
    {
        $beneficiary = $data['beneficiary'] ?? [];

        return hash('sha256', (string) json_encode([
            'order_id' => $data['order_id'],
            'amount' => $data['amount'],
            'customer_id' => $data['customer']['id'] ?? null,
            'type' => $beneficiary['type'] ?? null,
            'account' => $beneficiary['account_number'] ?? $beneficiary['upi_id'] ?? null,
            'ifsc' => $beneficiary['ifsc'] ?? null,
        ]));
    }

    private function existing(Partner $partner, string $orderId): ?Transaction
    {
        return Transaction::query()->where(['partner_id' => $partner->id, 'direction' => 'payout', 'partner_transaction_id' => $orderId])->first();
    }

    /**
     * @return array{payout: Transaction, created: bool}
     */
    private function replay(Transaction $existing, string $hash): array
    {
        if ($existing->request_hash !== $hash) {
            throw new ApiException('duplicate_order_id', 'A payout with this order_id already exists with different details.', 409, ['id' => $existing->reference]);
        }

        return ['payout' => $existing, 'created' => false];
    }

    private function ensureAllowed(Partner $partner, int $amount): void
    {
        if (! $partner->is_payout_enabled) {
            throw new ApiException('payout_disabled', 'Payouts are not enabled for this partner.', 403);
        }

        if (($partner->withdrawal_min_amount !== null && $amount < $partner->withdrawal_min_amount)
            || ($partner->withdrawal_max_amount !== null && $amount > $partner->withdrawal_max_amount)) {
            throw new ApiException('amount_out_of_range', 'The amount is outside the allowed range for this partner.', 422, [
                'min_amount' => $partner->withdrawal_min_amount,
                'max_amount' => $partner->withdrawal_max_amount,
            ]);
        }

        if ($partner->withdrawal_daily_limit !== null) {
            $used = app(UsageCounters::class)->today('partner', [$partner->id], Direction::Withdrawal)[$partner->id]['amount'] ?? 0;

            if ($used + $amount > $partner->withdrawal_daily_limit) {
                throw new ApiException('daily_limit_reached', 'This payout would exceed your daily withdrawal limit.', 422, [
                    'daily_limit' => $partner->withdrawal_daily_limit,
                    'used_today' => $used,
                ]);
            }
        }
    }
}
