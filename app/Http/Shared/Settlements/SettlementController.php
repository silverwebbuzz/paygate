<?php

namespace App\Http\Shared\Settlements;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Platform\Settings;
use App\Domain\Settlement\Actions\CalculateSettlement;
use App\Domain\Settlement\Actions\RecordSettlementPayment;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementPayment;
use App\Http\Controller;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settlement: Admin sees and settles every party; a partner or branch sees
 * its own settlements (read-only). Admin calculates on demand and records
 * payments made outside PayGate.
 */
class SettlementController extends Controller
{
    public function __construct(private SettlementPresenter $presenter) {}

    public function index(Request $request, Settings $settings): Response
    {
        Gate::authorize('settlements.view');

        $actor = $this->actor($request);
        $isAdmin = $actor->isType(UserType::Admin);
        $tab = in_array($request->query('tab'), ['open', 'settled', 'all'], true) ? (string) $request->query('tab') : 'open';
        $party = $isAdmin && in_array($request->query('party'), ['partner', 'branch'], true) ? (string) $request->query('party') : null;
        $partyId = $isAdmin && is_string($request->query('id')) && $request->query('id') !== '' ? (string) $request->query('id') : null;

        $base = fn () => $this->scoped($actor)
            ->when($party !== null, fn (Builder $query) => $query->where('party_type', $party))
            ->when($partyId !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('partner_id', $partyId)->orWhere('branch_id', $partyId)));
        $inTab = fn (string $key) => match ($key) {
            // What still has to be paid: each party's current, unsettled settlement.
            'open' => $base()->current()->whereIn('status', ['calculated', 'partially_settled']),
            'settled' => $base()->where('status', 'settled'),
            default => $base(),
        };

        $items = $inTab($tab)
            ->with(['partner', 'branch', 'calculator'])
            ->orderByDesc('period_end')
            ->orderBy('reference')
            ->paginate(30)
            ->withQueryString();

        $current = Settlement::query()->current()->whereIn('id', collect($items->items())->pluck('id'))->pluck('id')->flip();
        $rows = [];

        foreach ($items->items() as $settlement) {
            /** @var Settlement $settlement */
            $rows[] = $this->presenter->row($settlement, $actor, $current->has($settlement->id));
        }

        $open = fn (string $direction) => $inTab('open')->where('direction', $direction);
        $selected = $request->query('settlement');

        return Inertia::render('settlements/index', [
            'portal' => $actor->type->value,
            'tab' => $tab,
            'items' => [...$items->toArray(), 'data' => $rows],
            'filters' => ['party' => $party, 'id' => $partyId],
            'counts' => collect(['open', 'settled', 'all'])->mapWithKeys(fn (string $key) => [$key => $inTab($key)->count()]),
            'kpis' => [
                // Party → platform ("to receive") and platform → party ("to pay"), still open.
                'to_receive' => (int) $open('party_to_platform')->get()->sum(fn (Settlement $settlement) => $settlement->remaining()),
                'to_pay' => (int) $open('platform_to_party')->get()->sum(fn (Settlement $settlement) => $settlement->remaining()),
                'paid_today' => $isAdmin ? (int) SettlementPayment::query()->where('created_at', '>=', CarbonImmutable::now(config('app.business_timezone'))->startOfDay()->utc())->sum('amount') : null,
            ],
            'cutoff' => $settings->settlementCutoff(),
            'parties' => $isAdmin ? [
                'partner' => Partner::query()->orderBy('code')->get(['id', 'code', 'name']),
                'branch' => Branch::query()->orderBy('code')->get(['id', 'code', 'name']),
            ] : null,
            'can' => [
                'calculate' => $isAdmin && $actor->can('settlements.create'),
                'adjustments' => $isAdmin && $actor->can('adjustments.view'),
                'settings' => $isAdmin && $actor->can('settings.view'),
            ],
            'selected' => is_string($selected) ? $selected : null,
            'detail' => Inertia::optional(function () use ($selected, $actor) {
                $settlement = is_string($selected) ? $this->scoped($actor)->with(['lines', 'payments.recorder'])->find($selected) : null;

                return $settlement === null ? null : $this->presenter->detail($settlement, $actor);
            }),
        ]);
    }

    /**
     * On demand: one party, from its last settlement up to now.
     */
    public function calculate(Request $request, CalculateSettlement $calculate): RedirectResponse
    {
        Gate::authorize('settlements.create');

        $data = $request->validate([
            'party_type' => ['required', Rule::in(['partner', 'branch'])],
            'party_id' => ['required', 'uuid', Rule::exists($request->input('party_type') === 'branch' ? 'branches' : 'partners', 'id')],
        ]);

        $settlement = $calculate->handle($data['party_type'], $data['party_id'], CarbonImmutable::now(), 'on_demand', $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference calculated.', ['reference' => $settlement?->reference])]);

        return redirect()->route($this->actor($request)->type->value.'.settlements.index', ['settlement' => $settlement?->id, 'tab' => 'all']);
    }

    public function pay(Request $request, Settlement $settlement, RecordSettlementPayment $record): RedirectResponse
    {
        Gate::authorize('settlements.update');

        $data = $request->validate([
            'amounts' => ['required', 'array'],
            'amounts.*' => ['nullable', 'string', Money::RUPEES_RULE],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $amounts = [];

        foreach ($data['amounts'] as $lineId => $rupees) {
            if (is_string($rupees) && trim($rupees) !== '') {
                $amounts[(string) $lineId] = Money::toPaise($rupees);
            }
        }

        if ($amounts === []) {
            throw ValidationException::withMessages(['amounts' => __('Enter the amount paid for at least one line.')]);
        }

        $record->handle($this->actor($request), $settlement, $amounts, $data['paid_at'], $data['reference'] ?? null, $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference: ₹:amount recorded as settled.', [
            'reference' => $settlement->reference,
            'amount' => number_format(array_sum($amounts) / 100, 2),
        ])]);

        return back();
    }

    /**
     * @return Builder<Settlement>
     */
    private function scoped(User $actor): Builder
    {
        return match ($actor->type) {
            UserType::Admin => Settlement::query(),
            UserType::Partner => Settlement::query()->where(['party_type' => 'partner', 'partner_id' => $actor->partner_id]),
            UserType::Branch => Settlement::query()->where(['party_type' => 'branch', 'branch_id' => $actor->branch_id]),
        };
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
