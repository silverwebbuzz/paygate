<?php

namespace App\Http\Branch\Accounts;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Actions\ChangeAccountStatus;
use App\Domain\PaymentAccount\Actions\SavePaymentAccount;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Http\Controller;
use App\Http\Shared\Accounts\AccountPresenter;
use App\Http\Shared\Accounts\PaymentAccountRequest;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branch portal: the branch's own bank & UPI accounts. New and changed
 * accounts go to PayGate for verification; the branch then activates them.
 */
class AccountController extends Controller
{
    public function index(Request $request, AccountPresenter $presenter): Response
    {
        Gate::authorize('accounts.view');

        $actor = $this->actor($request);
        $branch = $this->branch($actor);

        return Inertia::render('branch/accounts', [
            'branch' => [
                'code' => $branch->code,
                'name' => $branch->name,
                'deposit_limit_type' => $branch->deposit_limit_type,
                'deposit_topup_balance' => $branch->deposit_topup_balance,
                'deposit_min_amount' => Money::toRupees($branch->deposit_min_amount),
                'deposit_max_amount' => Money::toRupees($branch->deposit_max_amount),
            ],
            'accounts' => $presenter->rows($branch->paymentAccounts()->with('branch')->orderBy('label')->get(), $actor),
            'can' => ['create' => $actor->can('accounts.create')],
        ]);
    }

    public function store(PaymentAccountRequest $request, SavePaymentAccount $save): RedirectResponse
    {
        $account = $save->handle($request->actor(), $this->branch($request->actor()), null, $request->accountData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account “:label” added. PayGate will verify it before customers are sent to it.', ['label' => $account->label])]);

        return back();
    }

    public function update(PaymentAccountRequest $request, PaymentAccount $account, SavePaymentAccount $save): RedirectResponse
    {
        $this->ensureOwn($request->actor(), $account);
        $save->handle($request->actor(), $this->branch($request->actor()), $account, $request->accountData());

        Inertia::flash('toast', ['type' => 'success', 'message' => $account->status === AccountStatus::VerificationPending
            ? __('Account saved and sent to PayGate for verification.')
            : __('Account saved.')]);

        return back();
    }

    public function status(Request $request, PaymentAccount $account, ChangeAccountStatus $change): RedirectResponse
    {
        Gate::authorize('accounts.update');

        $actor = $this->actor($request);
        $this->ensureOwn($actor, $account);

        $data = $request->validate([
            'status' => ['required', Rule::enum(AccountStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $change->handle($actor, $account, AccountStatus::from($data['status']), $data['reason'] ?? null);

        $message = $data['status'] === AccountStatus::VerificationPending->value
            ? __('“:label” is waiting for PayGate to verify it.', ['label' => $account->label])
            : __('“:label” is now :status.', ['label' => $account->label, 'status' => str_replace('_', ' ', $data['status'])]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function branch(User $actor): Branch
    {
        return Branch::query()->findOrFail($actor->branch_id);
    }

    private function ensureOwn(User $actor, PaymentAccount $account): void
    {
        abort_unless($account->branch_id === $actor->branch_id, 404);
    }
}
