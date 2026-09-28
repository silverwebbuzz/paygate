<?php

namespace App\Http\Admin\Settlements;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Settlement\Actions\ManageAdjustment;
use App\Domain\Settlement\Models\Adjustment;
use App\Http\Controller;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Adjustments (Admin): partner top-ups, corrections and goodwill, requested
 * by one admin and approved or rejected by another (maker–checker, G-44).
 */
class AdjustmentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('adjustments.view');

        /** @var User $actor */
        $actor = $request->user();
        $tab = in_array($request->query('tab'), ['pending', 'approved', 'rejected'], true) ? (string) $request->query('tab') : 'pending';

        $items = Adjustment::query()
            ->where('status', $tab)
            ->with(['partner', 'branch', 'requester', 'approver', 'case'])
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $rows = [];

        foreach ($items->items() as $adjustment) {
            /** @var Adjustment $adjustment */
            $rows[] = [
                'id' => $adjustment->id,
                'reference' => $adjustment->reference,
                'type' => $adjustment->type,
                'partner' => ['code' => $adjustment->partner->code, 'name' => $adjustment->partner->name],
                'branch' => ['code' => $adjustment->branch->code, 'name' => $adjustment->branch->name],
                'side' => $adjustment->side,
                'amount' => $adjustment->amount,
                'reason' => $adjustment->reason,
                'case' => $adjustment->case?->reference,
                'status' => $adjustment->status,
                'requested_by' => $adjustment->requester->name,
                'requested_at' => $adjustment->created_at?->toIso8601String(),
                'decided_by' => $adjustment->approver?->name,
                'decided_at' => $adjustment->approved_at?->toIso8601String(),
                'decision_note' => $adjustment->decision_note,
                // Maker–checker: never your own.
                'can_decide' => $adjustment->status === 'pending' && $actor->can('adjustments.approve') && $adjustment->requested_by !== $actor->id,
                'own' => $adjustment->requested_by === $actor->id,
            ];
        }

        return Inertia::render('admin/adjustments', [
            'tab' => $tab,
            'items' => [...$items->toArray(), 'data' => $rows],
            'counts' => collect(['pending', 'approved', 'rejected'])->mapWithKeys(fn (string $status) => [$status => Adjustment::query()->where('status', $status)->count()]),
            'partners' => Partner::query()->orderBy('code')->get(['id', 'code', 'name']),
            'branches' => Branch::query()->orderBy('code')->get(['id', 'code', 'name']),
            'mappings' => PartnerBranchMapping::query()->get(['partner_id', 'branch_id']),
            'prefill_case' => is_string($request->query('case')) ? ReconciliationCase::query()->where('status', '!=', 'resolved')->find($request->query('case'))?->only(['id', 'reference', 'branch_id', 'transaction_id']) : null,
            'can' => ['create' => $actor->can('adjustments.create')],
        ]);
    }

    public function store(Request $request, ManageAdjustment $adjustments): RedirectResponse
    {
        Gate::authorize('adjustments.create');

        $data = $request->validate([
            'type' => ['required', Rule::in(Adjustment::TYPES)],
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')],
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'side' => ['required', Rule::in(['partner', 'branch'])],
            'sign' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'string', Money::RUPEES_RULE],
            'reason' => ['required', 'string', 'max:500'],
            'case' => ['nullable', 'string', 'max:30'],
        ]);

        $case = null;

        if (($data['case'] ?? '') !== '') {
            $case = ReconciliationCase::query()->where('reference', strtoupper(trim((string) $data['case'])))->first()
                ?? throw ValidationException::withMessages(['case' => __('No case with this reference.')]);
        }

        $paise = Money::toPaise($data['amount']);
        /** @var User $actor */
        $actor = $request->user();

        $adjustment = $adjustments->request(
            $actor,
            $data['type'],
            Partner::query()->findOrFail((string) $data['partner_id']),
            Branch::query()->findOrFail((string) $data['branch_id']),
            $data['side'],
            $data['sign'] === 'credit' ? $paise : -$paise,
            $data['reason'],
            $case,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference requested. Another admin must approve it before it counts.', ['reference' => $adjustment->reference])]);

        return redirect()->route('admin.adjustments.index');
    }

    public function approve(Request $request, Adjustment $adjustment, ManageAdjustment $adjustments): RedirectResponse
    {
        Gate::authorize('adjustments.approve');

        /** @var User $actor */
        $actor = $request->user();
        $adjustments->approve($actor, $adjustment, $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference approved and booked.', ['reference' => $adjustment->reference])]);

        return back();
    }

    public function reject(Request $request, Adjustment $adjustment, ManageAdjustment $adjustments): RedirectResponse
    {
        Gate::authorize('adjustments.approve');

        /** @var User $actor */
        $actor = $request->user();
        $adjustments->reject($actor, $adjustment, $request->validate(['note' => ['required', 'string', 'max:500']])['note']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference rejected.', ['reference' => $adjustment->reference])]);

        return back();
    }
}
