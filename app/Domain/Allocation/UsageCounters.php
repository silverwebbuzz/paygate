<?php

namespace App\Domain\Allocation;

use App\Domain\Commission\Enums\Direction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily usage per account / branch / partner / pair (business day in India
 * time): what is confirmed plus what is reserved by open payment sessions
 * and assigned payouts.
 *
 * Reservations use conditional UPDATEs, so a limit can never be exceeded
 * even when many customers are allocated at the same moment (Database.md §4).
 */
class UsageCounters
{
    public static function businessDate(): string
    {
        return CarbonImmutable::now(config('app.business_timezone'))->toDateString();
    }

    /**
     * @param  array<mixed>  $scopeIds
     * @return array<string, array{amount: int, count: int}> scope id => usage today
     */
    public function today(string $scopeType, array $scopeIds, Direction $direction): array
    {
        if ($scopeIds === []) {
            return [];
        }

        return DB::table('usage_counters')
            ->where(['scope_type' => $scopeType, 'business_date' => self::businessDate(), 'direction' => $direction->value])
            ->whereIn('scope_id', $scopeIds)
            ->get(['scope_id', 'reserved_amount', 'confirmed_amount', 'reserved_count', 'confirmed_count'])
            ->mapWithKeys(fn (object $row) => [(string) $row->scope_id => [
                'amount' => (int) $row->reserved_amount + (int) $row->confirmed_amount,
                'count' => (int) $row->reserved_count + (int) $row->confirmed_count,
            ]])
            ->all();
    }

    /**
     * Reserves `$amount` (and one payment) on a scope for the business day,
     * only if it stays within the limits given (null = no limit). Returns
     * false, changing nothing, when a limit would be exceeded.
     */
    public function reserve(string $scopeType, string $scopeId, string $date, Direction $direction, int $amount, ?int $amountLimit = null, ?int $countLimit = null, ?int $sessionLimit = null): bool
    {
        $key = ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'business_date' => $date, 'direction' => $direction->value];

        DB::table('usage_counters')->insertOrIgnore($key);

        $sessions = $sessionLimit === null ? 0 : 1;

        return DB::table('usage_counters')
            ->where($key)
            ->when($amountLimit !== null, fn ($query) => $query->whereRaw('reserved_amount + confirmed_amount + ? <= ?', [$amount, $amountLimit]))
            ->when($countLimit !== null, fn ($query) => $query->whereRaw('reserved_count + confirmed_count + 1 <= ?', [$countLimit]))
            ->when($sessionLimit !== null, fn ($query) => $query->whereRaw('open_sessions + 1 <= ?', [$sessionLimit]))
            ->incrementEach(
                ['reserved_amount' => $amount, 'reserved_count' => 1, 'open_sessions' => $sessions],
                ['updated_at' => now()],
            ) === 1;
    }

    /**
     * Gives back a reservation (session expired, cancelled, rejected, or the
     * customer switched to another account). Never goes below zero.
     */
    public function release(string $scopeType, string $scopeId, string $date, Direction $direction, int $amount, bool $hadSession = false): void
    {
        DB::update(
            'UPDATE usage_counters
             SET reserved_amount = GREATEST(reserved_amount - ?, 0),
                 reserved_count = GREATEST(reserved_count - 1, 0),
                 open_sessions = GREATEST(open_sessions - ?, 0),
                 updated_at = now()
             WHERE scope_type = ? AND scope_id = ? AND business_date = ? AND direction = ?',
            [$amount, $hadSession ? 1 : 0, $scopeType, $scopeId, $date, $direction->value],
        );
    }

    /**
     * Turns a reservation into confirmed usage (the payment succeeded).
     */
    public function confirm(string $scopeType, string $scopeId, string $date, Direction $direction, int $amount, bool $hadSession = false): void
    {
        DB::update(
            'UPDATE usage_counters
             SET reserved_amount = GREATEST(reserved_amount - ?, 0),
                 reserved_count = GREATEST(reserved_count - 1, 0),
                 confirmed_amount = confirmed_amount + ?,
                 confirmed_count = confirmed_count + 1,
                 open_sessions = GREATEST(open_sessions - ?, 0),
                 updated_at = now()
             WHERE scope_type = ? AND scope_id = ? AND business_date = ? AND direction = ?',
            [$amount, $amount, $hadSession ? 1 : 0, $scopeType, $scopeId, $date, $direction->value],
        );
    }

    /**
     * Adds confirmed usage without a reservation: a late payment approved
     * after its reservation was released. Limits aren't checked; the money
     * has already arrived.
     */
    public function addConfirmed(string $scopeType, string $scopeId, string $date, Direction $direction, int $amount): void
    {
        $key = ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'business_date' => $date, 'direction' => $direction->value];

        DB::table('usage_counters')->insertOrIgnore($key);
        DB::table('usage_counters')->where($key)->incrementEach(['confirmed_amount' => $amount, 'confirmed_count' => 1], ['updated_at' => now()]);
    }

    /**
     * Frees one "customer on the payment page" slot of an account (the
     * customer submitted their proof; the amount stays reserved).
     */
    public function closeSession(string $scopeId, string $date, Direction $direction): void
    {
        DB::update(
            "UPDATE usage_counters SET open_sessions = GREATEST(open_sessions - 1, 0), updated_at = now()
             WHERE scope_type = 'account' AND scope_id = ? AND business_date = ? AND direction = ?",
            [$scopeId, $date, $direction->value],
        );
    }
}
