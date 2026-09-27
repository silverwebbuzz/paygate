<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Payout\Actions\ProcessPayout;
use App\Domain\Platform\Models\ReasonCode;
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
 * Manual Payout: withdrawals waiting for a branch to pay. The branch sees
 * the customer's full bank / UPI details while the payout is open (it has
 * to make the transfer), then marks it paid with the UTR or failed. Admin
 * sees every branch's queue and can move a waiting payout elsewhere.
 */
class PayoutQueueController extends Controller
{
    // "To pay" keeps payouts the operator has started, so they stay in view
    // until marked paid or failed; "Being paid" narrows to those.
    public const TABS = [
        'to_pay' => ['assigned', 'processing'],
        'paying' => ['processing'],
        'paid' => ['success'],
        'failed' => ['failed'],
    ];

    public function __construct(private TransactionPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::authorize('payouts.view');

        /** @var User $actor */
        $actor = $request->user();
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'to_pay';
        $search = trim((string) $request->query('search'));
        $today = CarbonImmutable::now(config('app.business_timezone'))->startOfDay()->utc();

        $inTab = fn (string $key) => TransactionController::scoped($actor)
            ->where('direction', 'payout')
            ->whereIn('status', self::TABS[$key])
            ->when(in_array($key, ['paid', 'failed'], true), fn (Builder $query) => $query->where('decided_at', '>=', $today));

        $items = $inTab($tab)
            ->with(['partner', 'branch', 'customer', 'beneficiary'])
            ->when($search !== '', fn (Builder $query) => TransactionController::search($query, $search))
            ->orderBy(in_array($tab, ['paid', 'failed'], true) ? 'decided_at' : 'created_at', in_array($tab, ['paid', 'failed'], true) ? 'desc' : 'asc')
            ->paginate(30)
            ->withQueryString();

        $rows = [];

        foreach ($items->items() as $payout) {
            /** @var Transaction $payout */
            $rows[] = [
                ...$this->presenter->row($payout, $actor),
                // Full details only while the viewer has to pay it.
                'pay_to' => in_array($payout->status, ['assigned', 'processing'], true) && $actor->can('process', $payout) && $payout->beneficiary ? [
                    'type' => $payout->beneficiary->type,
                    'name' => $payout->beneficiary->account_holder_name,
                    'account_number' => $payout->beneficiary->account_number_encrypted,
                    'ifsc' => $payout->beneficiary->ifsc,
                    'bank_name' => $payout->beneficiary->bank_name,
                    'upi_id' => $payout->beneficiary->upi_id_encrypted,
                ] : null,
            ];
        }

        $selected = $request->query('txn');

        return Inertia::render('payouts/index', [
            'portal' => $actor->type->value,
            'tab' => $tab,
            'tabs' => collect(array_keys(self::TABS))->mapWithKeys(fn (string $key) => [$key => [
                'count' => $inTab($key)->count(),
                'amount' => (int) $inTab($key)->sum('amount'),
            ]]),
            'items' => [...$items->toArray(), 'data' => $rows],
            'filters' => ['search' => $search],
            'reasons' => ReasonCode::options('payout_reject'),
            'selected' => is_string($selected) ? $selected : null,
            'detail' => Inertia::optional(function () use ($selected, $actor) {
                $txn = is_string($selected) ? Transaction::query()->find($selected) : null;

                return $txn !== null && $actor->can('view', $txn) ? $this->presenter->detail($txn, $actor) : null;
            }),
            'branches' => $actor->isType(UserType::Admin)
                ? Branch::query()->where('status', 'active')->where('is_withdrawal_enabled', true)->orderBy('code')->get(['id', 'code', 'name'])
                : [],
        ]);
    }

    public function start(Request $request, Transaction $transaction, ProcessPayout $process): RedirectResponse
    {
        $process->start($this->authorizeProcess($request, $transaction), $transaction);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference: you are paying it now.', ['reference' => $transaction->reference])]);

        return back();
    }

    public function complete(Request $request, Transaction $transaction, ProcessPayout $process): RedirectResponse
    {
        $actor = $this->authorizeProcess($request, $transaction);
        $data = $request->validate(['bank_utr' => ['required', 'string', 'max:50'], 'note' => ['nullable', 'string', 'max:500']]);

        $process->complete($actor, $transaction, $data['bank_utr'], $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference paid · ₹:amount.', ['reference' => $transaction->reference, 'amount' => number_format($transaction->amount / 100, 2)])]);

        return back();
    }

    public function fail(Request $request, Transaction $transaction, ProcessPayout $process): RedirectResponse
    {
        $actor = $this->authorizeProcess($request, $transaction);
        $data = $request->validate([
            'reason_code' => ['required', Rule::in(array_keys(ReasonCode::options('payout_reject')))],
            'note' => ['nullable', 'required_if:reason_code,other', 'string', 'max:500'],
        ]);

        $process->fail($actor, $transaction, $data['reason_code'], $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference marked failed; the partner is notified and the balance released.', ['reference' => $transaction->reference])]);

        return back();
    }

    public function reassign(Request $request, Transaction $transaction, ProcessPayout $process): RedirectResponse
    {
        $actor = $this->authorizeProcess($request, $transaction);
        abort_unless($actor->isType(UserType::Admin), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'uuid', Rule::exists('partner_branch_mappings', 'branch_id')->where('partner_id', $transaction->partner_id)],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $branch = Branch::query()->findOrFail((string) $data['branch_id']);
        $process->reassign($actor, $transaction, $branch, $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference moved to :branch.', ['reference' => $transaction->reference, 'branch' => $branch->code])]);

        return back();
    }

    private function authorizeProcess(Request $request, Transaction $transaction): User
    {
        /** @var User $actor */
        $actor = $request->user();

        Gate::authorize('process', $transaction);

        return $actor;
    }
}
