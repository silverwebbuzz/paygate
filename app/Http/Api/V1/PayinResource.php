<?php

namespace App\Http\Api\V1;

use App\Domain\PaymentSession\Models\PaymentSession;
use App\Domain\Transaction\Models\Transaction;

/**
 * A pay-in as the Partner API returns it. The payment URL is included only
 * while the customer can still pay. Never includes branch or account details.
 */
final class PayinResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Transaction $payin, ?PaymentSession $session = null): array
    {
        $session ??= $payin->session;
        $open = $payin->payinStatus()->isOpen();

        return [
            'id' => $payin->reference,
            'order_id' => $payin->partner_transaction_id,
            'status' => $payin->status,
            'amount' => $payin->amount,
            'currency' => $payin->currency,
            'method' => $payin->method,
            'customer_id' => $payin->customer?->external_id,
            'utr' => $payin->customer_utr_normalized,
            'payment_url' => $open && $session !== null ? $session->url() : null,
            'expires_at' => $payin->expires_at?->toIso8601String(),
            'submitted_at' => $payin->submitted_at?->toIso8601String(),
            'completed_at' => $payin->succeeded_at?->toIso8601String() ?? $payin->decided_at?->toIso8601String(),
            'created_at' => $payin->created_at?->toIso8601String(),
            'metadata' => $payin->metadata,
        ];
    }
}
