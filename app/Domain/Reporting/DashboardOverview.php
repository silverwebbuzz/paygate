<?php

namespace App\Domain\Reporting;

use App\Domain\Transaction\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The top of each dashboard (client's existing layout): total / successful /
 * failed transactions for one direction, the same three per hour or day, and
 * successful volume per payment method. Admins can narrow it to some
 * partners and branches. Transactions count in the period they were created.
 * Live queries, cached for 30 seconds.
 */
class DashboardOverview
{
    /** Count and amount: all, successful, failed (rejected / expired / cancelled / failed). */
    private const FIGURES = 'COUNT(*) AS total_count, COALESCE(SUM(amount), 0) AS total_amount, '
        ."COUNT(*) FILTER (WHERE status = 'success') AS success_count, COALESCE(SUM(amount) FILTER (WHERE status = 'success'), 0) AS success_amount, "
        ."COUNT(*) FILTER (WHERE status IN ('rejected', 'expired', 'cancelled', 'failed')) AS failed_count, "
        ."COALESCE(SUM(amount) FILTER (WHERE status IN ('rejected', 'expired', 'cancelled', 'failed')), 0) AS failed_amount";

    /**
     * @param  'payin'|'payout'  $direction
     * @param  list<string>  $partnerIds  admin only; empty = all
     * @param  list<string>  $branchIds  admin only; empty = all
     * @return array<string, mixed>
     */
    public function for(Scope $scope, Period $period, string $direction, array $partnerIds = [], array $branchIds = []): array
    {
        if (! $scope->isAdmin()) {
            $partnerIds = $branchIds = [];
        }

        sort($partnerIds);
        sort($branchIds);

        return Cache::remember(
            'dashboard-overview:'.$scope->cacheKey().':'.$direction.':'.md5(implode(',', $partnerIds).'|'.implode(',', $branchIds)).':'.$period->from->timestamp.':'.$period->to->timestamp,
            30,
            fn () => $this->build($scope, $period, $direction, $partnerIds, $branchIds),
        );
    }

    /**
     * @param  list<string>  $partnerIds
     * @param  list<string>  $branchIds
     * @return array<string, mixed>
     */
    private function build(Scope $scope, Period $period, string $direction, array $partnerIds, array $branchIds): array
    {
        $zone = (string) config('app.business_timezone');
        $unit = $period->bucket();
        $base = fn (): Builder => $scope->apply(Transaction::query())
            ->where('direction', $direction)
            ->where('created_at', '>=', $period->from)
            ->where('created_at', '<', $period->to)
            ->when($partnerIds !== [], fn (Builder $query) => $query->whereIn('partner_id', $partnerIds))
            ->when($branchIds !== [], fn (Builder $query) => $query->whereIn('branch_id', $branchIds));

        $rows = $base()->toBase()
            ->selectRaw($unit === 'hour'
                ? "to_char(date_trunc('hour', created_at AT TIME ZONE ?), 'YYYY-MM-DD\"T\"HH24:MI') AS slot, ".self::FIGURES
                : "to_char(date_trunc('day', created_at AT TIME ZONE ?), 'YYYY-MM-DD\"T\"HH24:MI') AS slot, ".self::FIGURES, [$zone])
            ->groupBy('slot')
            ->get()
            ->keyBy('slot');

        $points = [];
        $cursor = $period->from->setTimezone($zone);
        $cursor = $unit === 'hour' ? $cursor->startOfHour() : $cursor->startOfDay();
        $end = $period->to->setTimezone($zone);

        while ($cursor->lessThan($end) && count($points) < 400) {
            $slot = $cursor->format('Y-m-d\TH:i');
            $points[] = ['at' => $slot] + $this->figures($rows->get($slot));
            $cursor = $unit === 'hour' ? $cursor->addHour() : $cursor->addDay();
        }

        $methods = $base()->where('status', 'success')->toBase()
            ->selectRaw("COALESCE(method, 'other') AS method, COUNT(*) AS count, SUM(amount) AS amount")
            ->groupBy('method')
            ->orderByDesc('amount')
            ->get()
            ->map(fn (object $row) => ['method' => (string) $row->method, 'count' => (int) $row->count, 'amount' => (int) $row->amount])
            ->values()
            ->all();

        return [
            'direction' => $direction,
            'summary' => $this->figures($base()->toBase()->selectRaw(self::FIGURES)->first()),
            'bucket' => $unit,
            'points' => $points,
            'methods' => $methods,
        ];
    }

    /**
     * @return array{total_count: int, total_amount: int, success_count: int, success_amount: int, failed_count: int, failed_amount: int}
     */
    private function figures(?object $row): array
    {
        $keys = ['total_count', 'total_amount', 'success_count', 'success_amount', 'failed_count', 'failed_amount'];

        return array_combine($keys, array_map(fn (string $key) => (int) ($row->{$key} ?? 0), $keys));
    }
}
