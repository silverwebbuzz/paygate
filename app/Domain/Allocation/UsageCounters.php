<?php

namespace App\Domain\Allocation;

use App\Domain\Commission\Enums\Direction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads today's usage (business day in India time) from usage_counters:
 * what is confirmed plus what is reserved by open payment sessions and
 * assigned payouts. The counters are written by allocation (Phase 6+).
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
}
