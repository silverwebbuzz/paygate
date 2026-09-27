<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Platform\Models\ReasonCode;
use App\Domain\Transaction\Actions\DecidePayin;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manual Deposit (design: cards / dense list): the pay-ins customers say they
 * paid, for the branch to check in its bank and approve with the bank UTR,
 * hold, or decline. Branches see those paid into their accounts; Admin sees
 * every branch.
 */
class DepositQueueController extends Controller
{
    public const TABS = [
        'pending' => ['payment_submitted', 'payment_detected'],
        'hold' => ['under_review'],
        'awaiting' => ['awaiting_payment'],
        'approved' => ['success'],
        'declined' => ['rejected'],
    ];

    public function __construct(private TransactionPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::authorize('payins.view');

        /** @var User $actor */
        $actor = $request->user();
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'pending';
        $partnerId = $request->query('partner');
        $search = trim((string) $request->query('search'));
        $today = CarbonImmutable::now(config('app.business_timezone'))->startOfDay()->utc();

        $base = fn () => TransactionController::scoped($actor)
            ->where('direction', 'payin')
            ->when(is_string($partnerId) && $partnerId !== '', fn (Builder $query) => $query->where('partner_id', $partnerId));

        // Approved / declined tabs show today's decisions (India time).
        $inTab = fn (Builder $query, string $key) => $query
            ->whereIn('status', self::TABS[$key])
            ->when(in_array($key, ['approved', 'declined'], true), fn (Builder $query) => $query->where('decided_at', '>=', $today));

        $items = $inTab($base(), $tab)
            ->with(['partner', 'branch', 'paymentAccount', 'customer', 'statementEntry'])
            ->when($search !== '', fn (Builder $query) => TransactionController::search($query, $search))
            // Oldest first while waiting (fairness), newest first once decided.
            ->orderBy(in_array($tab, ['approved', 'declined'], true) ? 'decided_at' : 'submitted_at', in_array($tab, ['approved', 'declined'], true) ? 'desc' : 'asc')
            ->orderBy('created_at')
            ->paginate(30)
            ->withQueryString();

        $selected = $request->query('txn');

        return Inertia::render('deposits/index', [
            'portal' => $actor->type->value,
            'tab' => $tab,
            'tabs' => collect(array_keys(self::TABS))->mapWithKeys(fn (string $key) => [$key => [
                'count' => $inTab($base(), $key)->count(),
                'amount' => (int) $inTab($base(), $key)->sum('amount'),
            ]]),
            'items' => [
                ...$items->toArray(),
                'data' => $this->rows($items->items(), $actor),
            ],
            'filters' => ['partner' => $partnerId, 'search' => $search],
            'partners' => $actor->isType(UserType::Admin)
                ? Partner::query()->orderBy('code')->get(['id', 'code', 'name'])
                : Partner::query()->whereIn('id', TransactionController::scoped($actor)->select('partner_id'))->orderBy('code')->get(['id', 'code', 'name']),
            'reasons' => ReasonCode::options('payin_reject'),
            'selected' => is_string($selected) ? $selected : null,
            'detail' => Inertia::optional(function () use ($selected, $actor) {
                $txn = is_string($selected) ? Transaction::query()->find($selected) : null;

                return $txn !== null && $actor->can('view', $txn) ? $this->presenter->detail($txn, $actor) : null;
            }),
        ]);
    }

    public function approve(Request $request, Transaction $transaction, DecidePayin $decide): RedirectResponse
    {
        $actor = $this->authorizeDecision($request, $transaction);
        $data = $request->validate([
            'bank_utr' => ['required', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $decide->approve($actor, $transaction, $data['bank_utr'], $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference approved · ₹:amount.', [
            'reference' => $transaction->reference,
            'amount' => number_format($transaction->amount / 100, 2),
        ])]);

        return back();
    }

    public function hold(Request $request, Transaction $transaction, DecidePayin $decide): RedirectResponse
    {
        $actor = $this->authorizeDecision($request, $transaction);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $decide->hold($actor, $transaction, $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference is on hold.', ['reference' => $transaction->reference])]);

        return back();
    }

    public function decline(Request $request, Transaction $transaction, DecidePayin $decide): RedirectResponse
    {
        $actor = $this->authorizeDecision($request, $transaction);
        $data = $request->validate([
            'reason_code' => ['required', Rule::in(array_keys(ReasonCode::options('payin_reject')))],
            'note' => ['nullable', 'required_if:reason_code,other', 'string', 'max:500'],
        ]);

        $decide->decline($actor, $transaction, $data['reason_code'], $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference declined; the partner is notified.', ['reference' => $transaction->reference])]);

        return back();
    }

    private function authorizeDecision(Request $request, Transaction $transaction): User
    {
        /** @var User $actor */
        $actor = $request->user();

        Gate::authorize('decide', $transaction);

        return $actor;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function rows(array $items, User $actor): array
    {
        $rows = [];

        foreach ($items as $txn) {
            if ($txn instanceof Transaction) {
                $rows[] = $this->presenter->row($txn, $actor);
            }
        }

        return $rows;
    }
}
