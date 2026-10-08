<?php

namespace App\Http\Admin\Branches;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Branch\Actions\ChangeBranchStatus;
use App\Domain\Branch\Actions\ConfigureBranch;
use App\Domain\Branch\Actions\TopUpBranchLimit;
use App\Domain\Branch\Models\Branch;
use App\Domain\Branch\Models\BranchLimitTopup;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\Models\CommissionRate;
use App\Domain\Commission\RateBook;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Http\Admin\Branches\Requests\BranchRequest;
use App\Http\Admin\Branches\Requests\ChangeBranchStatusRequest;
use App\Http\Admin\Branches\Requests\TopUpRequest;
use App\Http\Controller;
use App\Http\Shared\Requests\UpdateLimitsRequest;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branches (design: list with deposit / withdrawal columns, form, drawer).
 */
class BranchController extends Controller
{
    private const STATUS_TABS = ['active', 'draft', 'suspended', 'offboarded'];

    public function __construct(private RateBook $rates, private UsageCounters $usage) {}

    public function index(Request $request, ChangeBranchStatus $status): Response
    {
        Gate::authorize('branches.view');

        $actor = $this->actor($request);
        $filter = in_array($request->query('status'), self::STATUS_TABS, true) ? (string) $request->query('status') : null;
        $search = trim((string) $request->query('search'));

        $branches = Branch::query()
            ->withCount([
                'paymentAccounts as active_accounts_count' => fn (Builder $query) => $query->where('status', AccountStatus::Active),
                'mappings as partners_count' => fn (Builder $query) => $query->where('status', 'active'),
            ])
            ->with(['users' => fn ($query) => $query->whereRelation('role', 'slug', SystemRoles::BRANCH_OWNER)->orderBy('name')])
            ->when($filter, fn (Builder $query) => $query->where('status', $filter))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('code', "%{$search}%")))
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        $ids = $branches->getCollection()->pluck('id')->all();
        $rates = $this->rates->currentForMany('branch', $ids, 'branch');
        $deposits = $this->usage->today('branch', $ids, Direction::Deposit);
        $withdrawals = $this->usage->today('branch', $ids, Direction::Withdrawal);
        $selected = $request->query('branch');

        return Inertia::render('admin/branches/index', [
            'branches' => $branches->through(fn (Branch $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'status' => $branch->status->value,
                'deposit_limit_type' => $branch->deposit_limit_type,
                'deposit_topup_balance' => $branch->deposit_topup_balance,
                'is_deposit_enabled' => $branch->is_deposit_enabled,
                'is_withdrawal_enabled' => $branch->is_withdrawal_enabled,
                'deposit_daily_limit' => $branch->deposit_daily_limit,
                'withdrawal_daily_limit' => $branch->withdrawal_daily_limit,
                'withdrawal_min_amount' => $branch->withdrawal_min_amount,
                'withdrawal_max_amount' => $branch->withdrawal_max_amount,
                'limits' => UpdateLimitsRequest::formValues($branch),
                'rates' => $rates[$branch->id] ?? (object) [],
                'today' => [
                    'deposit' => $deposits[$branch->id]['amount'] ?? 0,
                    'withdrawal' => $withdrawals[$branch->id]['amount'] ?? 0,
                ],
                'active_accounts' => $branch->active_accounts_count,
                'partners_count' => $branch->partners_count,
                'admins' => $branch->users->pluck('name'),
            ]),
            'filters' => ['status' => $filter, 'search' => $search],
            'counts' => ['all' => Branch::query()->count(), ...Branch::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all()],
            'selected' => is_string($selected) ? $selected : null,
            'detail' => Inertia::optional(fn () => is_string($selected) ? $this->detail(Branch::query()->findOrFail($selected), $status) : null),
            'can' => [
                'create' => $actor->can('branches.create'),
                'update' => $actor->can('branches.update'),
                'users' => $actor->can('users.view'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('branches.create');

        return $this->form($request, null);
    }

    public function store(BranchRequest $request, ConfigureBranch $configure): RedirectResponse
    {
        $result = $configure->handle(
            $request->actor(),
            null,
            $request->branchAttributes(),
            $request->rates(),
            $request->partnerIds(),
            $request->admin(),
            $request->boolean('activate') && $request->actor()->can('branches.update'),
        );

        $this->flashResult(__('Branch “:name” created.', ['name' => $result['branch']->name]).($request->admin() ? ' '.__('Its branch admin can now log in.') : ''), $result['negative_margins']);

        return to_route('admin.branches.index', ['branch' => $result['branch']->id]);
    }

    public function edit(Request $request, Branch $branch): Response
    {
        Gate::authorize('branches.update');

        return $this->form($request, $branch);
    }

    public function update(BranchRequest $request, Branch $branch, ConfigureBranch $configure): RedirectResponse
    {
        $result = $configure->handle($request->actor(), $branch, $request->branchAttributes(), $request->rates(), $request->partnerIds(), null, $request->boolean('activate'));

        $this->flashResult(__('Branch “:name” saved.', ['name' => $branch->name]), $result['negative_margins']);

        return to_route('admin.branches.index', ['branch' => $branch->id]);
    }

    public function status(ChangeBranchStatusRequest $request, Branch $branch, ChangeBranchStatus $change): RedirectResponse
    {
        $status = OrganisationStatus::from($request->string('status')->value());
        $change->handle($request->actor(), $branch, $status, $request->string('reason')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('“:name” is now :status.', ['name' => $branch->name, 'status' => str_replace('_', ' ', $status->value)])]);

        return back();
    }

    public function topup(TopUpRequest $request, Branch $branch, TopUpBranchLimit $topUp): RedirectResponse
    {
        $record = $topUp->handle($request->actor(), $branch, $request->amountInPaise(), $request->string('reason')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deposit allowance of :branch is now ₹:balance.', [
            'branch' => $branch->code,
            'balance' => number_format($record->balance_after / 100, 2),
        ])]);

        return back();
    }

    public function limits(UpdateLimitsRequest $request, Branch $branch, ConfigureBranch $configure): RedirectResponse
    {
        $configure->handle($this->actor($request), $branch, $request->limitAttributes(), null, null, null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Limits for “:name” saved.', ['name' => $branch->name])]);

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * @param  array<string, mixed>  $negativeMargins
     */
    private function flashResult(string $message, array $negativeMargins): void
    {
        if ($negativeMargins === []) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

            return;
        }

        $partners = collect($negativeMargins)->flatten(1)->pluck('code')->unique()->implode(', ');

        Inertia::flash('toast', ['type' => 'warning', 'message' => $message.' '.__('Warning: this branch earns more than partners :partners pay, so the platform loses the difference (recorded in the audit log).', ['partners' => $partners])]);
    }

    private function form(Request $request, ?Branch $branch): Response
    {
        $actor = $this->actor($request);
        $partners = Partner::query()->where('status', '!=', 'offboarded')->orderBy('code')->get(['id', 'code', 'name', 'status']);
        $partnerRates = $this->rates->currentForMany('partner', $partners->pluck('id')->all(), 'partner');

        return Inertia::render('admin/branches/form', [
            'branch' => $branch ? [
                ...$branch->only(['id', 'code', 'name', 'is_deposit_enabled', 'is_withdrawal_enabled', 'deposit_limit_type']),
                'status' => $branch->status->value,
                ...collect(['deposit_min_amount', 'deposit_max_amount', 'deposit_daily_limit', 'withdrawal_min_amount', 'withdrawal_max_amount', 'withdrawal_daily_limit'])
                    ->mapWithKeys(fn (string $field) => [$field => Money::toRupees($branch->{$field})])->all(),
                'deposit_rate' => $this->rates->branchRate($branch, Direction::Deposit),
                'withdrawal_rate' => $this->rates->branchRate($branch, Direction::Withdrawal),
                'partner_ids' => $branch->mappings()->where('status', 'active')->pluck('partner_id'),
            ] : null,
            'partners' => $partners->map(fn (Partner $partner) => [
                'id' => $partner->id,
                'code' => $partner->code,
                'name' => $partner->name,
                'status' => $partner->status->value,
                'rates' => $partnerRates[$partner->id] ?? (object) [],
            ]),
            'can' => [
                'rates' => $actor->can('commissions.update'),
                'mappings' => $actor->can('mappings.update'),
                'add_admin' => $actor->can('users.create'),
                'activate' => $actor->can('branches.update'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Branch $branch, ChangeBranchStatus $status): array
    {
        return [
            'id' => $branch->id,
            'limits' => [
                'deposit' => [$branch->deposit_min_amount, $branch->deposit_max_amount, $branch->deposit_daily_limit],
                'withdrawal' => [$branch->withdrawal_min_amount, $branch->withdrawal_max_amount, $branch->withdrawal_daily_limit],
            ],
            'verified_at' => $branch->verified_at?->toIso8601String(),
            'created_at' => $branch->created_at?->toIso8601String(),
            'accounts' => $branch->paymentAccounts()->orderBy('label')->get()->map(fn ($account) => [
                'label' => $account->label,
                'holder' => $account->account_holder_name,
                'bank' => $account->is_bank_enabled ? "{$account->bank_name} · {$account->maskedAccountNumber()}" : null,
                'upi' => $account->maskedUpiId(),
                'status' => $account->status->value,
            ]),
            'partners' => PartnerBranchMapping::query()->where('branch_id', $branch->id)->with('partner')->orderByDesc('status')->get()->map(fn (PartnerBranchMapping $mapping) => [
                'code' => $mapping->partner->code,
                'name' => $mapping->partner->name,
                'status' => $mapping->status,
                'deposit' => $mapping->is_deposit_enabled,
                'withdrawal' => $mapping->is_withdrawal_enabled,
            ]),
            'users' => $branch->users()->with('role')->orderBy('name')->get()->map(fn (User $user) => [
                'name' => $user->username ?? $user->name,
                'role' => $user->role->name,
                'status' => $user->displayStatus(),
            ]),
            'topups' => $branch->topups()->with('creator')->latest('created_at')->limit(20)->get()->map(fn (BranchLimitTopup $topup) => [
                'amount' => $topup->amount,
                'balance_after' => $topup->balance_after,
                'reason' => $topup->reason,
                'by' => $topup->creator->name ?? '—',
                'at' => $topup->created_at->toIso8601String(),
            ]),
            'rate_history' => CommissionRate::query()
                ->where(['subject_type' => 'branch', 'subject_id' => $branch->id])
                ->orderByDesc('effective_from')
                ->limit(20)
                ->get()
                ->map(fn (CommissionRate $rate) => [
                    'direction' => $rate->direction->value,
                    'rate' => $rate->rate_percent,
                    'from' => $rate->effective_from->toIso8601String(),
                    'to' => $rate->effective_to?->toIso8601String(),
                ]),
            'activity' => AuditLog::query()
                ->where(['subject_type' => 'branch', 'subject_id' => $branch->id])
                ->with('actor')
                ->latest('created_at')
                ->limit(15)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'action' => $log->action,
                    'actor' => $log->actor->name ?? 'System',
                    'at' => $log->created_at->toIso8601String(),
                    'reason' => $log->new_values['reason'] ?? null,
                ]),
            'transitions' => array_map(fn (OrganisationStatus $next) => $next->value, $branch->status->transitions()),
            'blockers' => $branch->status === OrganisationStatus::Active ? [] : $status->activationBlockers($branch),
        ];
    }
}
