<?php

namespace App\Http\Partner\Accounts;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request, UsageCounters $usage): Response
    {
        Gate::authorize('accounts.view');

        /** @var User $actor */
        $actor = $request->user();
        $partner = Partner::query()->findOrFail($actor->partner_id);

        $accounts = PaymentAccount::query()
            ->whereIn('branch_id', $partner->branches()->select('branches.id'))
            ->where('status', AccountStatus::Active)
            ->orderBy('bank_name')
            ->orderBy('account_holder_name')
            ->orderBy('id')
            ->get();

        $used = $usage->today('account', $accounts->pluck('id')->all(), Direction::Deposit);

        return Inertia::render('partner/accounts', [
            'accounts' => $accounts->map(fn (PaymentAccount $account) => [
                'id' => $account->id,
                'label' => $account->label,
                'holder' => $account->account_holder_name,
                'is_bank_enabled' => $account->is_bank_enabled,
                'bank_name' => $account->bank_name,
                'ifsc' => $account->ifsc,
                'account_number' => is_string($account->account_number_encrypted) && $account->account_number_encrypted !== ''
                    ? $account->account_number_encrypted
                    : $account->maskedAccountNumber(),
                'is_upi_enabled' => $account->is_upi_enabled,
                'upi_id' => is_string($account->upi_id_encrypted) && $account->upi_id_encrypted !== ''
                    ? $account->upi_id_encrypted
                    : $account->maskedUpiId(),
                'upi_display_name' => $account->upi_display_name,
                'upi_code' => $account->upi_code,
                'is_qr_enabled' => $account->is_qr_enabled,
                'min_amount' => $account->min_amount,
                'max_amount' => $account->max_amount,
                'daily_amount_limit' => $account->daily_amount_limit,
                'daily_count_limit' => $account->daily_count_limit,
                'max_open_sessions' => $account->max_open_sessions,
                'used_today' => $used[$account->id] ?? ['amount' => 0, 'count' => 0],
                'status' => $account->status->value,
                'rejected_reason' => $account->rejected_reason,
                'verified_at' => $account->verified_at?->toIso8601String(),
                'created_at' => $account->created_at?->toIso8601String(),
                'can' => [
                    'update' => false,
                    'verify' => false,
                    'switch_to' => [],
                ],
            ])->values(),
        ]);
    }
}
