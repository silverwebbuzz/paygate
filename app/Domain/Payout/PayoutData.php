<?php

namespace App\Domain\Payout;

use App\Domain\Transaction\Models\Transaction;

/**
 * A payout as partners see it (API responses and webhook payloads). The
 * beneficiary is masked; branch details are never included.
 */
final class PayoutData
{
    /**
     * @return array<string, mixed>
     */
    public static function forPartner(Transaction $payout): array
    {
        $beneficiary = $payout->beneficiary;

        return [
            'id' => $payout->reference,
            'order_id' => $payout->partner_transaction_id,
            'status' => $payout->status,
            'amount' => $payout->amount,
            'fee' => PayoutReservation::fee($payout),
            'currency' => $payout->currency,
            'customer_id' => $payout->customer?->external_id,
            'beneficiary' => $beneficiary ? [
                'type' => $beneficiary->type,
                'name' => $beneficiary->account_holder_name,
                'account' => $beneficiary->type === 'bank' ? 'XXXX'.$beneficiary->account_number_last4 : null,
                'ifsc' => $beneficiary->ifsc,
            ] : null,
            'utr' => $payout->bank_utr_normalized,
            'reason' => $payout->status === 'failed' ? $payout->status_reason_code : null,
            'completed_at' => ($payout->succeeded_at ?? $payout->decided_at)?->toIso8601String(),
            'created_at' => $payout->created_at?->toIso8601String(),
            'metadata' => $payout->metadata,
        ];
    }
}
