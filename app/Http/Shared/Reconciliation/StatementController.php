<?php

namespace App\Http\Shared\Reconciliation;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\Models\ReasonCode;
use App\Domain\Reconciliation\Actions\RecordStatementEntry;
use App\Domain\Reconciliation\Enums\CaseType;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Controller;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manual A/C Statement (Admin) / A/C Statement Entry (branch): the bank
 * statement lines of branch accounts, typed in or imported, with what each
 * matched. A line linked to a waiting deposit can be approved from here
 * (the deposit queue's approve / hold routes, with the line's UTR).
 */
class StatementController extends Controller
{
    public const TABS = [
        'pending' => ['matched'],
        'approved' => ['reconciled'],
        'unsettled' => ['unmatched', 'duplicate', 'imported'],
        'ignored' => ['ignored'],
    ];

    public function __construct(private StatementPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::authorize('statements.view');

        $actor = $this->actor($request);
        $filters = $this->filters($request);
        $base = fn () => $this->filtered($actor, $filters);
        $statuses = self::TABS[(string) $filters['status']] ?? null;

        $items = $base()
            ->when($statuses !== null, fn (Builder $query) => $query->whereIn('status', (array) $statuses))
            ->with(StatementPresenter::WITH)
            ->orderByDesc('value_date')
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        $rows = [];

        foreach ($items->items() as $entry) {
            /** @var StatementEntry $entry */
            $rows[] = $this->presenter->entry($entry, $actor);
        }

        $sum = fn (array $statuses, ?string $direction = null) => (int) $base()
            ->whereIn('status', $statuses)
            ->when($direction !== null, fn (Builder $query) => $query->where('entry_direction', $direction))
            ->sum('amount');

        $selected = $request->query('line');

        return Inertia::render('statements/index', [
            'portal' => $actor->type->value,
            'items' => [...$items->toArray(), 'data' => $rows],
            'filters' => $filters,
            'counts' => [
                'all' => $base()->count(),
                ...collect(self::TABS)->map(fn (array $statuses) => $base()->whereIn('status', $statuses)->count())->all(),
            ],
            'kpis' => [
                'credit' => (int) $base()->where('entry_direction', 'credit')->sum('amount'),
                'debit' => (int) $base()->where('entry_direction', 'debit')->sum('amount'),
                'credit_lines' => $base()->where('entry_direction', 'credit')->count(),
                'debit_lines' => $base()->where('entry_direction', 'debit')->count(),
                'pending' => $sum(self::TABS['pending']),
                'unsettled' => $sum(self::TABS['unsettled']),
            ],
            'accounts' => ReconciliationScope::accounts($actor)->with('branch')->orderBy('label')->get()
                ->map(fn ($account) => [...$this->presenter->account($account), 'branch_id' => $account->branch_id, 'branch' => $account->branch->code])
                ->values(),
            'branches' => $actor->isType(UserType::Admin) ? Branch::query()->orderBy('code')->get(['id', 'code', 'name']) : [],
            'reasons' => ReasonCode::options('payin_reject'),
            'can' => [
                'create' => $actor->can('statements.create'),
                'resolve' => $actor->can('reconciliation.resolve'),
            ],
            'selected' => is_string($selected) ? $selected : null,
            'detail' => Inertia::optional(function () use ($selected, $actor) {
                $entry = is_string($selected) ? ReconciliationScope::entries($actor)->with(['transaction.partner', 'transaction.branch', 'transaction.paymentAccount', 'transaction.customer', 'cases.resolver'])->find($selected) : null;

                return $entry === null ? null : $this->presenter->entryDetail($entry, $actor);
            }),
        ]);
    }

    public function store(StatementEntryRequest $request, RecordStatementEntry $record): RedirectResponse
    {
        $account = $request->account();
        abort_if($account === null, 404);

        $entry = $record->handle(
            $this->actor($request),
            $account,
            (string) $request->validated('value_date'),
            $request->direction(),
            $request->amount(),
            $request->validated('utr'),
            $request->validated('description'),
        );

        $message = match ($entry->status) {
            'matched' => __('Line added and matched to :reference: approve it when you’re ready.', ['reference' => $entry->transaction?->reference]),
            'reconciled' => __('Line added and reconciled with :reference.', ['reference' => $entry->transaction?->reference]),
            default => __('Line added. It matches no transaction yet, so it is in the unsettled queue (:type).', [
                'type' => $entry->openCase?->type->label() ?? CaseType::ManualReview->label(),
            ]),
        };

        Inertia::flash('toast', ['type' => $entry->transaction_id === null ? 'warning' : 'success', 'message' => $message]);

        return back();
    }

    /**
     * The filtered lines as CSV (Excel-safe: cells never start a formula).
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('statements.view');

        $actor = $this->actor($request);
        $query = $this->filtered($actor, $this->filters($request))
            ->with(['paymentAccount', 'branch', 'transaction', 'openCase'])
            ->orderBy('value_date')
            ->orderBy('created_at');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            $safe = fn (?string $value) => $value !== null && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;

            fputcsv($out, ['Date', 'Branch', 'Account', 'Credit', 'Debit', 'UTR', 'Description', 'Status', 'Transaction', 'Transaction status', 'Case']);

            foreach ($query->lazy(500) as $entry) {
                /** @var StatementEntry $entry */
                fputcsv($out, [
                    $entry->value_date->toDateString(),
                    $entry->branch->code,
                    $safe($entry->paymentAccount->label),
                    $entry->isCredit() ? Money::toRupees($entry->amount) : '',
                    $entry->isCredit() ? '' : Money::toRupees($entry->amount),
                    $entry->utr_normalized,
                    $safe($entry->description),
                    $entry->status,
                    $entry->transaction?->reference,
                    $entry->transaction?->status,
                    $entry->openCase?->type->label(),
                ]);
            }

            fclose($out);
        }, 'statement-'.now(config('app.business_timezone'))->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{from: string, to: string, branch: ?string, account: ?string, status: ?string, match: ?string, search: string}
     */
    private function filters(Request $request): array
    {
        $today = CarbonImmutable::now(config('app.business_timezone'));
        $date = function (string $key, CarbonImmutable $default) use ($request): string {
            $value = $request->query($key);

            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $default->toDateString();
        };
        $text = fn (string $key) => is_string($request->query($key)) && $request->query($key) !== '' ? (string) $request->query($key) : null;

        return [
            'from' => $date('from', $today->subDays(6)),
            'to' => $date('to', $today),
            'branch' => $text('branch'),
            'account' => $text('account'),
            'status' => $text('status'),
            'match' => $text('match'),
            'search' => trim((string) $request->query('search')),
        ];
    }

    /**
     * @param  array{from: string, to: string, branch: ?string, account: ?string, status: ?string, match: ?string, search: string}  $filters
     * @return Builder<StatementEntry>
     */
    private function filtered(User $actor, array $filters): Builder
    {
        $search = $filters['search'];

        return ReconciliationScope::entries($actor)
            ->whereBetween('value_date', [$filters['from'], $filters['to']])
            ->when($filters['branch'] !== null && $actor->isType(UserType::Admin), fn (Builder $query) => $query->where('branch_id', $filters['branch']))
            ->when($filters['account'] !== null, fn (Builder $query) => $query->where('payment_account_id', $filters['account']))
            // Branch match: the line's branch vs its transaction's (linked, or pointed at by the open case).
            ->when($filters['match'] === 'matched', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNotNull('transaction_id')
                ->orWhereHas('openCase.transaction', fn (Builder $query) => $query->whereColumn('transactions.branch_id', 'statement_entries.branch_id'))))
            ->when($filters['match'] === 'mismatch', fn (Builder $query) => $query->whereHas('openCase.transaction', fn (Builder $query) => $query->whereColumn('transactions.branch_id', '!=', 'statement_entries.branch_id')))
            ->when($filters['match'] === 'none', fn (Builder $query) => $query->whereNull('transaction_id')->whereDoesntHave('openCase.transaction'))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('utr_normalized', Transaction::normaliseUtr($search))
                ->orWhere('description', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereHas('transaction', fn (Builder $query) => $query->where('reference', strtoupper($search)))));
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
