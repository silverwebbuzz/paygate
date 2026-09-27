<?php

namespace App\Domain\Payout;

use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;

/**
 * What an open payout currently holds (balance, fee, limits), read from its
 * latest `assigned` / `reassigned` event — unless it was already released
 * or confirmed after that.
 */
final class PayoutReservation
{
    /**
     * @return array{branch_id: string, mapping_id: string, ledger_account_id: string, needed: int, fee: int, date: string, amount: int}|null
     */
    public static function current(Transaction $payout): ?array
    {
        $latest = TransactionEvent::query()
            ->where('transaction_id', $payout->id)
            ->whereIn('event', ['assigned', 'reassigned', 'reservation_released', 'reservation_confirmed'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->first();

        $reservation = $latest?->data['reservation'] ?? null;

        if (! in_array($latest?->event, ['assigned', 'reassigned'], true) || ! is_array($reservation)) {
            return null;
        }

        return [
            'branch_id' => (string) $reservation['branch_id'],
            'mapping_id' => (string) $reservation['mapping_id'],
            'ledger_account_id' => (string) $reservation['ledger_account_id'],
            'needed' => (int) $reservation['needed'],
            'fee' => (int) $reservation['fee'],
            'date' => (string) $reservation['date'],
            'amount' => (int) $reservation['amount'],
        ];
    }

    /**
     * The fee the partner pays: final once paid, otherwise what was reserved.
     */
    public static function fee(Transaction $payout): ?int
    {
        if ($payout->partner_commission !== null) {
            return $payout->partner_commission;
        }

        $reservation = self::current($payout);

        return $reservation === null ? null : (int) $reservation['fee'];
    }
}
