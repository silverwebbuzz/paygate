<?php

namespace App\Http\Shared\Dashboard;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Reporting\DashboardMetrics;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Scope;
use App\Http\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The three portal dashboards (design: range buttons, KPIs, pay-in vs
 * payout chart, outcome, needs attention, a table), scoped to the viewer.
 * The page refreshes `metrics` every 30 seconds.
 */
class DashboardController extends Controller
{
    /** KPIs about money owed, shown only with balances.view. */
    private const BALANCE_KPIS = ['to_receive', 'to_pay', 'settled', 'balance', 'settlement'];

    public function show(Request $request, DashboardMetrics $metrics): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $period = Period::resolve(
            is_string($request->query('range')) ? $request->query('range') : null,
            is_string($request->query('from')) ? $request->query('from') : null,
            is_string($request->query('to')) ? $request->query('to') : null,
        );

        return Inertia::render($actor->type->value.'/dashboard', [
            'portal' => $actor->type->value,
            'period' => $period->toArray(),
            'organisation' => match (true) {
                $actor->partner_id !== null => Partner::query()->whereKey($actor->partner_id)->value('name'),
                $actor->branch_id !== null => Branch::query()->whereKey($actor->branch_id)->get(['code', 'name'])->map(fn (Branch $branch) => "{$branch->code} · {$branch->name}")->first(),
                default => null,
            },
            'metrics' => function () use ($metrics, $actor, $period) {
                $figures = $metrics->for(Scope::of($actor), $period);

                if (! $actor->can('balances.view')) {
                    $figures['kpis'] = array_values(array_filter($figures['kpis'], fn (array $kpi) => ! in_array($kpi['key'], self::BALANCE_KPIS, true)));
                }

                return $figures;
            },
        ]);
    }
}
