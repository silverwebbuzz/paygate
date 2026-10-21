<?php

namespace App\Http\Shared\Settlements;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\SettlementDashboard;
use App\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settlement Dashboard (as in the client's system): Admin has an Admin,
 * a Branch and a Partner view with partner / branch filters; a branch sees
 * its own side (filter by partner); a partner sees its own totals only
 * (no branches, G-26).
 */
class SettlementDashboardController extends Controller
{
    public const SIDES = ['admin', 'branch', 'partner'];

    public function show(Request $request, SettlementDashboard $dashboard, ?string $side = null): Response
    {
        Gate::authorize('settlements.view');

        /** @var User $actor */
        $actor = $request->user();
        $side = match ($actor->type) {
            UserType::Admin => in_array($side, self::SIDES, true) ? $side : 'admin',
            UserType::Branch => 'branch',
            UserType::Partner => 'partner',
        };

        $ids = fn (string $key): array => array_values(array_filter((array) $request->query($key, []), fn ($id) => is_string($id) && Str::isUuid($id)));
        [$partnerIds, $branchIds] = match ($actor->type) {
            UserType::Admin => [$ids('partners'), $ids('branches')],
            UserType::Branch => [$ids('partners'), [(string) $actor->branch_id]],
            UserType::Partner => [[(string) $actor->partner_id], []],
        };

        $period = Period::resolve(
            is_string($request->query('range')) ? $request->query('range') : null,
            is_string($request->query('from')) ? $request->query('from') : null,
            is_string($request->query('to')) ? $request->query('to') : null,
        );

        $figures = match ($side) {
            'admin' => ['admin' => $dashboard->admin($period, $partnerIds, $branchIds)],
            'branch' => $dashboard->side('branch', $period, $partnerIds, $branchIds),
            default => $dashboard->side('partner', $period, $partnerIds, $branchIds, withPairs: $actor->isType(UserType::Admin)),
        };

        return Inertia::render('settlements/dashboard', [
            'portal' => $actor->type->value,
            'side' => $side,
            'period' => $period->toArray(),
            'filters' => [
                'partners' => $actor->isType(UserType::Partner) ? [] : $partnerIds,
                'branches' => $actor->isType(UserType::Admin) ? $branchIds : [],
            ],
            'options' => match ($actor->type) {
                UserType::Admin => [
                    'partners' => Partner::query()->orderBy('code')->get(['id', 'code', 'name']),
                    'branches' => Branch::query()->orderBy('code')->get(['id', 'code', 'name']),
                ],
                UserType::Branch => [
                    'partners' => Partner::query()
                        ->whereIn('id', PartnerBranchMapping::query()->where('branch_id', $actor->branch_id)->select('partner_id'))
                        ->orderBy('code')
                        ->get(['id', 'code', 'name']),
                    'branches' => [],
                ],
                UserType::Partner => null,
            },
            'figures' => $figures,
        ]);
    }
}
