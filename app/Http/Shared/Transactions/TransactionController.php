<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Webhook\Actions\ResendWebhook;
use App\Domain\Webhook\Models\WebhookEvent;
use App\Http\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function __construct(private TransactionPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $direction = $request->route()?->defaults['direction'] ?? 'payin';
        $direction = $direction === 'payout' ? 'payout' : 'payin';
        Gate::authorize($direction === 'payout' ? 'payouts.view' : 'payins.view');

        $actor = $this->actor($request);
        $groups = self::STATUS_GROUPS[$direction];
        $group = array_key_exists((string) $request->query('status'), $groups) ? (string) $request->query('status') : null;
        $search = trim((string) $request->query('search'));
        $selected = $request->query('txn');

        $base = fn () => self::scoped($actor)->where('direction', $direction);

        $transactions = $base()
            ->with(['partner', 'branch', 'paymentAccount', 'customer', 'beneficiary', 'statementEntry'])
            ->when($group !== null, fn (Builder $query) => $query->whereIn('status', $groups[(string) $group] ?? []))
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
            'filters' => ['status' => $group, 'search' => $search],
            'summary' => collect($groups)->map(fn (array $statuses) => [
                'count' => (int) $counts->whereIn('status', $statuses)->sum('total'),
                'amount' => (int) $counts->whereIn('status', $statuses)->sum('amount'),
            ]),
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
