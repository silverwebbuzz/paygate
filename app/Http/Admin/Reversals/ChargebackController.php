<?php

namespace App\Http\Admin\Reversals;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Transaction\Actions\ManageChargeback;
use App\Domain\Transaction\Models\Chargeback;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chargebacks (Admin): what branches raised, with the bank's notice and
 * the deposit beside it; approve (booked that day, the partner bears it)
 * or reject (decided 2026-10-21).
 */
class ChargebackController extends Controller
{
    private const TABS = ['requested', 'approved', 'rejected'];

    public function index(Request $request): Response
    {
        Gate::authorize('reversals.view');

        /** @var User $actor */
        $actor = $request->user();
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'requested';
        $search = trim((string) $request->query('search'));

        $items = Chargeback::query()
            ->where('status', $tab)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', strtoupper($search))
                ->orWhere('utr', Transaction::normaliseUtr($search))
                ->orWhereHas('transaction', fn (Builder $query) => $query->where('reference', strtoupper($search))->orWhere('partner_transaction_id', $search))))
            ->with(['transaction.customer', 'partner', 'branch', 'requester', 'decider', 'proof'])
            ->orderByDesc($tab === 'requested' ? 'created_at' : 'decided_at')
            ->paginate(30)
            ->withQueryString();

        $sameUtr = Chargeback::query()
            ->whereIn('utr', collect($items->items())->pluck('utr')->filter()->unique()->all())
            ->where('status', '!=', 'rejected')
            ->get(['reference', 'utr'])
            ->groupBy('utr')
            ->map(fn ($group) => $group->pluck('reference'));

        $rows = [];

        foreach ($items->items() as $chargeback) {
            /** @var Chargeback $chargeback */
            $txn = $chargeback->transaction;
            $rows[] = [
                'id' => $chargeback->id,
                'reference' => $chargeback->reference,
                'status' => $chargeback->status,
                'amount' => $chargeback->amount,
                'utr' => $chargeback->utr,
                // The debit's UTR on other chargebacks (one bank debit may cover two complaints, or be a mistake).
                'utr_also_on' => $chargeback->utr === null ? [] : $sameUtr->get($chargeback->utr, collect())->reject(fn (string $reference) => $reference === $chargeback->reference)->values()->all(),
                'reason' => $chargeback->reason,
                'proof' => $chargeback->proof === null ? null : ['id' => $chargeback->proof->id, 'name' => $chargeback->proof->original_name, 'mime' => $chargeback->proof->mime],
                'requested_by' => $chargeback->requester->name,
                'requested_at' => $chargeback->created_at?->toIso8601String(),
                'decided_by' => $chargeback->decider?->name,
                'decided_at' => $chargeback->decided_at?->toIso8601String(),
                'decision_note' => $chargeback->decision_note,
                'partner' => ['code' => $chargeback->partner->code, 'name' => $chargeback->partner->name],
                'branch' => ['code' => $chargeback->branch->code, 'name' => $chargeback->branch->name],
                'transaction' => [
                    'id' => $txn->id,
                    'reference' => $txn->reference,
                    'order_id' => $txn->partner_transaction_id,
                    'status' => $txn->status,
                    'amount' => $txn->amount,
                    'method' => $txn->method,
                    'bank_utr' => $txn->bank_utr_normalized,
                    'customer' => $txn->customer === null ? null : ['id' => $txn->customer->external_id, 'name' => $txn->customer->name, 'mobile' => $txn->customer->mobile],
                    'created_at' => $txn->created_at?->toIso8601String(),
                    'succeeded_at' => $txn->succeeded_at?->toIso8601String(),
                ],
                // Maker–checker: never your own.
                'can_decide' => $chargeback->status === 'requested' && $actor->can('reversals.approve') && $chargeback->requested_by !== $actor->id,
            ];
        }

        $totals = Chargeback::query()
            ->toBase()
            ->selectRaw('status, count(*) as total, coalesce(sum(amount), 0) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return Inertia::render('admin/chargebacks', [
            'tab' => $tab,
            'search' => $search,
            'items' => [...$items->toArray(), 'data' => $rows],
            'totals' => collect(self::TABS)->mapWithKeys(fn (string $status) => [$status => [
                'count' => (int) ($totals->get($status)->total ?? 0),
                'amount' => (int) ($totals->get($status)->amount ?? 0),
            ]]),
        ]);
    }

    public function approve(Request $request, Chargeback $chargeback, ManageChargeback $chargebacks): RedirectResponse
    {
        Gate::authorize('reversals.approve');

        /** @var User $actor */
        $actor = $request->user();
        $chargebacks->approve($actor, $chargeback, $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference approved: booked today, the partner is notified.', ['reference' => $chargeback->reference])]);

        return back();
    }

    public function reject(Request $request, Chargeback $chargeback, ManageChargeback $chargebacks): RedirectResponse
    {
        Gate::authorize('reversals.approve');

        /** @var User $actor */
        $actor = $request->user();
        $chargebacks->reject($actor, $chargeback, (string) $request->validate(['note' => ['required', 'string', 'max:500']])['note']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference rejected; the branch is told why.', ['reference' => $chargeback->reference])]);

        return back();
    }
}
