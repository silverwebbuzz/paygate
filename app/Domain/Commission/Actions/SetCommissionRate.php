<?php

namespace App\Domain\Commission\Actions;

use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\Models\CommissionRate;
use App\Domain\Commission\RatePercent;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Puts a new rate in force from now. The current rate (if any) is closed at
 * the same moment, so periods never overlap (commission_rates_no_overlap)
 * and past transactions keep the rate they were calculated with.
 *
 * Rates are negotiated offline and entered by Admin (Req G-59). A rate that
 * gives the platform a negative margin is allowed but flagged in the audit
 * log (Req G-07); pass the offending branches in `$negativeMargins`.
 */
class SetCommissionRate
{
    /**
     * @param  string  $subjectType  partner / branch / mapping
     * @param  list<array<string, string>>  $negativeMargins
     * @return CommissionRate|null the new rate, or null when it didn't change
     */
    public function handle(User $actor, string $subjectType, Model $subject, string $side, Direction $direction, string $rate, array $negativeMargins = []): ?CommissionRate
    {
        $rate = RatePercent::normalize($rate);

        return DB::transaction(function () use ($actor, $subjectType, $subject, $side, $direction, $rate, $negativeMargins) {
            $current = CommissionRate::query()
                ->for($subjectType, (string) $subject->getKey(), $side, $direction)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($current !== null && RatePercent::compare($current->rate_percent, $rate) === 0) {
                return null;
            }

            $now = now();

            if ($current !== null) {
                // A rate set moments ago (same timestamp) is replaced rather than closed,
                // since a zero-length period isn't allowed.
                $current->effective_from->greaterThanOrEqualTo($now)
                    ? $current->delete()
                    : $current->update(['effective_to' => $now]);
            }

            $new = CommissionRate::create([
                'subject_type' => $subjectType,
                'subject_id' => $subject->getKey(),
                'side' => $side,
                'direction' => $direction,
                'fee_type' => 'percent',
                'rate_percent' => $rate,
                'effective_from' => $now,
                'created_by' => $actor->id,
            ]);

            AuditLog::record('commission_rate.set', $subject, [
                'rate_percent' => $current?->rate_percent,
            ], array_filter([
                'side' => $side,
                'direction' => $direction->value,
                'rate_percent' => $rate,
                'effective_from' => $now->toIso8601String(),
                'negative_margin_pairs' => $negativeMargins ?: null,
            ]), $actor);

            return $new;
        });
    }

    /**
     * Ends the rate in force now without a replacement (e.g. removing a pair
     * override so the partner's or branch's own rate applies again).
     */
    public function clear(User $actor, string $subjectType, Model $subject, string $side, Direction $direction): bool
    {
        return DB::transaction(function () use ($actor, $subjectType, $subject, $side, $direction) {
            $current = CommissionRate::query()
                ->for($subjectType, (string) $subject->getKey(), $side, $direction)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return false;
            }

            $current->update(['effective_to' => now()]);

            AuditLog::record('commission_rate.cleared', $subject, ['rate_percent' => $current->rate_percent], [
                'side' => $side,
                'direction' => $direction->value,
            ], $actor);

            return true;
        });
    }
}
