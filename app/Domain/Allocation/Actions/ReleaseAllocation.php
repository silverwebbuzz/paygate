<?php

namespace App\Domain\Allocation\Actions;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;

/**
 * Gives back (or, on success, confirms) the capacity a pay-in reserved when its account was allocated
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
        $this->settle($payin, $reason, confirm: false);
    }

    /**
     * The payment succeeded: the reservation becomes confirmed usage.
     */
    public function confirm(Transaction $payin): void
    {
        $this->settle($payin, 'success', confirm: true);
    }

    /**
     * The customer submitted their proof and left the payment page: their
     * account slot (max_open_sessions) is free for the next customer, while
     * the amount stays reserved until the branch decides.
     */
    public function closeSession(Transaction $payin): void
    {
        $allocated = $this->lastAllocation($payin);
        $reservation = $allocated?->data['reservation'] ?? null;

        if (! is_array($reservation) || $this->sessionClosed($payin, $allocated)) {
            return;
        }

        $this->usage->closeSession((string) ($allocated->data['account_id'] ?? ''), (string) $reservation['date'], Direction::Deposit);

        TransactionEvent::record($payin, 'session_closed', $payin->status, $payin->status, 'system');
    }

    private function lastAllocation(Transaction $payin): ?TransactionEvent
    {
        return TransactionEvent::query()
            ->where('transaction_id', $payin->id)
            ->where('event', 'allocated')
            ->latest('created_at')
            ->first();
    }

    private function sessionClosed(Transaction $payin, TransactionEvent $allocated): bool
    {
        return TransactionEvent::query()
            ->where('transaction_id', $payin->id)
            ->where('event', 'session_closed')
            ->where('created_at', '>=', $allocated->created_at)
            ->exists();
    }

    private function settle(Transaction $payin, string $reason, bool $confirm): void
    {
        $allocated = TransactionEvent::query()
            ->where('transaction_id', $payin->id)
            ->where('event', 'allocated')
            ->latest('created_at')
            ->first();

        $released = $allocated !== null && TransactionEvent::query()
            ->where('transaction_id', $payin->id)
            ->whereIn('event', ['allocation_released', 'allocation_confirmed'])
            ->where('created_at', '>=', $allocated->created_at)
            ->exists();

        if ($allocated === null || $released) {
            return;
        }

        $reservation = $allocated->data['reservation'] ?? null;

        if (! is_array($reservation)) {
            return;
        }

        $sessionOpen = ! $this->sessionClosed($payin, $allocated);

        foreach ($reservation['scopes'] as [$type, $id, $hadSession]) {
            $hadSession = $hadSession && $sessionOpen;

            $confirm
                ? $this->usage->confirm($type, $id, $reservation['date'], Direction::Deposit, (int) $reservation['amount'], (bool) $hadSession)
                : $this->usage->release($type, $id, $reservation['date'], Direction::Deposit, (int) $reservation['amount'], (bool) $hadSession);
        }

        TransactionEvent::record($payin, $confirm ? 'allocation_confirmed' : 'allocation_released', $payin->status, $payin->status, 'system', null, $reason, [
            'account_id' => $allocated->data['account_id'] ?? null,
        ]);
    }
}
