<?php

namespace App\Http\Partner\Profile;

use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\RateBook;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Http\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Partner portal: the partner's own business profile as Admin configured it
 * (read-only; changes go through PayGate). Never shows branch details.
 */
class BusinessProfileController extends Controller
{
    public function show(Request $request, RateBook $rates): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $partner = Partner::query()->findOrFail($actor->partner_id);

        return Inertia::render('partner/profile', [
            'partner' => [
                ...$partner->only([
                    'name', 'code', 'email', 'description', 'website_url', 'api_version',
                    'is_payin_enabled', 'is_payout_enabled', 'allow_qr', 'allow_upi', 'allow_bank_transfer', 'is_h2h_enabled',
                    'manual_payment_type', 'session_ttl_minutes', 'is_auto_withdrawal', 'is_partial_withdrawal',
                    'deposit_min_amount', 'deposit_max_amount', 'deposit_daily_limit',
                    'withdrawal_min_amount', 'withdrawal_max_amount', 'withdrawal_daily_limit',
                ]),
                'status' => $partner->status->value,
                'verified_at' => $partner->verified_at?->toIso8601String(),
            ],
            // What the partner pays us; shown only to roles that see finance.
            'rates' => $actor->can('balances.view') ? [
                'deposit' => $rates->partnerRate($partner, Direction::Deposit),
                'withdrawal' => $rates->partnerRate($partner, Direction::Withdrawal),
            ] : null,
        ]);
    }
}
