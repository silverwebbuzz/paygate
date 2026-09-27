<?php

namespace App\Http\Shared\Reconciliation;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Reconciliation\Actions\ResolveCase;
use App\Domain\Reconciliation\Enums\CaseType;
use App\Domain\Reconciliation\Enums\Resolution;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Controller;
use App\Http\Shared\Transactions\TransactionController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unsettled UTR (Admin) / Deposit Unsettled (branch): bank statement lines
 * that didn't match cleanly, one case each, until someone resolves it:
 * link it to its transaction, approve a late payment (Admin), or close it as
 * not a customer payment / returned to the customer.
 */
class CaseController extends Controller
{
    public function __construct(private StatementPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::authorize('reconciliation.view');

        $actor = $this->actor($request);
        $tab = $request->query('tab') === 'resolved' ? 'resolved' : 'open';
        $type = CaseType::tryFrom((string) $request->query('type'));
        $branch = $request->query('branch');
        $search = trim((string) $request->query('search'));

        $base = fn () => ReconciliationScope::cases($actor)
            ->when(is_string($branch) && $branch !== '' && $actor->isType(UserType::Admin), fn (Builder $query) => $query->where('branch_id', $branch))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', strtoupper($search))
                ->orWhereHas('entry', fn (Builder $query) => $query->where('utr_normalized', Transaction::normaliseUtr($search)))
                ->orWhereHas('transaction', fn (Builder $query) => $query->where('reference', strtoupper($search)))));
        $inTab = fn (string $key) => $key === 'open' ? $base()->where('status', '!=', 'resolved') : $base()->where('status', 'resolved');

        $cases = $inTab($tab)
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type->value))
            ->with(['branch', 'resolver', 'transaction', ...array_map(fn (string $relation) => 'entry.'.$relation, StatementPresenter::WITH)])
            ->orderBy($tab === 'open' ? 'created_at' : 'resolved_at', $tab === 'open' ? 'asc' : 'desc')
            ->paginate(30)
            ->withQueryString();

        $rows = [];

        foreach ($cases->items() as $case) {
            /** @var ReconciliationCase $case */
            $rows[] = $this->presenter->caseRow($case, $actor);
        }

        // Bank lines waiting in open cases (KPIs).
        $waiting = fn (string $direction) => ReconciliationScope::entries($actor)
            ->when(is_string($branch) && $branch !== '' && $actor->isType(UserType::Admin), fn (Builder $query) => $query->where('branch_id', $branch))
            ->where('entry_direction', $direction)
            ->whereHas('openCase');
        $selected = $request->query('case');

        return Inertia::render('reconciliation/cases', [
            'portal' => $actor->type->value,
            'tab' => $tab,
            'cases' => [...$cases->toArray(), 'data' => $rows],
            'filters' => ['type' => $type?->value, 'branch' => is_string($branch) ? $branch : null, 'search' => $search],
            'counts' => [
                'open' => $inTab('open')->count(),
                'resolved' => $inTab('resolved')->count(),
                'types' => $inTab('open')->toBase()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type'),
            ],
            'kpis' => [
                'credit' => (int) $waiting('credit')->sum('amount'),
                'credit_count' => $waiting('credit')->count(),
                'debit' => (int) $waiting('debit')->sum('amount'),
                'debit_count' => $waiting('debit')->count(),
                'resolved_today' => $inTab('resolved')->where('resolved_at', '>=', now(config('app.business_timezone'))->startOfDay()->utc())->count(),
            ],
            'types' => collect(CaseType::cases())->mapWithKeys(fn (CaseType $type) => [$type->value => $type->label()]),
            'branches' => $actor->isType(UserType::Admin) ? Branch::query()->orderBy('code')->get(['id', 'code', 'name']) : [],
            'selected' => is_string($selected) ? $selected : null,
            // Transactions the open case's bank line could be linked to.
            'candidates' => Inertia::optional(function () use ($selected, $actor, $request) {
                $case = is_string($selected) ? ReconciliationScope::cases($actor)->with('entry')->find($selected) : null;
                $find = $request->query('find');

                return $case === null ? [] : $this->presenter->candidates($case, $actor, is_string($find) ? $find : null);
            }),
        ]);
    }

    public function link(Request $request, ReconciliationCase $case, ResolveCase $resolve): RedirectResponse
    {
        $actor = $this->authorizeResolve($request, $case);
        $data = $request->validate([
            'transaction_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $txn = TransactionController::scoped($actor)->findOrFail((string) $data['transaction_id']);
        $resolve->link($actor, $case, $txn, $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':case resolved: linked to :reference.', ['case' => $case->reference, 'reference' => $txn->reference])]);

        return back();
    }

    public function approveLate(Request $request, ReconciliationCase $case, ResolveCase $resolve): RedirectResponse
    {
        $actor = $this->authorizeResolve($request, $case);
        Gate::authorize('payins.approve');
        abort_unless($actor->isType(UserType::Admin), 403);

        $data = $request->validate([
            'transaction_id' => ['required', 'uuid'],
            'bank_utr' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $txn = Transaction::query()->findOrFail((string) $data['transaction_id']);
        $resolve->approveLate($actor, $case, $txn, $data['bank_utr'] ?? null, $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference approved late; the partner is credited and notified.', ['reference' => $txn->reference])]);

        return back();
    }

    public function close(Request $request, ReconciliationCase $case, ResolveCase $resolve): RedirectResponse
    {
        $actor = $this->authorizeResolve($request, $case);
        $data = $request->validate([
            'resolution' => ['required', Rule::in([Resolution::Rejected->value, Resolution::Refunded->value])],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $resolve->close($actor, $case, Resolution::from($data['resolution']), $data['note']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':case closed: :resolution.', ['case' => $case->reference, 'resolution' => Resolution::from($data['resolution'])->label()])]);

        return back();
    }

    private function authorizeResolve(Request $request, ReconciliationCase $case): User
    {
        $actor = $this->actor($request);

        Gate::authorize('reconciliation.resolve');
        abort_unless(ReconciliationScope::allows($actor, $case->branch_id), 404);

        return $actor;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
