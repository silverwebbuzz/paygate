<?php

namespace Tests\Feature\Settlements;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Ledger\Ledger;
use App\Domain\Platform\Settings;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Settlement\Actions\CalculateSettlement;
use App\Domain\Settlement\Models\Adjustment;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $admin;

    private User $checker;

    private User $operator;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->buildNetwork();
        $this->activeAccount();
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
        $this->checker = User::factory()->admin()->withTwoFactor()->create();
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
        $this->ledger = app(Ledger::class);
    }

    private function approvedPayin(int $amount, string $utr): Transaction
    {
        $payin = $this->submittedPayin(['amount' => $amount], $utr);
        $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => $utr])->assertSessionHasNoErrors();

        return $payin->refresh();
    }

    private function calculate(string $type, string $id): Settlement
    {
        $this->actingAs($this->admin)->post(route('admin.settlements.calculate'), ['party_type' => $type, 'party_id' => $id])->assertSessionHasNoErrors();

        return Settlement::query()->where('party_type', $type)->orderByDesc('period_end')->firstOrFail();
    }

    private function pay(Settlement $settlement, string $rupees)
    {
        return $this->actingAs($this->admin)->post(route('admin.settlements.pay', $settlement), [
            'amounts' => [$settlement->lines()->value('id') => $rupees],
            'paid_at' => now(config('app.business_timezone'))->toDateString(),
            'reference' => 'NEFT-SET-001',
        ]);
    }

    public function test_a_settlement_shows_each_partys_position_with_the_breakdown()
    {
        $this->approvedPayin(1000000, '626812820491');
        $this->travel(1)->minutes();

        $partner = $this->calculate('partner', $this->partner->id);
        // ₹10,000 at 6%: the platform owes the partner ₹9,400.
        $this->assertSame(1000000, $partner->gross_payin);
        $this->assertSame(60000, $partner->partner_commission);
        $this->assertSame(940000, $partner->closing_balance);
        $this->assertSame(940000, $partner->net_amount);
        $this->assertSame('platform_to_party', $partner->direction);
        $this->assertSame('calculated', $partner->status);
        $this->assertSame(1, $partner->lines()->count());

        $branch = $this->calculate('branch', $this->branch->id);
        // At 4% the branch keeps ₹400 and owes ₹9,600.
        $this->assertSame(-960000, $branch->closing_balance);
        $this->assertSame('party_to_platform', $branch->direction);
        $this->assertSame(40000, $branch->branch_commission);
        $this->assertSame(20000, $branch->platform_margin);

        // Nothing new since: a second on-demand run is refused.
        $this->actingAs($this->admin)->post(route('admin.settlements.calculate'), ['party_type' => 'branch', 'party_id' => $this->branch->id])
            ->assertSessionHasErrors('party');
    }

    public function test_a_partial_payment_carries_forward_into_the_next_settlement()
    {
        $this->approvedPayin(1000000, '626812820491');
        $this->travel(1)->minutes();
        $first = $this->calculate('branch', $this->branch->id);

        $this->travel(1)->minutes();
        $this->pay($first, '5000')->assertSessionHasNoErrors();

        $first->refresh();
        $this->assertSame('partially_settled', $first->status);
        $this->assertSame(500000, $first->settled_amount);
        // The branch paid ₹5,000: it now owes ₹4,600.
        $this->assertSame(-460000, $this->ledger->balance(Ledger::BRANCH_POSITION, $this->partner->id, $this->branch->id));
        $this->assertSame(-500000, $this->ledger->balance(Ledger::SETTLEMENT_CLEARING));
        $this->assertSame(0, (int) DB::table('ledger_entries')->sum('amount'));

        // Too much for the line.
        $this->pay($first, '4600.01')->assertSessionHasErrors('amounts.'.$first->lines()->value('id'));

        $this->travel(1)->minutes();
        $this->approvedPayin(200000, '626812820492');
        $this->travel(1)->minutes();
        $second = $this->calculate('branch', $this->branch->id);

        // Opening = the first closing; the payment and the new deposit are this period's movements.
        $this->assertSame(-960000, $second->opening_balance);
        $this->assertSame(500000, $second->settlements_total);
        $this->assertSame(200000, $second->gross_payin);
        $this->assertSame(-460000 - 192000, $second->closing_balance);

        // The first one is carried forward: payments go to the newest.
        $this->pay($first, '100')->assertSessionHasErrors('amounts');
        $this->actingAs($this->admin)->get(route('admin.settlements.index', ['tab' => 'all']))
            ->assertInertia(fn ($page) => $page->component('settlements/index')
                ->where('items.data.0.id', $second->id)->where('items.data.0.can_pay', true)
                ->where('items.data.1.carried_forward', true)->where('items.data.1.can_pay', false));

        $this->pay($second, '6520')->assertSessionHasNoErrors();
        $this->assertSame('settled', $second->refresh()->status);
        $this->assertSame(0, $this->ledger->balance(Ledger::BRANCH_POSITION, $this->partner->id, $this->branch->id));
    }

    public function test_paying_a_partner_out_leaves_what_payouts_in_progress_hold()
    {
        $this->approvedPayin(1000000, '626812820491');
        $this->rate('partner', $this->partner->id, 'withdrawal', '5');
        $this->rate('branch', $this->branch->id, 'withdrawal', '3');
        $this->partner->update(['is_payout_enabled' => true]);
        $this->branch->update(['is_withdrawal_enabled' => true]);
        DB::table('partner_branch_mappings')->update(['is_withdrawal_enabled' => true]);
        // A ₹5,000 payout holds ₹5,250 of the ₹9,400.
        $this->api('POST', '/v1/payouts', [
            'order_id' => 'WD-1', 'amount' => 500000, 'customer' => ['id' => 'c1'],
            'beneficiary' => ['type' => 'bank', 'name' => 'Ravi', 'account_number' => '50100482716640', 'ifsc' => 'HDFC0001203'],
        ])->assertCreated();

        $this->travel(1)->minutes();
        $settlement = $this->calculate('partner', $this->partner->id);

        $this->pay($settlement, '9400')->assertSessionHasErrors('amounts.'.$settlement->lines()->value('id'));
        $this->pay($settlement, '4150')->assertSessionHasNoErrors();
        $this->assertSame(525000, $this->ledger->balance(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id));
    }

    public function test_the_daily_run_follows_the_cut_off_set_in_global_settings()
    {
        $this->actingAs($this->admin)->put(route('admin.settings.settlement'), ['timezone' => 'UTC', 'time' => '06:00'])->assertSessionHasNoErrors();
        $this->assertSame(['timezone' => 'UTC', 'time' => '06:00'], app(Settings::class)->settlementCutoff());
        $this->assertSame('2026-09-27T06:00:00+00:00', app(Settings::class)->lastCutoff(CarbonImmutable::parse('2026-09-28T05:59:00Z'))->toIso8601String());
        $this->assertSame('2026-09-28T06:00:00+00:00', app(Settings::class)->lastCutoff(CarbonImmutable::parse('2026-09-28T06:00:00Z'))->toIso8601String());

        $this->travelTo(CarbonImmutable::parse('2026-09-28T04:00:00Z'));
        $this->approvedPayin(1000000, '626812820491');
        $idle = Branch::factory()->create();

        $this->travelTo(CarbonImmutable::parse('2026-09-28T06:01:00Z'));
        $run = app(CalculateSettlement::class);
        $cutoff = app(Settings::class)->lastCutoff();

        $this->assertSame(2, $run->daily($cutoff)); // the partner and the branch
        $this->assertSame(0, $run->daily($cutoff)); // nothing new
        $this->assertFalse(Settlement::where('branch_id', $idle->id)->exists());

        $daily = Settlement::where('party_type', 'partner')->sole();
        $this->assertSame('daily', $daily->run_type);
        $this->assertTrue($daily->period_end->equalTo($cutoff));
        $this->assertDatabaseHas('audit_logs', ['action' => 'setting.updated', 'actor_id' => $this->admin->id]);
    }

    public function test_adjustments_need_a_second_admin_and_topups_fund_partner_payouts()
    {
        $this->actingAs($this->admin)->post(route('admin.adjustments.store'), [
            'type' => 'topup', 'partner_id' => $this->partner->id, 'branch_id' => $this->branch->id,
            'side' => 'partner', 'sign' => 'credit', 'amount' => '1000', 'reason' => 'Partner transferred ₹1,000 (UTR X1)',
        ])->assertSessionHasNoErrors();
        $topup = Adjustment::sole();
        $this->assertSame('pending', $topup->status);
        $this->assertSame(0, $this->ledger->balance(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id));

        // Not by the person who asked.
        $this->actingAs($this->admin)->post(route('admin.adjustments.approve', $topup))->assertSessionHasErrors('adjustment');
        // Finance can ask but not approve.
        $finance = User::factory()->admin(SystemRoles::ADMIN_FINANCE)->withTwoFactor()->create();
        $this->actingAs($finance)->post(route('admin.adjustments.approve', $topup))->assertForbidden();

        $this->actingAs($this->checker)->post(route('admin.adjustments.approve', $topup), ['note' => 'Seen in bank'])->assertSessionHasNoErrors();

        $topup->refresh();
        $this->assertSame('approved', $topup->status);
        $this->assertSame($this->checker->id, $topup->approved_by);
        $this->assertSame(100000, $this->ledger->balance(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id));
        $this->assertSame(-100000, $this->ledger->balance(Ledger::SETTLEMENT_CLEARING));
        $this->actingAs($this->checker)->post(route('admin.adjustments.approve', $topup))->assertSessionHasErrors('adjustment');

        // A top-up only credits a partner.
        $this->actingAs($this->admin)->post(route('admin.adjustments.store'), [
            'type' => 'topup', 'partner_id' => $this->partner->id, 'branch_id' => $this->branch->id,
            'side' => 'branch', 'sign' => 'credit', 'amount' => '10', 'reason' => 'x',
        ])->assertSessionHasErrors('amount');
    }

    public function test_a_correction_can_resolve_an_unsettled_case_and_rejections_keep_the_note()
    {
        $account = $this->activeAccount();
        $this->actingAs($this->operator)->post(route('branch.statements.store'), [
            'payment_account_id' => $account->id, 'value_date' => now(config('app.business_timezone'))->toDateString(),
            'utr' => '', 'description' => 'DUPLICATE LINE', 'credit' => '500',
        ]);
        $case = ReconciliationCase::sole();

        $request = fn (string $reason) => $this->actingAs($this->admin)->post(route('admin.adjustments.store'), [
            'type' => 'correction', 'partner_id' => $this->partner->id, 'branch_id' => $this->branch->id,
            'side' => 'branch', 'sign' => 'credit', 'amount' => '500', 'reason' => $reason, 'case' => $case->reference,
        ]);

        $request('First try')->assertSessionHasNoErrors();
        $first = Adjustment::sole();
        $this->actingAs($this->checker)->post(route('admin.adjustments.reject', $first), ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($this->checker)->post(route('admin.adjustments.reject', $first), ['note' => 'Wrong side'])->assertSessionHasNoErrors();
        $this->assertSame('Wrong side', $first->refresh()->decision_note);
        $this->assertTrue($case->refresh()->isOpen());

        $request('Duplicate statement line')->assertSessionHasNoErrors();
        $second = Adjustment::where('status', 'pending')->sole();
        $this->actingAs($this->checker)->post(route('admin.adjustments.approve', $second))->assertSessionHasNoErrors();

        $this->assertSame(50000, $this->ledger->balance(Ledger::BRANCH_POSITION, $this->partner->id, $this->branch->id));
        $this->assertSame(-50000, $this->ledger->balance(Ledger::PLATFORM_ADJUSTMENTS));
        $case->refresh();
        $this->assertSame('resolved', $case->status);
        $this->assertSame('adjusted', $case->resolution->value);
    }

    public function test_partners_and_branches_see_only_their_own_settlements_and_side()
    {
        $this->approvedPayin(1000000, '626812820491');
        $this->travel(1)->minutes();
        $this->calculate('partner', $this->partner->id);
        $branchSettlement = $this->calculate('branch', $this->branch->id);

        $partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->withTwoFactor()->create();
        $this->actingAs($partnerUser)->get(route('partner.settlements.index'))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)
                ->where('items.data.0.party_type', 'partner')
                ->where('items.data.0.figures.branch_commission', null)
                ->where('items.data.0.figures.platform_margin', null)
                ->where('items.data.0.can_pay', false));
        $this->actingAs($partnerUser)->post('/partner/settlements/calculate', [])->assertNotFound();

        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        $this->actingAs($owner)->get(route('branch.settlements.index', ['settlement' => $branchSettlement->id]))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)
                ->where('items.data.0.figures.partner_commission', null)
                ->where('items.data.0.figures.branch_commission', 40000));
        $this->actingAs($this->operator)->get(route('branch.balance'))->assertForbidden(); // the operator role has no balances.view
        $this->actingAs($owner)->get(route('branch.balance'))
            ->assertInertia(fn ($page) => $page->component('branch/balance')->where('total', -960000)->where('latest.reference', $branchSettlement->reference));

        $stranger = User::factory()->branch(SystemRoles::BRANCH_OWNER)->withTwoFactor()->create();
        $this->actingAs($stranger)->get(route('branch.settlements.index', ['tab' => 'all']))->assertInertia(fn ($page) => $page->has('items.data', 0));

        $viewer = User::factory()->admin(SystemRoles::ADMIN_VIEWER)->withTwoFactor()->create();
        $this->actingAs($viewer)->post(route('admin.settlements.calculate'), ['party_type' => 'partner', 'party_id' => $this->partner->id])->assertForbidden();
    }

    public function test_the_commissions_page_adds_up_the_snapshotted_commissions()
    {
        $this->approvedPayin(1000000, '626812820491');
        $this->approvedPayin(250000, '626812820492');

        $this->actingAs($this->admin)->get(route('admin.commissions.index'))
            ->assertInertia(fn ($page) => $page->component('admin/commissions')
                ->where('totals.partner_commission', 75000)
                ->where('totals.branch_commission', 50000)
                ->where('totals.margin', 25000)
                ->where('rows.0.payin.count', 2)
                ->where('rows.0.payin.commission', 75000));
        $this->actingAs($this->admin)->get(route('admin.commissions.index', ['by' => 'branch']))
            ->assertInertia(fn ($page) => $page->where('rows.0.code', $this->branch->code)->where('rows.0.payin.commission', 50000));
    }
}
