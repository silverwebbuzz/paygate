<?php

namespace App\Domain\Transaction;

use App\Domain\PaymentSession\Models\PaymentSession;
use App\Domain\Transaction\Models\Transaction;

/**
 * A pay-in as partners see it (API responses and webhook payloads). The
 * payment URL is included only while the customer can still pay. Never
 * includes branch, account or commission details.
 */
final class PayinData
{
    /**
     * @return array<string, mixed>
     */
    public static function forPartner(Transaction $payin, ?PaymentSession $session = null): array
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
            'utr' => $payin->bank_utr_normalized ?? $payin->customer_utr_normalized,
            'reason' => $payin->status === 'rejected' ? $payin->status_reason_code : null,
            'payment_url' => $open && $session !== null ? $session->url() : null,
            'expires_at' => $payin->expires_at?->toIso8601String(),
            'submitted_at' => $payin->submitted_at?->toIso8601String(),
            'completed_at' => $payin->succeeded_at?->toIso8601String() ?? $payin->decided_at?->toIso8601String(),
            'created_at' => $payin->created_at?->toIso8601String(),
            'metadata' => $payin->metadata,
        ];
    }
}
