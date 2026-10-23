<?php

namespace App\Domain\Payout;

use App\Domain\Payout\Models\PayoutPayment;
use Illuminate\Support\Collection;

/**
 * What payout journals booked, for settlements and the settlement
 * dashboard (decided 2026-10-23): each transfer of a payout is booked on
 * the day it is recorded, the fees with the transfer that completes it.
 * So a period counts the transfers booked in it, not whole payouts.
 */
final class PayoutBookings
{
    /**
     * The transfers booked by these journals (with their payout).
     *
     * @param  list<string>  $journalIds
     * @return Collection<string, PayoutPayment> keyed by journal id
     */
    public static function forJournals(array $journalIds): Collection
    {
        if ($journalIds === []) {
            return collect();
        }

        return PayoutPayment::query()
            ->whereIn('journal_id', array_values(array_unique($journalIds)))
            ->with('transaction:id,partner_id,branch_id,partner_commission,branch_commission,platform_margin')
            ->get()
            // A plain collection: an Eloquent one's only() filters by primary key.
            ->toBase()
            ->keyBy('journal_id');
    }

    /**
     * Payouts touched (count), amount sent, and the fees booked.
     *
     * @param  Collection<string, PayoutPayment>  $parts
     * @return array{count: int, amount: int, partner_commission: int, branch_commission: int, platform_margin: int}
     */
    public static function totals(Collection $parts): array
    {
        $withFees = $parts->where('fees_booked', true);

        return [
            'count' => $parts->pluck('transaction_id')->unique()->count(),
            'amount' => (int) $parts->sum('amount'),
            'partner_commission' => (int) $withFees->sum(fn (PayoutPayment $part) => (int) $part->transaction->partner_commission),
            'branch_commission' => (int) $withFees->sum(fn (PayoutPayment $part) => (int) $part->transaction->branch_commission),
            'platform_margin' => (int) $withFees->sum(fn (PayoutPayment $part) => (int) $part->transaction->platform_margin),
        ];
    }
}
