<?php

namespace App\Domain\Transaction\Actions;

use App\Domain\Customer\Models\PartnerCustomer;
use App\Domain\Partner\Models\Partner;
use App\Domain\PartnerApi\Exceptions\ApiException;
use App\Domain\PaymentSession\Models\PaymentSession;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a pay-in and its payment page (Database.md §4 "Pay-in create").
 *
 * Duplicates: the partner's order id is unique per partner. Sending the same
 * order again with the same details returns the original pay-in (safe to
 * retry after a timeout); with different details it is refused (409,
 * Req G-42).
 *
 * The account is not chosen here: that happens when the customer picks a
 * payment method on the page (AllocateAccount).
 */
class CreatePayin
{
    /**
     * @param  array{order_id: string, amount: int, customer: array{id: string, name?: string|null, email?: string|null, mobile?: string|null, username?: string|null}, return_url?: string|null, metadata?: array<string, mixed>|null}  $data
     * @return array{payin: Transaction, session: PaymentSession, created: bool}
     */
    public function handle(Partner $partner, array $data, ?string $apiKeyId = null): array
    {
        $hash = self::requestHash($data);

        $existing = $this->existing($partner, $data['order_id']);

        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }

        $this->ensureAllowed($partner, $data);

        try {
            return DB::transaction(function () use ($partner, $data, $hash, $apiKeyId) {
                $customer = PartnerCustomer::remember($partner, $data['customer']);

                if ($customer->is_blocked) {
                    throw new ApiException('customer_blocked', 'This customer is blocked from paying in.', 403);
                }

                $payin = Transaction::create([
                    'reference' => Transaction::newPayinReference(),
                    'direction' => 'payin',
                    'origin' => 'api',
                    'partner_id' => $partner->id,
                    'partner_transaction_id' => $data['order_id'],
                    'request_hash' => $hash,
                    'partner_customer_id' => $customer->id,
                    'amount' => $data['amount'],
                    'currency' => 'INR',
                    'status' => PayinStatus::Created->value,
                    'return_url' => $data['return_url'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                    'expires_at' => now()->addMinutes($partner->session_ttl_minutes),
                ]);

                $session = new PaymentSession(['transaction_id' => $payin->id, 'status' => 'issued']);
                $session->id = $session->newUniqueId();
                $session->token_hash = PaymentSession::hashToken(PaymentSession::tokenFor($session->id));
                $session->save();

                TransactionEvent::record($payin, 'created', null, $payin->status, 'partner_api', $apiKeyId, null, [
                    'amount' => $payin->amount,
                    'expires_at' => $payin->expires_at?->toIso8601String(),
                ]);

                return ['payin' => $payin, 'session' => $session, 'created' => true];
            });
        } catch (QueryException $exception) {
            // Two identical requests at the same moment: the second one loses
            // the unique index race and replays the first.
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
        return hash('sha256', (string) json_encode([
            'order_id' => $data['order_id'],
            'amount' => $data['amount'],
            'customer_id' => $data['customer']['id'] ?? null,
            'return_url' => $data['return_url'] ?? null,
        ]));
    }

    private function existing(Partner $partner, string $orderId): ?Transaction
    {
        return Transaction::query()->where([
            'partner_id' => $partner->id,
            'direction' => 'payin',
            'partner_transaction_id' => $orderId,
        ])->first();
    }

    /**
     * @return array{payin: Transaction, session: PaymentSession, created: bool}
     */
    private function replay(Transaction $existing, string $hash): array
    {
        if ($existing->request_hash !== $hash) {
            throw new ApiException('duplicate_order_id', 'A pay-in with this order_id already exists with different details.', 409, [
                'id' => $existing->reference,
            ]);
        }

        return ['payin' => $existing, 'session' => $existing->session()->firstOrFail(), 'created' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ensureAllowed(Partner $partner, array $data): void
    {
        if (! $partner->is_payin_enabled) {
            throw new ApiException('payin_disabled', 'Pay-ins are not enabled for this partner.', 403);
        }

        if (! $partner->allow_upi && ! $partner->allow_qr && ! $partner->allow_bank_transfer) {
            throw new ApiException('payin_disabled', 'No payment method is enabled for this partner.', 403);
        }

        $amount = (int) $data['amount'];

        if (($partner->deposit_min_amount !== null && $amount < $partner->deposit_min_amount)
            || ($partner->deposit_max_amount !== null && $amount > $partner->deposit_max_amount)) {
            throw new ApiException('amount_out_of_range', 'The amount is outside the allowed range for this partner.', 422, [
                'min_amount' => $partner->deposit_min_amount,
                'max_amount' => $partner->deposit_max_amount,
            ]);
        }

        $returnUrl = $data['return_url'] ?? null;

        if (is_string($returnUrl) && ! self::returnUrlAllowed($partner, $returnUrl)) {
            throw new ApiException('return_url_not_allowed', 'return_url must be on your registered website or return URL domain.', 422);
        }
    }

    /**
     * A per-request return URL must be on the partner's own domain (the
     * website or saved return URL host, or a subdomain of it) — decided
     * 2026-09-27 (G-41) to avoid open redirects.
     */
    public static function returnUrlAllowed(Partner $partner, string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach ([$partner->website_url, $partner->return_url] as $registered) {
            $allowed = strtolower((string) parse_url((string) $registered, PHP_URL_HOST));
            $allowed = preg_replace('/^www\./', '', $allowed) ?? $allowed;

            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }
}
