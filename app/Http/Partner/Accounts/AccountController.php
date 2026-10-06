<?php

namespace App\Http\Partner\Accounts;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('accounts.view');

        /** @var User $actor */
        $actor = $request->user();
        $partner = Partner::query()->findOrFail($actor->partner_id);

        $accounts = PaymentAccount::query()
            ->whereIn('branch_id', $partner->branches()->select('branches.id'))
            ->orderBy('account_holder_name')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentAccount $account) => [
                'id' => $account->id,
                'holder' => $account->account_holder_name,
                'is_bank_enabled' => $account->is_bank_enabled,
                'bank_name' => $account->bank_name,
                'ifsc' => $account->ifsc,
                'account_number' => $account->maskedAccountNumber(),
                'is_upi_enabled' => $account->is_upi_enabled,
                'upi_id' => $account->maskedUpiId(),
                'upi_display_name' => $account->upi_display_name,
                'is_qr_enabled' => $account->is_qr_enabled,
                'min_amount' => $account->min_amount,
                'max_amount' => $account->max_amount,
                'daily_amount_limit' => $account->daily_amount_limit,
                'daily_count_limit' => $account->daily_count_limit,
                'status' => $account->status->value,
            ]);

        return Inertia::render('partner/accounts', ['accounts' => $accounts]);
    }
}
