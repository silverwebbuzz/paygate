<?php

namespace App\Domain\Allocation\Actions;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;

/**
 * Gives back the capacity a pay-in reserved when its account was allocated
 * (on expiry, cancellation, rejection, or when the customer switches to a
 * method its account can't take). Reads what was reserved from the
 * `allocated` event, so it releases exactly that, once.
 *
 * Call inside the caller's transaction, with the pay-in row locked.
 */
class ReleaseAllocation
{
    public function __construct(private UsageCounters $usage) {}

    public function handle(Transaction $payin, string $reason): void
    {
        $allocated = TransactionEvent::query()
            ->where('transaction_id', $payin->id)
            ->where('event', 'allocated')
            ->latest('created_at')
            ->first();

        $released = $allocated !== null && TransactionEvent::query()
            ->where('transaction_id', $payin->id)
            ->where('event', 'allocation_released')
            ->where('created_at', '>=', $allocated->created_at)
            ->exists();

        if ($allocated === null || $released) {
            return;
        }

        $reservation = $allocated->data['reservation'] ?? null;

        if (! is_array($reservation)) {
            return;
        }

        foreach ($reservation['scopes'] as [$type, $id, $hadSession]) {
            $this->usage->release($type, $id, $reservation['date'], Direction::Deposit, (int) $reservation['amount'], (bool) $hadSession);
        }

        TransactionEvent::record($payin, 'allocation_released', $payin->status, $payin->status, 'system', null, $reason, [
            'account_id' => $allocated->data['account_id'] ?? null,
        ]);
    }
}
