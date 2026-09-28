<?php

namespace App\Http\Admin\Reversals;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Transaction\Actions\ReverseTransaction;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionReversal;
use App\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Refunds (pay-in refunds and returned payouts) and Chargebacks (Admin):
 * the list, and recording a new one against a successful transaction
 * found by its id (decided 2026-09-28, G-20 / G-21 / G-22).
 */
class ReversalController extends Controller
{
    private const GROUPS = [
        'refunds' => ['refund', 'return'],
        'chargebacks' => ['chargeback'],
    ];

    public function index(Request $request, string $group): Response
    {
        Gate::authorize('reversals.view');

        $kinds = self::GROUPS[$group];
        $items = TransactionReversal::query()
            ->whereIn('kind', $kinds)
            ->with(['transaction.partner', 'transaction.branch', 'creator'])
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        $rows = [];

        foreach ($items->items() as $reversal) {
            /** @var TransactionReversal $reversal */
            $txn = $reversal->transaction;
            $rows[] = [
                'id' => $reversal->id,
                'reference' => $reversal->reference,
                'kind' => $reversal->kind,
                'bearer' => $reversal->bearer,
                'amount' => $reversal->amount,
                'reason' => $reversal->reason,
                'external_reference' => $reversal->external_reference,
                'created_by' => $reversal->creator->name,
                'created_at' => $reversal->created_at->toIso8601String(),
                'transaction' => [
                    'id' => $txn->id,
                    'reference' => $txn->reference,
                    'direction' => $txn->direction,
                    'partner' => $txn->partner->code,
                    'branch' => $txn->branch?->code,
                    'status' => $txn->status,
                ],
            ];
        }

        $find = trim((string) $request->query('find'));
        $candidate = $find === '' ? null : Transaction::query()
            ->with(['partner', 'branch', 'customer'])
            ->where('reference', strtoupper($find))
            ->first();

        return Inertia::render('admin/reversals', [
            'group' => $group,
            'items' => [...$items->toArray(), 'data' => $rows],
            'totals' => collect($kinds)->mapWithKeys(fn (string $kind) => [$kind => [
                'count' => TransactionReversal::query()->where('kind', $kind)->count(),
                'amount' => (int) TransactionReversal::query()->where('kind', $kind)->sum('amount'),
            ]]),
            'find' => $find,
            'candidate' => $candidate === null ? null : [
                'id' => $candidate->id,
                'reference' => $candidate->reference,
                'direction' => $candidate->direction,
                'status' => $candidate->status,
                'amount' => $candidate->amount,
                'partner' => "{$candidate->partner->code} · {$candidate->partner->name}",
                'branch' => $candidate->branch === null ? null : "{$candidate->branch->code} · {$candidate->branch->name}",
                'customer' => $candidate->customer->external_id ?? null,
                'succeeded_at' => $candidate->succeeded_at?->toIso8601String(),
                'partner_commission' => $candidate->partner_commission,
                'branch_commission' => $candidate->branch_commission,
                'platform_margin' => $candidate->platform_margin,
            ],
            'can' => ['create' => $request->user()?->can('reversals.create') ?? false],
        ]);
    }

    public function store(Request $request, ReverseTransaction $reverse): RedirectResponse
    {
        Gate::authorize('reversals.create');

        $data = $request->validate([
            'transaction_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['chargeback', 'refund', 'return'])],
            'bearer' => ['nullable', 'required_if:kind,chargeback', Rule::in(['partner', 'branch'])],
            'reason' => ['required', 'string', 'max:500'],
            'external_reference' => ['nullable', 'string', 'max:100'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $txn = Transaction::query()->findOrFail((string) $data['transaction_id']);
        $reversal = $reverse->handle($actor, $txn, $data['kind'], $data['reason'], $data['bearer'] ?? null, $data['external_reference'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference recorded for :txn; the partner is notified.', ['reference' => $reversal->reference, 'txn' => $txn->reference])]);

        return redirect()->route($data['kind'] === 'chargeback' ? 'admin.chargebacks.index' : 'admin.refunds.index');
    }
}
