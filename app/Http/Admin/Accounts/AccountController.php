<?php

namespace App\Http\Admin\Accounts;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Actions\ChangeAccountStatus;
use App\Domain\PaymentAccount\Actions\ReviewPaymentAccount;
use App\Domain\PaymentAccount\Actions\SavePaymentAccount;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Http\Controller;
use App\Http\Shared\Accounts\AccountPresenter;
use App\Http\Shared\Accounts\PaymentAccountRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bank & UPI accounts of every branch: Admin verifies new or changed
 * accounts, and can add, edit, pause or disable them.
 */
class AccountController extends Controller
{
    public const STATUS_TABS = ['verification_pending', 'active', 'verified', 'paused', 'rejected', 'disabled'];

    public function index(Request $request, AccountPresenter $presenter): Response
    {
        Gate::authorize('accounts.view');

        /** @var User $actor */
        $actor = $request->user();
        $status = in_array($request->query('status'), self::STATUS_TABS, true) ? (string) $request->query('status') : null;
        $branchId = $request->query('branch');
        $search = trim((string) $request->query('search'));

        $accounts = PaymentAccount::query()
            ->with('branch')
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when(is_string($branchId) && $branchId !== '', fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('label', "%{$search}%")
                ->orWhereLike('account_holder_name', "%{$search}%")
                ->orWhereLike('bank_name', "%{$search}%")
                ->orWhere('account_number_last4', $search)
                ->orWhere('upi_id_last4', $search)))
            ->orderByRaw("CASE WHEN status = 'verification_pending' THEN 0 ELSE 1 END")
            ->orderBy('label')
            ->paginate(50)
            ->withQueryString();

        $revealId = $request->query('reveal');

        return Inertia::render('admin/accounts/index', [
            'accounts' => [
                ...$accounts->toArray(),
                'data' => $presenter->rows($accounts->getCollection(), $actor),
            ],
            'filters' => ['status' => $status, 'branch' => $branchId, 'search' => $search],
            'counts' => ['all' => PaymentAccount::query()->count(), ...PaymentAccount::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all()],
            'branches' => Branch::query()->where('status', '!=', 'offboarded')->orderBy('code')->get(['id', 'code', 'name']),
            // Full numbers for verification, loaded on request and audited.
            'reveal' => Inertia::optional(fn () => is_string($revealId) ? $this->reveal($actor, $revealId) : null),
            'can' => [
                'create' => $actor->can('accounts.create'),
                'verify' => $actor->can('accounts.verify'),
            ],
        ]);
    }

    public function store(PaymentAccountRequest $request, SavePaymentAccount $save): RedirectResponse
    {
        $branch = Branch::query()->findOrFail($request->string('branch_id')->value());
        $account = $save->handle($request->actor(), $branch, null, $request->accountData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account “:label” added and waiting for verification.', ['label' => $account->label])]);

        return back();
    }

    public function update(PaymentAccountRequest $request, PaymentAccount $account, SavePaymentAccount $save): RedirectResponse
    {
        $save->handle($request->actor(), $account->branch, $account, $request->accountData());

        Inertia::flash('toast', ['type' => 'success', 'message' => $account->status === AccountStatus::VerificationPending
            ? __('Account saved. It needs verification before customers are sent to it.')
            : __('Account saved.')]);

        return back();
    }

    public function approve(Request $request, PaymentAccount $account, ReviewPaymentAccount $review): RedirectResponse
    {
        Gate::authorize('accounts.verify');

        /** @var User $actor */
        $actor = $request->user();
        $review->approve($actor, $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('“:label” verified. The branch can now activate it.', ['label' => $account->label])]);

        return back();
    }

    public function reject(Request $request, PaymentAccount $account, ReviewPaymentAccount $review): RedirectResponse
    {
        Gate::authorize('accounts.verify');

        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        /** @var User $actor */
        $actor = $request->user();
        $review->reject($actor, $account, $reason);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('“:label” rejected; the branch sees your reason.', ['label' => $account->label])]);

        return back();
    }

    public function status(Request $request, PaymentAccount $account, ChangeAccountStatus $change): RedirectResponse
    {
        Gate::authorize('accounts.update');

        $data = $request->validate([
            'status' => ['required', Rule::enum(AccountStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $change->handle($actor, $account, AccountStatus::from($data['status']), $data['reason'] ?? null, true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('“:label” is now :status.', ['label' => $account->label, 'status' => $data['status']])]);

        return back();
    }

    /**
     * @return array{id: string, account_number: string|null, upi_id: string|null}|null
     */
    private function reveal(User $actor, string $accountId): ?array
    {
        if (! $actor->can('accounts.verify')) {
            return null;
        }

        $account = PaymentAccount::query()->findOrFail($accountId);

        AuditLog::record('payment_account.revealed', $account, [], ['purpose' => 'verification'], $actor);

        return [
            'id' => $account->id,
            'account_number' => $account->account_number_encrypted,
            'upi_id' => $account->upi_id_encrypted,
        ];
    }
}
