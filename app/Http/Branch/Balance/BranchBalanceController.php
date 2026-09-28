<?php

namespace App\Http\Branch\Balance;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Partner\Models\Partner;
use App\Domain\Settlement\Models\Settlement;
use App\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branch Balance: where the branch stands with the platform right now, per
 * partner it collects for (positive = the platform owes the branch,
 * negative = the branch owes the platform), and its latest settlement.
 */
class BranchBalanceController extends Controller
{
    public function show(Request $request, Ledger $ledger): Response
    {
        Gate::authorize('balances.view');

        /** @var User $actor */
        $actor = $request->user();
        $positions = $ledger->branchPositions((string) $actor->branch_id);
        $partners = Partner::query()->whereIn('id', array_keys($positions))->get(['id', 'code', 'name'])->keyBy('id');

        $latest = Settlement::query()
            ->current()
            ->where(['party_type' => 'branch', 'branch_id' => $actor->branch_id])
            ->first();

        return Inertia::render('branch/balance', [
            'positions' => collect($positions)->map(fn (array $figures, string $partnerId) => [
                'partner' => ['code' => $partners->get($partnerId)->code ?? '—', 'name' => $partners->get($partnerId)->name ?? '—'],
                'balance' => $figures['balance'],
            ])->sortBy('partner.code')->values(),
            'total' => array_sum(array_column($positions, 'balance')),
            'latest' => $latest === null ? null : [
                'reference' => $latest->reference,
                'period_end' => $latest->period_end->toIso8601String(),
                'net_amount' => $latest->net_amount,
                'direction' => $latest->direction,
                'settled_amount' => $latest->settled_amount,
                'status' => $latest->status,
            ],
        ]);
    }
}
