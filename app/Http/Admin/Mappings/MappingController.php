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
