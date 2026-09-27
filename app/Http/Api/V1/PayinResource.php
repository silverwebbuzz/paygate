<?php

namespace App\Http\Api\V1;

use App\Domain\PaymentSession\Models\PaymentSession;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\PayinData;

/**
 * A pay-in as the Partner API returns it (same shape as webhook payloads).
 */
final class PayinResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Transaction $payin, ?PaymentSession $session = null): array
    {
        return PayinData::forPartner($payin, $session);
    }
}
