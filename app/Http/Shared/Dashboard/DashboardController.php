<?php

namespace App\Http\Shared\Dashboard;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Reporting\DashboardMetrics;
use App\Domain\Reporting\DashboardOverview;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Scope;
use App\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The three portal dashboards, scoped to the viewer: on top the client's
 * familiar overview (total / successful / failed for pay-ins or payouts, with
 * charts; admins filter by partner and branch), then KPIs, pay-in vs payout,
 * outcome, needs attention and a table. The page refreshes `overview` and
 * `metrics` every 30 seconds.
 */
class DashboardController extends Controller
{
    /** KPIs about money owed, shown only with balances.view. */
    private const BALANCE_KPIS = ['to_receive', 'to_pay', 'settled', 'balance', 'settlement'];

    public function show(Request $request, DashboardMetrics $metrics, DashboardOverview $overview): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $isAdmin = $actor->isType(UserType::Admin);
        $direction = $request->query('direction') === 'payout' ? 'payout' : 'payin';
        // Admin filters: some partners and/or branches (empty = all).
        $ids = fn (string $key): array => $isAdmin
            ? array_values(array_filter((array) $request->query($key, []), fn ($id) => is_string($id) && Str::isUuid($id)))
            : [];
        $partnerIds = $ids('partners');
        $branchIds = $ids('branches');
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
            'filters' => ['direction' => $direction, 'partners' => $partnerIds, 'branches' => $branchIds],
            'options' => $isAdmin ? [
                'partners' => Partner::query()->orderBy('code')->get(['id', 'code', 'name']),
                'branches' => Branch::query()->orderBy('code')->get(['id', 'code', 'name']),
            ] : null,
            'overview' => fn () => $overview->for(Scope::of($actor), $period, $direction, $partnerIds, $branchIds),
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
