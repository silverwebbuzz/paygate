<?php

namespace App\Http\Partner\Balance;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Payout\PartnerBalance;
use App\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Partner portal: how much can be withdrawn (same figures as GET /v1/balance).
 */
class BalanceController extends Controller
{
    public function show(Request $request, PartnerBalance $balances): Response
    {
        Gate::authorize('balances.view');

        /** @var User $actor */
        $actor = $request->user();
        $partner = Partner::query()->findOrFail($actor->partner_id);

        return Inertia::render('partner/balance', [
            'balance' => $balances->summary($partner),
            'limits' => [
                'min' => $partner->withdrawal_min_amount,
                'max' => $partner->withdrawal_max_amount,
                'daily' => $partner->withdrawal_daily_limit,
            ],
            'payouts_enabled' => $partner->is_payout_enabled,
        ]);
    }
}
