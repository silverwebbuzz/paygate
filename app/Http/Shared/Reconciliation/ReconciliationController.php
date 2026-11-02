<?php

namespace App\Http\Shared\Reconciliation;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Reporting\Period;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Controller;
use App\Http\Shared\Transactions\TransactionController;
use App\Http\Shared\Transactions\TransactionPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * UTR Reconciliation: the transaction side of matching. Every approved
 * deposit and paid payout, and whether a bank statement line confirms it.
 * "Not in a statement" lists approvals with no bank line yet: either the
 * statement isn't entered, or the approval needs a second look.
 */
class ReconciliationController extends Controller
{
    public function __construct(private TransactionPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::authorize('reconciliation.view');

        /** @var User $actor */
        $actor = $request->user();
        $direction = $request->query('direction') === 'payout' ? 'payout' : 'payin';
        $tab = $request->query('tab') === 'matched' ? 'matched' : 'missing';
        $branch = $request->query('branch');
        $search = trim((string) $request->query('search'));
        // Succeeded in the period (Today by default).
        $period = Period::fromRequest($request);

        $base = fn () => TransactionController::scoped($actor)
            ->where(['direction' => $direction, 'status' => 'success'])
            ->where(fn (Builder $query) => $period->apply($query, 'succeeded_at'))
            ->when(is_string($branch) && $branch !== '' && $actor->isType(UserType::Admin), fn (Builder $query) => $query->where('branch_id', $branch))
            ->when($search !== '', fn (Builder $query) => TransactionController::search($query, $search));
        $inTab = fn (string $key) => $key === 'matched' ? $base()->whereHas('statementEntry') : $base()->whereDoesntHave('statementEntry');

        $items = $inTab($tab)
            ->with(['partner', 'branch', 'paymentAccount', 'customer', 'beneficiary', 'statementEntry'])
            ->orderByDesc('succeeded_at')
            ->paginate(30)
            ->withQueryString();

        $rows = [];

        foreach ($items->items() as $txn) {
            /** @var Transaction $txn */
            $rows[] = $this->presenter->row($txn, $actor);
        }

        $selected = $request->query('txn');

        return Inertia::render('reconciliation/index', [
            'portal' => $actor->type->value,
            'direction' => $direction,
            'tab' => $tab,
            'items' => [...$items->toArray(), 'data' => $rows],
            'filters' => ['branch' => is_string($branch) ? $branch : null, 'search' => $search],
            'period' => $period->toArray(),
            'summary' => collect(['all' => $base(), 'matched' => $inTab('matched'), 'missing' => $inTab('missing')])
                ->map(fn (Builder $query) => ['count' => (clone $query)->count(), 'amount' => (int) $query->sum('amount')]),
            'open_cases' => ReconciliationScope::cases($actor)->where('status', '!=', 'resolved')->count(),
            'branches' => $actor->isType(UserType::Admin) ? Branch::query()->orderBy('code')->get(['id', 'code', 'name']) : [],
            'selected' => is_string($selected) ? $selected : null,
            'detail' => Inertia::optional(function () use ($selected, $actor) {
                $txn = is_string($selected) ? Transaction::query()->find($selected) : null;

                return $txn !== null && $actor->can('view', $txn) ? $this->presenter->detail($txn, $actor) : null;
            }),
        ]);
    }
}
