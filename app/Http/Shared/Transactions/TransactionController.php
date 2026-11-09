<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Platform\SectionRollout;
use App\Domain\Reporting\Period;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Webhook\Actions\ResendWebhook;
use App\Domain\Webhook\Models\WebhookEvent;
use App\Http\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pay-in lists: Admin › Transactions (all), Partner › Pay-in (own),
 * Branch › Pay-in history (paid into its accounts). Same page, scoped and
 * trimmed per portal by TransactionPresenter.
 */
class TransactionController extends Controller
{
    /**
     * What TransactionPresenter::row() reads, loaded up front.
     */
    public const ROW_RELATIONS = ['partner', 'branch', 'paymentAccount', 'customer', 'beneficiary', 'statementEntry', 'decider', 'session', 'latestProof', 'currentChargeback.requester', 'currentReturn.requester', 'currentReturn.decider', 'payoutPayments.proof', 'payoutPayments.creator', 'payoutPayments.statementEntry', 'payoutPayments.approvedReturn'];

    public const STATUS_GROUPS = [
        'payin' => [
            'open' => ['created', 'awaiting_payment'],
            'pending' => ['payment_submitted', 'payment_detected'],
            'hold' => ['under_review'],
            'success' => ['success'],
            'rejected' => ['rejected'],
            'closed' => ['expired', 'cancelled'],
        ],
        'payout' => [
            'pending' => ['assigned'],
            'hold' => ['processing'],
            'success' => ['success'],
            'rejected' => ['failed', 'rejected', 'returned'],
            'closed' => ['cancelled'],
        ],
    ];

    /**
     * The Section rollout item that owns each extra tab (under the portal).
     */
    public const SECTION_PATHS = ['chargeback' => 'chargebacks', 'return' => 'payout-returns'];

    public function __construct(private TransactionPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $direction = $request->route()?->defaults['direction'] ?? 'payin';
        $direction = $direction === 'payout' ? 'payout' : 'payin';
        Gate::authorize($direction === 'payout' ? 'payouts.view' : 'payins.view');

        $actor = $this->actor($request);
        $groups = self::STATUS_GROUPS[$direction];
        $chargebacks = $direction === 'payin';
        // The extra tab beside the statuses: chargebacks (pay-ins) or returns
        // (payouts), while its section is open (Section rollout). The
        // Chargebacks / Payout returns pages are this list fixed on that tab.
        $page = $request->route()?->defaults['group'] ?? null;
        $extra = $chargebacks ? 'chargeback' : 'return';
        $extra = app(SectionRollout::class)->allows($actor, $actor->type->value.'/'.self::SECTION_PATHS[$extra]) ? $extra : null;
        $status = $page ?? (string) $request->query('status');
        $group = array_key_exists($status, $groups) || ($extra !== null && $status === $extra) ? $status : null;
        $search = trim((string) $request->query('search'));
        $selected = $request->query('txn');

        $filters = self::filters($request, $actor);
        // Created in the period (Today by default). The Chargebacks and
        // Payout returns pages are work queues: every open case, any date.
        $period = $page === null ? Period::fromRequest($request) : null;

        $base = fn () => self::filtered(self::scoped($actor)->where('direction', $direction), $filters)
            ->when($period !== null, fn (Builder $query) => $period->apply($query, 'created_at'));

        $transactions = $base()
            ->with(self::ROW_RELATIONS)
            ->when($group === 'chargeback', fn (Builder $query) => self::withChargeback($query, $actor))
            ->when($group === 'return', fn (Builder $query) => $query->whereNotNull('return_status'))
            ->when($group !== null && $group !== 'chargeback' && $group !== 'return', fn (Builder $query) => $query->whereIn('status', $groups[(string) $group] ?? []))
            ->when($search !== '', fn (Builder $query) => self::search($query, $search))
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        $counts = $base()->toBase()->selectRaw('status, count(*) as total, sum(amount) as amount')->groupBy('status')->get();

        return Inertia::render('transactions/index', [
            'portal' => $actor->type->value,
            'direction' => $direction,
            'transactions' => [
                ...$transactions->toArray(),
                'data' => $transactions->getCollection()->map(fn (Transaction $txn) => $this->presenter->row($txn, $actor))->values(),
            ],
            'filters' => ['status' => $group, 'search' => $search, ...$filters],
            'period' => $period?->toArray(),
            ...self::filterOptions($actor),
            'summary' => collect($groups)->map(fn (array $statuses) => [
                'count' => (int) $counts->whereIn('status', $statuses)->sum('total'),
                'amount' => (int) $counts->whereIn('status', $statuses)->sum('amount'),
            ])->when($extra !== null, fn ($summary) => $summary->put((string) $extra, $chargebacks ? self::chargebackSummary(self::withChargeback($base(), $actor)) : self::returnSummary($base()->whereNotNull('return_status')))),
            'page' => $page,
            // All deposits / All withdrawals tab (Chargebacks and Payout returns stay pages of their own).
            'hub' => $page === null ? fn () => PaymentHub::props($actor, $direction === 'payout' ? PaymentHub::WITHDRAWAL : PaymentHub::DEPOSIT) : null,
            'selected' => is_string($selected) ? $selected : null,
            'detail' => Inertia::optional(function () use ($selected, $actor) {
                $txn = is_string($selected) ? Transaction::query()->find($selected) : null;

                return $txn !== null && $actor->can('view', $txn) ? $this->presenter->detail($txn, $actor) : null;
            }),
        ]);
    }

    public function resendWebhook(Request $request, WebhookEvent $event, ResendWebhook $resend): RedirectResponse
    {
        Gate::authorize('webhooks.update');

        $actor = $this->actor($request);
        abort_unless($actor->isType(UserType::Admin) || $event->partner_id === $actor->partner_id, 404);

        $resend->handle($actor, $event);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook :type queued to send again.', ['type' => $event->event_type])]);

        return back();
    }

    /**
     * Partner and branches (Admin only). The period is separate (Period).
     *
     * @return array{partner: ?string, branches: list<string>}
     */
    public static function filters(Request $request, User $actor): array
    {
        $partner = $request->query('partner');
        $branches = $actor->isType(UserType::Admin) ? array_values(array_filter((array) $request->query('branches', []), 'is_string')) : [];

        return [
            'partner' => is_string($partner) && $partner !== '' && ! $actor->isType(UserType::Partner) ? $partner : null,
            'branches' => $branches,
        ];
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  array{partner: ?string, branches: list<string>}  $filters
     * @return Builder<Transaction>
     */
    public static function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['partner'] !== null, fn (Builder $query) => $query->where('partner_id', $filters['partner']))
            ->when($filters['branches'] !== [], fn (Builder $query) => $query->whereIn('branch_id', $filters['branches']));
    }

    /**
     * The partner and branch choices for the filters.
     *
     * @return array{partners: mixed, branch_options: mixed}
     */
    public static function filterOptions(User $actor): array
    {
        return [
            'partners' => match ($actor->type) {
                UserType::Admin => Partner::query()->orderBy('name')->get(['id', 'code', 'name']),
                UserType::Branch => Partner::query()->whereIn('id', self::scoped($actor)->select('partner_id'))->orderBy('name')->get(['id', 'code', 'name']),
                UserType::Partner => [],
            },
            'branch_options' => $actor->isType(UserType::Admin) ? Branch::query()->orderBy('name')->get(['id', 'code', 'name']) : [],
        ];
    }

    /**
     * Deposits with a chargeback (partners: approved ones only).
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    private static function withChargeback(Builder $query, User $actor): Builder
    {
        return $actor->isType(UserType::Partner)
            ? $query->where('chargeback_status', 'approved')
            : $query->whereNotNull('chargeback_status');
    }

    /**
     * Count of deposits with a chargeback; amount = what was approved.
     *
     * @param  Builder<Transaction>  $query
     * @return array{count: int, amount: int, pending: int}
     */
    private static function chargebackSummary(Builder $query): array
    {
        $row = $query->toBase()->first([
            DB::raw('COUNT(*) AS total'),
            DB::raw("COALESCE(SUM((SELECT SUM(cb.amount) FROM chargebacks cb WHERE cb.transaction_id = transactions.id AND cb.status = 'approved')), 0) AS amount"),
            DB::raw("COUNT(*) FILTER (WHERE chargeback_status = 'requested') AS pending"),
        ]);

        return ['count' => (int) ($row->total ?? 0), 'amount' => (int) ($row->amount ?? 0), 'pending' => (int) ($row->pending ?? 0)];
    }

    /**
     * Count of payouts with a return; amount = what came back (confirmed).
     * A payout paid in parts may have one confirmed return per transfer.
     *
     * @param  Builder<Transaction>  $query
     * @return array{count: int, amount: int, pending: int}
     */
    private static function returnSummary(Builder $query): array
    {
        $row = $query->toBase()->first([
            DB::raw('COUNT(*) AS total'),
            DB::raw("COALESCE(SUM((SELECT SUM(pr.amount) FROM payout_returns pr WHERE pr.transaction_id = transactions.id AND pr.status = 'approved')), 0) AS amount"),
            DB::raw("COUNT(*) FILTER (WHERE return_status = 'requested') AS pending"),
        ]);

        return ['count' => (int) ($row->total ?? 0), 'amount' => (int) ($row->amount ?? 0), 'pending' => (int) ($row->pending ?? 0)];
    }

    /**
     * @return Builder<Transaction>
     */
    public static function scoped(User $actor): Builder
    {
        return match ($actor->type) {
            UserType::Admin => Transaction::query(),
            UserType::Partner => Transaction::query()->where('partner_id', $actor->partner_id),
            UserType::Branch => Transaction::query()->where('branch_id', $actor->branch_id),
        };
    }

    /**
     * Our id, the partner's order id, either UTR, or the customer id/mobile.
     *
     * @param  Builder<Transaction>  $query
     */
    public static function search(Builder $query, string $search): void
    {
        $utr = Transaction::normaliseUtr($search);

        $query->where(fn (Builder $query) => $query
            ->where('reference', strtoupper($search))
            ->orWhere('partner_transaction_id', $search)
            ->orWhere('customer_utr_normalized', $utr)
            ->orWhere('bank_utr_normalized', $utr)
            ->orWhereHas('customer', fn (Builder $query) => $query->where('external_id', $search)->orWhere('mobile', $search)));
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
