<?php

namespace App\Http\Admin\Partners;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\Models\CommissionRate;
use App\Domain\Commission\RateBook;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Actions\ChangePartnerStatus;
use App\Domain\Partner\Actions\ConfigurePartner;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Domain\Partner\Models\PartnerIpRule;
use App\Domain\Partner\PartnerIntegrationFile;
use App\Http\Admin\Partners\Requests\ChangePartnerStatusRequest;
use App\Http\Admin\Partners\Requests\PartnerRequest;
use App\Http\Controller;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Partners (design: list + 7-step create wizard + detail drawer).
 */
class PartnerController extends Controller
{
    private const STATUS_TABS = ['active', 'draft', 'suspended', 'offboarded'];

    public function __construct(private RateBook $rates) {}

    public function index(Request $request, ChangePartnerStatus $status): Response
    {
        Gate::authorize('partners.view');

        $actor = $this->actor($request);
        $filter = in_array($request->query('status'), self::STATUS_TABS, true) ? (string) $request->query('status') : null;
        $search = trim((string) $request->query('search'));

        $partners = Partner::query()
            ->with('activeApiKey')
            ->withCount(['mappings as branches_count' => fn (Builder $query) => $query->where('status', 'active'), 'users'])
            ->when($filter, fn (Builder $query) => $query->where('status', $filter))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('code', "%{$search}%")
                ->orWhereLike('email', "%{$search}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $rates = $this->rates->currentForMany('partner', $partners->getCollection()->pluck('id')->all(), 'partner');

        $selected = $request->query('partner');

        return Inertia::render('admin/partners/index', [
            'partners' => $partners->through(fn (Partner $partner) => [
                'id' => $partner->id,
                'name' => $partner->name,
                'code' => $partner->code,
                'email' => $partner->email,
                'return_url' => $partner->return_url,
                'status' => $partner->status->value,
                'is_payin_enabled' => $partner->is_payin_enabled,
                'is_payout_enabled' => $partner->is_payout_enabled,
                'key' => $partner->activeApiKey ? ['key_id' => $partner->activeApiKey->key_id, 'last4' => $partner->activeApiKey->secret_last4] : null,
                'rates' => $rates[$partner->id] ?? (object) [],
                'branches_count' => $partner->branches_count,
                'users_count' => $partner->users_count,
            ]),
            'filters' => ['status' => $filter, 'search' => $search],
            'counts' => ['all' => Partner::query()->count(), ...Partner::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all()],
            'selected' => is_string($selected) ? $selected : null,
            // Loaded when the drawer opens (router.reload({ only: ['detail'] })).
            'detail' => Inertia::optional(fn () => is_string($selected) ? $this->detail(Partner::query()->findOrFail($selected), $status) : null),
            'can' => [
                'create' => $actor->can('partners.create'),
                'update' => $actor->can('partners.update'),
                'users' => $actor->can('users.view'),
                'issue_keys' => $actor->can('api_keys.create'),
                'revoke_keys' => $actor->can('api_keys.delete'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('partners.create');

        return $this->form($request, null);
    }

    public function store(PartnerRequest $request, ConfigurePartner $configure): RedirectResponse
    {
        $result = $configure->handle(
            $request->actor(),
            null,
            $request->partnerAttributes(),
            $request->ipAddresses(),
            $request->rates(),
            $request->branchIds(),
            $request->boolean('activate') && $request->actor()->can('partners.update'),
        );

        $partner = $result['partner'];

        if (is_string($result['secret'])) {
            PartnerIntegrationFile::flashCredentials(
                $partner,
                (string) $partner->activeApiKey()->value('key_id'),
                $result['secret'],
                route('admin.partners.integration-file.issued', $partner),
            );
        }
        $this->flashResult(__('Partner “:name” created.', ['name' => $partner->name]), $result['negative_margins']);

        return to_route('admin.partners.index', ['partner' => $partner->id]);
    }

    public function edit(Request $request, Partner $partner): Response
    {
        Gate::authorize('partners.update');

        return $this->form($request, $partner);
    }

    public function update(PartnerRequest $request, Partner $partner, ConfigurePartner $configure): RedirectResponse
    {
        $result = $configure->handle(
            $request->actor(),
            $partner,
            $request->partnerAttributes(),
            $request->ipAddresses(),
            $request->rates(),
            $request->branchIds(),
            $request->boolean('activate'),
        );

        $this->flashResult(__('Partner “:name” saved.', ['name' => $partner->name]), $result['negative_margins']);

        return to_route('admin.partners.index', ['partner' => $partner->id]);
    }

    public function status(ChangePartnerStatusRequest $request, Partner $partner, ChangePartnerStatus $change): RedirectResponse
    {
        $status = OrganisationStatus::from($request->string('status')->value());
        $change->handle($request->actor(), $partner, $status, $request->string('reason')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('“:name” is now :status.', ['name' => $partner->name, 'status' => str_replace('_', ' ', $status->value)])]);

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

        $branches = collect($negativeMargins)->flatten(1)->pluck('code')->unique()->implode(', ');

        Inertia::flash('toast', ['type' => 'warning', 'message' => $message.' '.__('Warning: branches :branches earn more than this partner pays, so the platform loses the difference (recorded in the audit log).', ['branches' => $branches])]);
    }

    private function form(Request $request, ?Partner $partner): Response
    {
        $actor = $this->actor($request);
        $branches = Branch::query()->where('status', '!=', 'offboarded')->orderBy('code')->get(['id', 'code', 'name', 'status']);
        $branchRates = $this->rates->currentForMany('branch', $branches->pluck('id')->all(), 'branch');

        return Inertia::render('admin/partners/form', [
            'partner' => $partner ? $this->formData($partner) : null,
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'status' => $branch->status,
                'rates' => $branchRates[$branch->id] ?? (object) [],
            ]),
            'can' => [
                'rates' => $actor->can('commissions.update'),
                'mappings' => $actor->can('mappings.update'),
                'ips' => $actor->can('ip_rules.create'),
                'activate' => $actor->can('partners.update'),
            ],
        ]);
    }

    /**
     * The partner in the wizard's field format (amounts in rupees).
     *
     * @return array<string, mixed>
     */
    private function formData(Partner $partner): array
    {
        $data = $partner->only([
            'id', 'name', 'code', 'email', 'description', 'website_url', 'return_url', 'callback_url',
            'payin_webhook_url', 'payout_webhook_url', 'api_version', 'is_payin_enabled', 'manual_payment_type',
            'allow_qr', 'allow_upi', 'allow_bank_transfer', 'is_h2h_enabled', 'session_ttl_minutes',
            'payout_limit_type', 'is_payout_enabled', 'withdraw_url', 'payout_group', 'is_auto_withdrawal',
            'is_partial_withdrawal',
        ]);

        foreach (['deposit_min_amount', 'deposit_max_amount', 'deposit_daily_limit', 'withdrawal_min_amount', 'withdrawal_max_amount', 'withdrawal_daily_limit'] as $field) {
            $data[$field] = Money::toRupees($partner->{$field});
        }

        return [
            ...$data,
            'status' => $partner->status->value,
            'ip_addresses' => $partner->ipRules()->orderBy('created_at')->get()->map(fn (PartnerIpRule $rule) => $rule->display())->implode("\n"),
            'deposit_rate' => $this->rates->partnerRate($partner, Direction::Deposit),
            'withdrawal_rate' => $this->rates->partnerRate($partner, Direction::Withdrawal),
            'branch_ids' => $partner->mappings()->where('status', 'active')->pluck('branch_id'),
        ];
    }

    /**
     * Everything the detail drawer shows.
     *
     * @return array<string, mixed>
     */
    private function detail(Partner $partner, ChangePartnerStatus $status): array
    {
        return [
            'id' => $partner->id,
            'website_url' => $partner->website_url,
            'callback_url' => $partner->callback_url,
            'payin_webhook_url' => $partner->payin_webhook_url,
            'payout_webhook_url' => $partner->payout_webhook_url,
            'api_version' => $partner->api_version,
            'manual_payment_type' => $partner->manual_payment_type,
            'methods' => array_keys(array_filter([
                'QR' => $partner->allow_qr, 'UPI' => $partner->allow_upi, 'Bank' => $partner->allow_bank_transfer, 'H2H' => $partner->is_h2h_enabled,
            ])),
            'limits' => [
                'deposit' => [$partner->deposit_min_amount, $partner->deposit_max_amount, $partner->deposit_daily_limit],
                'withdrawal' => [$partner->withdrawal_min_amount, $partner->withdrawal_max_amount, $partner->withdrawal_daily_limit],
            ],
            'payout_limit_type' => $partner->payout_limit_type,
            'is_auto_withdrawal' => $partner->is_auto_withdrawal,
            'is_partial_withdrawal' => $partner->is_partial_withdrawal,
            'session_ttl_minutes' => $partner->session_ttl_minutes,
            'verified_at' => $partner->verified_at?->toIso8601String(),
            'created_at' => $partner->created_at?->toIso8601String(),
            'keys' => $partner->apiKeys()->orderByDesc('created_at')->limit(10)->get()->map(fn (PartnerApiKey $key) => [
                'id' => $key->id,
                'key_id' => $key->key_id,
                'last4' => $key->secret_last4,
                'status' => $key->status === 'rotating' && ! $key->isUsable() ? 'expired' : $key->status,
                'expires_at' => $key->expires_at?->toIso8601String(),
                'created_at' => $key->created_at->toIso8601String(),
                'last_used_at' => $key->last_used_at?->toIso8601String(),
            ]),
            'ips' => $partner->ipRules()->orderBy('created_at')->get()->map(fn (PartnerIpRule $rule) => $rule->display()),
            'rate_history' => CommissionRate::query()
                ->where(['subject_type' => 'partner', 'subject_id' => $partner->id])
                ->orderByDesc('effective_from')
                ->limit(20)
                ->get()
                ->map(fn (CommissionRate $rate) => [
                    'direction' => $rate->direction->value,
                    'rate' => $rate->rate_percent,
                    'from' => $rate->effective_from->toIso8601String(),
                    'to' => $rate->effective_to?->toIso8601String(),
                ]),
            'branches' => PartnerBranchMapping::query()
                ->where('partner_id', $partner->id)
                ->with('branch')
                ->orderByDesc('status')
                ->get()
                ->map(fn (PartnerBranchMapping $mapping) => [
                    'code' => $mapping->branch->code,
                    'name' => $mapping->branch->name,
                    'status' => $mapping->status,
                    'deposit' => $mapping->is_deposit_enabled,
                    'withdrawal' => $mapping->is_withdrawal_enabled,
                ]),
            'activity' => AuditLog::query()
                ->where(['subject_type' => 'partner', 'subject_id' => $partner->id])
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
            'transitions' => array_map(fn (OrganisationStatus $next) => $next->value, $partner->status->transitions()),
            'blockers' => $partner->status === OrganisationStatus::Active ? [] : $status->activationBlockers($partner),
        ];
    }
}
