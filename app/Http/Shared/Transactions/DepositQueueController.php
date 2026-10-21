<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Platform\Models\ReasonCode;
use App\Domain\Reconciliation\Actions\AutoApproveDeposit;
use App\Domain\Transaction\Actions\DecidePayin;
use App\Domain\Transaction\Actions\MovePayin;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Utr;
use App\Http\Controller;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $filters = TransactionController::filters($request, $actor);
        $search = trim((string) $request->query('search'));
        $today = CarbonImmutable::now(config('app.business_timezone'))->startOfDay()->utc();
        $dated = $filters['from'] !== null || $filters['to'] !== null;

        $base = fn () => TransactionController::filtered(TransactionController::scoped($actor)->where('direction', 'payin'), $filters);

        // Approved / declined tabs show today's decisions (India time),
        // unless a date range is chosen.
        $inTab = fn (Builder $query, string $key) => $query
            ->whereIn('status', self::TABS[$key])
            ->when(! $dated && in_array($key, ['approved', 'declined'], true), fn (Builder $query) => $query->where('decided_at', '>=', $today));

        $items = $inTab($base(), $tab)
            ->with(TransactionController::ROW_RELATIONS)
            ->when($search !== '', fn (Builder $query) => TransactionController::search($query, $search))
            ->when(in_array($tab, ['approved', 'declined'], true), fn (Builder $query) => $query->orderByDesc('decided_at'))
            ->orderByDesc('created_at')
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
            'filters' => ['search' => $search, ...$filters],
            ...TransactionController::filterOptions($actor),
            'reasons' => ReasonCode::options('payin_reject'),
            'selected' => is_string($selected) ? $selected : null,
            // Admin's "Move to branch" dialog, loaded when it opens (?move=<id>).
            'move_options' => Inertia::optional(function () use ($request, $actor) {
                $txn = $actor->isType(UserType::Admin) && is_string($request->query('move')) ? Transaction::query()->find($request->query('move')) : null;

                return $txn === null ? [] : $this->moveOptions($txn);
            }),
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
            'received_amount' => ['nullable', 'string', Money::RUPEES_RULE],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $decide->approve($actor, $transaction, $data['bank_utr'], $data['note'] ?? null, Money::toPaiseOrNull($data['received_amount'] ?? null));

        Inertia::flash('toast', ['type' => 'success', 'message' => $transaction->order_amount === null
            ? __(':reference approved · ₹:amount.', [
                'reference' => $transaction->reference,
                'amount' => number_format($transaction->amount / 100, 2),
            ])
            : __(':reference approved · ₹:amount of ₹:order ordered.', [
                'reference' => $transaction->reference,
                'amount' => number_format($transaction->amount / 100, 2),
                'order' => number_format($transaction->order_amount / 100, 2),
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

    /**
     * The branch can't find the money: on hold, and Admin is asked to move it.
     */
    public function notInBank(Request $request, Transaction $transaction, DecidePayin $decide): RedirectResponse
    {
        $actor = $this->authorizeDecision($request, $transaction);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $decide->notInBank($actor, $transaction, $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference is on hold; Admin will move it to the branch that received the money.', ['reference' => $transaction->reference])]);

        return back();
    }

    /**
     * Admin: move a waiting deposit to the account the money really reached.
     */
    public function move(Request $request, Transaction $transaction, MovePayin $move, AutoApproveDeposit $autoApprove): RedirectResponse
    {
        $actor = $this->authorizeDecision($request, $transaction);
        abort_unless($actor->isType(UserType::Admin), 403);

        $data = $request->validate([
            'payment_account_id' => ['required', 'uuid', Rule::exists('payment_accounts', 'id')],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $account = PaymentAccount::query()->with('branch')->findOrFail((string) $data['payment_account_id']);
        // The new branch's statement may already show it: then it is approved at once.
        $approved = DB::transaction(fn () => $autoApprove->forPayin($move->handle($actor, $transaction, $account, $data['reason'])));

        Inertia::flash('toast', ['type' => 'success', 'message' => $approved !== null
            ? __(':reference moved to :branch · :account and approved automatically: its bank line is already there.', [
                'reference' => $transaction->reference,
                'branch' => $account->branch->code,
                'account' => $account->label,
            ])
            : __(':reference moved to :branch · :account; the branch is alerted.', [
                'reference' => $transaction->reference,
                'branch' => $account->branch->code,
                'account' => $account->label,
            ])]);

        return back();
    }

    /**
     * The accounts a deposit can be moved to: those of every branch mapped
     * to its partner, with the branch.
     *
     * @return array<int, array{id: string, label: string, bank: ?string, number: ?string, upi: ?string, status: string, branch: array{id: string, code: string, name: string}}>
     */
    private function moveOptions(Transaction $payin): array
    {
        return PaymentAccount::query()
            ->whereIn('branch_id', PartnerBranchMapping::query()->where('partner_id', $payin->partner_id)->select('branch_id'))
            ->whereKeyNot((string) $payin->payment_account_id)
            ->with('branch')
            ->get()
            ->sortBy(fn (PaymentAccount $account) => $account->branch->code.' '.$account->label)
            ->map(fn (PaymentAccount $account) => [
                'id' => $account->id,
                'label' => $account->label,
                'bank' => $account->bank_name,
                'number' => $account->maskedAccountNumber(),
                'upi' => $account->maskedUpiId(),
                'status' => $account->status->value,
                'branch' => ['id' => $account->branch->id, 'code' => $account->branch->code, 'name' => $account->branch->name],
            ])
            ->values()
            ->all();
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
        $shared = $this->sharedClaims($items);

        foreach ($items as $txn) {
            if ($txn instanceof Transaction) {
                $rows[] = [
                    ...$this->presenter->row($txn, $actor),
                    // Other waiting deposits claiming the same UTR (one query for the page).
                    'utr_shared_with' => array_values(array_map(
                        fn (array $claim) => $claim['reference'],
                        array_filter($shared[(string) $txn->customer_utr_normalized] ?? [], fn (array $claim) => $claim['id'] !== $txn->id),
                    )),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<string, list<array{id: string, reference: string}>>
     */
    private function sharedClaims(array $items): array
    {
        $utrs = [];

        foreach ($items as $txn) {
            if ($txn instanceof Transaction && $txn->customer_utr_normalized !== null) {
                $utrs[$txn->customer_utr_normalized] = true;
            }
        }

        if ($utrs === []) {
            return [];
        }

        $claims = [];

        foreach (Transaction::query()
            ->where('direction', 'payin')
            ->whereIn('customer_utr_normalized', array_keys($utrs))
            ->whereIn('status', Utr::CLAIMING)
            ->get(['id', 'reference', 'customer_utr_normalized']) as $claim) {
            $claims[(string) $claim->customer_utr_normalized][] = ['id' => $claim->id, 'reference' => $claim->reference];
        }

        return $claims;
    }
}
