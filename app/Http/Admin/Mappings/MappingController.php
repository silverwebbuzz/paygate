<?php

namespace App\Http\Admin\Mappings;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\RateBook;
use App\Domain\Commission\RatePercent;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Actions\SyncMappings;
use App\Domain\Network\Actions\UpdateMapping;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Http\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Partner ↔ branch mapping: every pair with its switches, pair limits and
 * the rates that apply (pair overrides win over the partner / branch rate).
 */
class MappingController extends Controller
{
    public function __construct(private RateBook $rates) {}

    public function index(Request $request): Response
    {
        Gate::authorize('mappings.view');

        /** @var User $actor */
        $actor = $request->user();
        $partnerId = $request->query('partner');
        $branchId = $request->query('branch');
        $status = in_array($request->query('status'), ['active', 'inactive'], true) ? (string) $request->query('status') : null;

        $mappings = PartnerBranchMapping::query()
            ->with(['partner', 'branch'])
            ->when(is_string($partnerId) && $partnerId !== '', fn (Builder $query) => $query->where('partner_id', $partnerId))
            ->when(is_string($branchId) && $branchId !== '', fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->join('partners', 'partners.id', '=', 'partner_branch_mappings.partner_id')
            ->join('branches', 'branches.id', '=', 'partner_branch_mappings.branch_id')
            ->orderBy('partners.code')
            ->orderBy('branches.code')
            ->select('partner_branch_mappings.*')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/mappings/index', [
            'mappings' => $mappings->through(fn (PartnerBranchMapping $mapping) => $this->row($mapping)),
            'filters' => ['partner' => $partnerId, 'branch' => $branchId, 'status' => $status],
            'partners' => Partner::query()->orderBy('code')->get(['id', 'code', 'name']),
            'branches' => Branch::query()->orderBy('code')->get(['id', 'code', 'name']),
            'can' => [
                'update' => $actor->can('mappings.update'),
                'rates' => $actor->can('commissions.update'),
            ],
        ]);
    }

    public function store(Request $request, SyncMappings $mappings): RedirectResponse
    {
        Gate::authorize('mappings.update');

        $data = $request->validate([
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')],
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $mapping = $mappings->map($actor, Partner::query()->findOrFail((string) $data['partner_id']), Branch::query()->findOrFail((string) $data['branch_id']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':partner is mapped to :branch.', ['partner' => $mapping->partner->code, 'branch' => $mapping->branch->code])]);

        return back();
    }

    public function update(MappingRequest $request, PartnerBranchMapping $mapping, UpdateMapping $update): RedirectResponse
    {
        $losing = $update->handle($request->actor(), $mapping, $request->mappingAttributes(), $request->overrides());

        Inertia::flash('toast', $losing === []
            ? ['type' => 'success', 'message' => __('Mapping saved.')]
            : ['type' => 'warning', 'message' => __('Mapping saved. Warning: the branch earns more than the partner pays for :directions, so the platform loses the difference (recorded in the audit log).', ['directions' => implode(' and ', $losing)])]);

        return back();
    }

    public function byPartner(Request $request): Response
    {
        return $this->assignment($request, 'partner');
    }

    public function updateByPartner(Request $request, SyncMappings $mappings): RedirectResponse
    {
        Gate::authorize('mappings.update');

        $data = $request->validate([
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')],
            'branch_ids' => ['present', 'array'],
            'branch_ids.*' => ['uuid', 'distinct', Rule::exists('branches', 'id')],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        /** @var Partner $partner */
        $partner = Partner::query()->findOrFail((string) $data['partner_id']);
        $mappings->forPartner($actor, $partner, $data['branch_ids']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':partner is assigned to :count branches.', ['partner' => $partner->code, 'count' => count($data['branch_ids'])])]);

        return to_route('admin.mappings.by-partner', ['partner' => $partner->id]);
    }

    public function byBranch(Request $request): Response
    {
        return $this->assignment($request, 'branch');
    }

    public function updateByBranch(Request $request, SyncMappings $mappings): RedirectResponse
    {
        Gate::authorize('mappings.update');

        $data = $request->validate([
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'partner_ids' => ['present', 'array'],
            'partner_ids.*' => ['uuid', 'distinct', Rule::exists('partners', 'id')],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        /** @var Branch $branch */
        $branch = Branch::query()->findOrFail((string) $data['branch_id']);
        $mappings->forBranch($actor, $branch, $data['partner_ids']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':branch is assigned to :count partners.', ['branch' => $branch->code, 'count' => count($data['partner_ids'])])]);

        return to_route('admin.mappings.by-branch', ['branch' => $branch->id]);
    }

    private function assignment(Request $request, string $side): Response
    {
        Gate::authorize('mappings.view');

        /** @var User $actor */
        $actor = $request->user();
        $forPartner = $side === 'partner';
        $partners = Partner::query()->where('status', '!=', 'offboarded')->orderBy('code')->get(['id', 'code', 'name', 'status']);
        $branches = Branch::query()->where('status', '!=', 'offboarded')->orderBy('code')->get(['id', 'code', 'name', 'status']);
        $owners = $forPartner ? $partners : $branches;
        $items = $forPartner ? $branches : $partners;
        $asked = $request->query($side);
        $selected = is_string($asked) && $owners->contains('id', $asked) ? $asked : $owners->first()?->id;
        $ownerColumn = $forPartner ? 'partner_id' : 'branch_id';
        $itemColumn = $forPartner ? 'branch_id' : 'partner_id';
        $selectedIds = $selected === null ? [] : PartnerBranchMapping::query()
            ->where($ownerColumn, $selected)
            ->where('status', 'active')
            ->whereIn($itemColumn, $items->pluck('id'))
            ->orderBy($itemColumn)
            ->pluck($itemColumn)
            ->all();
        $counts = PartnerBranchMapping::query()
            ->where('status', 'active')
            ->whereIn($itemColumn, $items->pluck('id'))
            ->toBase()
            ->selectRaw($ownerColumn.' as owner_id, count(*) as total')
            ->groupBy('owner_id')
            ->pluck('total', 'owner_id');
        $rateSubject = $forPartner ? 'branch' : 'partner';
        $rates = $this->rates->currentForMany($rateSubject, $items->pluck('id')->all(), $rateSubject);

        return Inertia::render('admin/mappings/assign', [
            'side' => $side,
            'owners' => $owners->map(fn (Partner|Branch $org) => [
                'id' => $org->id,
                'code' => $org->code,
                'name' => $org->name,
                'status' => $org->status->value,
                'assigned' => (int) ($counts[$org->id] ?? 0),
            ])->values(),
            'selected' => $selected,
            'items' => $items->map(fn (Partner|Branch $org) => [
                'id' => $org->id,
                'code' => $org->code,
                'name' => $org->name,
                'status' => $org->status->value,
                'rates' => $rates[$org->id] ?? (object) [],
            ])->values(),
            'selected_ids' => $selectedIds,
            'can' => ['update' => $actor->can('mappings.update')],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PartnerBranchMapping $mapping): array
    {
        $rates = [];

        foreach (Direction::cases() as $direction) {
            $pair = $this->rates->forPair($mapping, $direction);
            $rates[$direction->value] = [
                'partner' => $pair['partner'],
                'branch' => $pair['branch'],
                'partner_override' => $this->rates->current('mapping', $mapping->id, 'partner', $direction),
                'branch_override' => $this->rates->current('mapping', $mapping->id, 'branch', $direction),
                // Platform margin = partner rate − branch rate (negative = loss).
                'margin' => $pair['partner'] !== null && $pair['branch'] !== null
                    ? RatePercent::fromUnits(RatePercent::units($pair['partner']) - RatePercent::units($pair['branch']))
                    : null,
            ];
        }

        return [
            'id' => $mapping->id,
            'partner' => ['id' => $mapping->partner->id, 'code' => $mapping->partner->code, 'name' => $mapping->partner->name],
            'branch' => ['id' => $mapping->branch->id, 'code' => $mapping->branch->code, 'name' => $mapping->branch->name],
            'status' => $mapping->status,
            'is_deposit_enabled' => $mapping->is_deposit_enabled,
            'is_withdrawal_enabled' => $mapping->is_withdrawal_enabled,
            'deposit_daily_limit' => $mapping->deposit_daily_limit,
            'withdrawal_daily_limit' => $mapping->withdrawal_daily_limit,
            'rates' => $rates,
        ];
    }
}
