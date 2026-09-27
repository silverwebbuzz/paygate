<?php

namespace Tests\Feature\Payouts;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Ledger\Ledger;
use App\Domain\Payout\Models\PayoutBeneficiary;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->buildNetwork();
        $this->partner->update(['is_payout_enabled' => true, 'payout_webhook_url' => 'https://atoz.example/hooks/payout']);
        $this->rate('partner', $this->partner->id, 'withdrawal', '5');
        $this->rate('branch', $this->branch->id, 'withdrawal', '3');
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
    }

    /**
     * Gives the partner a balance at a branch (as deposits would).
     */
    private function fund(Branch $branch, int $amount): void
    {
        DB::transaction(function () use ($branch, $amount) {
            $ledger = app(Ledger::class);
            $ledger->post('adjustment', [
                $ledger->account(Ledger::PARTNER_POSITION, $this->partner->id, $branch->id) => $amount,
                $ledger->account(Ledger::SETTLEMENT_CLEARING) => -$amount,
            ], null, 'Test funding');
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'order_id' => 'WD-'.bin2hex(random_bytes(4)),
            'amount' => 9000,
            'customer' => ['id' => 'cust-9'],
            'beneficiary' => ['type' => 'bank', 'name' => 'Ravi Kumar', 'account_number' => '5010 0482 7166 40', 'ifsc' => 'hdfc0001203', 'bank_name' => 'HDFC Bank'],
            ...$overrides,
        ];
    }

    private function position(?Branch $branch = null): array
    {
        return app(Ledger::class)->partnerPositions($this->partner->id)[($branch ?? $this->branch)->id] ?? ['balance' => 0, 'reserved' => 0];
    }

    public function test_a_payout_holds_amount_plus_fee_and_goes_to_the_branch_queue()
    {
        $this->fund($this->branch, 9400); // ₹94.00

        $data = $this->api('POST', '/v1/payouts', $this->body(['amount' => 8000]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.fee', 400)
            ->assertJsonPath('data.beneficiary.account', 'XXXX6640')
            ->assertJsonMissingPath('data.branch')
            ->json('data');

        $this->assertMatchesRegularExpression('/^PO\d{6}[A-Z2-9]{8}$/', $data['id']);
        $this->assertSame(['balance' => 9400, 'reserved' => 8400], $this->position());

        $payout = Transaction::where('reference', $data['id'])->firstOrFail();
        $this->assertSame($this->branch->id, $payout->branch_id);
        $beneficiary = PayoutBeneficiary::findOrFail($payout->id);
        $this->assertSame('50100482716640', $beneficiary->account_number_encrypted);
        $this->assertStringNotContainsString('50100482716640', (string) json_encode(DB::table('payout_beneficiaries')->first()));
    }

    public function test_balance_is_low_when_no_branch_can_cover_amount_plus_fee()
    {
        $this->fund($this->branch, 9400);

        // ₹90 needs ₹94.50 (Database.md §3.3): refused, nothing stored.
        $this->api('POST', '/v1/payouts', $this->body(['amount' => 9000]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_balance');
        $this->assertSame(0, Transaction::where('direction', 'payout')->count());
        $this->assertSame(0, $this->position()['reserved']);

        // The largest possible is ₹89.52 (needs exactly ₹94.00).
        $this->api('GET', '/v1/balance')->assertOk()->assertJsonPath('data.available', 9400)->assertJsonPath('data.max_payout', 8952);
        $this->api('POST', '/v1/payouts', $this->body(['amount' => 8952]))->assertCreated();
        $this->assertSame(0, $this->position()['balance'] - $this->position()['reserved']);
    }

    public function test_the_branch_holding_enough_of_the_partners_balance_is_chosen()
    {
        $rich = Branch::factory()->create();
        $this->mapPair($this->partner, $rich);
        $this->rate('branch', $rich->id, 'withdrawal', '3');
        $this->fund($this->branch, 1000);
        $this->fund($rich, 500000);

        $id = $this->api('POST', '/v1/payouts', $this->body(['amount' => 100000]))->assertCreated()->json('data.id');

        $this->assertSame($rich->id, Transaction::where('reference', $id)->value('branch_id'));
    }

    public function test_repeats_are_safe_and_changed_details_are_refused()
    {
        $this->fund($this->branch, 50000);
        $body = $this->body(['order_id' => 'WD-1']);

        $first = $this->api('POST', '/v1/payouts', $body)->assertCreated()->json('data.id');
        $this->api('POST', '/v1/payouts', $body)->assertOk()->assertJsonPath('data.id', $first);
        $this->api('POST', '/v1/payouts', [...$body, 'amount' => 100])->assertStatus(409)->assertJsonPath('error.code', 'duplicate_order_id');

        $this->assertSame(9450, $this->position()['reserved']);
    }

    public function test_paying_books_the_ledger_and_releases_the_hold()
    {
        $this->fund($this->branch, 50000);
        $id = $this->api('POST', '/v1/payouts', $this->body(['amount' => 9000]))->json('data.id');
        $payout = Transaction::where('reference', $id)->firstOrFail();

        $this->actingAs($this->operator)->post(route('branch.payouts.start', $payout))->assertSessionHasNoErrors();
        $this->actingAs($this->operator)->post(route('branch.payouts.complete', $payout), ['bank_utr' => 'IMPS 5268 1282 0491'])->assertSessionHasNoErrors();

        $payout->refresh();
        $this->assertSame('success', $payout->status);
        $this->assertSame(450, $payout->partner_commission);   // 5% of ₹90
        $this->assertSame(270, $payout->branch_commission);    // 3% of ₹90
        $this->assertSame(180, $payout->platform_margin);

        // Option W-A: partner −94.50, branch +92.70, margin +1.80 (Database.md §3.2).
        $ledger = app(Ledger::class);
        $this->assertSame(['balance' => 50000 - 9450, 'reserved' => 0], $this->position());
        $this->assertSame(9270, $ledger->balance(Ledger::BRANCH_POSITION, $this->partner->id, $this->branch->id));
        $this->assertSame(180, $ledger->balance(Ledger::PLATFORM_MARGIN));
        $this->assertDatabaseHas('webhook_events', ['transaction_id' => $payout->id, 'event_type' => 'payout.success']);

        $this->api('GET', "/v1/payouts/{$id}")->assertJsonPath('data.status', 'success')->assertJsonPath('data.utr', 'IMPS526812820491')->assertJsonPath('data.fee', 450);
    }

    public function test_a_failed_payout_releases_the_hold_and_the_partner_can_retry()
    {
        $this->fund($this->branch, 9450);
        $id = $this->api('POST', '/v1/payouts', $this->body(['amount' => 9000]))->json('data.id');
        $payout = Transaction::where('reference', $id)->firstOrFail();

        $this->actingAs($this->operator)->post(route('branch.payouts.fail', $payout), ['reason_code' => 'other'])->assertSessionHasErrors('note');
        $this->actingAs($this->operator)->post(route('branch.payouts.fail', $payout), ['reason_code' => 'invalid_beneficiary'])->assertSessionHasNoErrors();

        $this->assertSame('failed', $payout->fresh()?->status);
        $this->assertSame(['balance' => 9450, 'reserved' => 0], $this->position());
        $this->assertDatabaseHas('webhook_events', ['transaction_id' => $payout->id, 'event_type' => 'payout.failed']);
        $this->assertSame(0, (int) DB::table('ledger_journals')->where('transaction_id', $payout->id)->count());

        $this->api('POST', '/v1/payouts', $this->body(['amount' => 9000]))->assertCreated();
    }

    public function test_partners_cancel_only_before_the_branch_starts()
    {
        $this->fund($this->branch, 50000);
        $a = $this->api('POST', '/v1/payouts', $this->body())->json('data.id');
        $b = $this->api('POST', '/v1/payouts', $this->body())->json('data.id');

        $this->api('POST', "/v1/payouts/{$a}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($this->operator)->post(route('branch.payouts.start', Transaction::where('reference', $b)->firstOrFail()));
        $this->api('POST', "/v1/payouts/{$b}/cancel")->assertStatus(409)->assertJsonPath('error.code', 'not_cancellable');

        $this->assertSame(9450, $this->position()['reserved']);
    }

    public function test_admin_can_move_a_waiting_payout_and_its_hold_to_another_branch()
    {
        $other = Branch::factory()->create();
        $this->mapPair($this->partner, $other);
        $this->rate('branch', $other->id, 'withdrawal', '2');
        $poor = Branch::factory()->create();
        $this->mapPair($this->partner, $poor);
        $this->rate('branch', $poor->id, 'withdrawal', '2');
        $this->fund($this->branch, 50000);
        $this->fund($other, 50000);

        $id = $this->api('POST', '/v1/payouts', $this->body())->json('data.id');
        $payout = Transaction::where('reference', $id)->firstOrFail();
        $from = $payout->branch_id;
        $to = $from === $this->branch->id ? $other : $this->branch;
        $admin = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();

        $this->actingAs($admin)->post(route('admin.payouts.reassign', $payout), ['branch_id' => $poor->id, 'reason' => 'x'])->assertSessionHasErrors('branch_id');
        $this->actingAs($admin)->post(route('admin.payouts.reassign', $payout), ['branch_id' => $to->id, 'reason' => 'Faster bank'])->assertSessionHasNoErrors();

        $this->assertSame($to->id, $payout->fresh()?->branch_id);
        $this->assertSame(0, app(Ledger::class)->partnerPositions($this->partner->id)[$from]['reserved']);
        $this->assertSame(9450, app(Ledger::class)->partnerPositions($this->partner->id)[$to->id]['reserved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout.reassigned', 'subject_id' => $payout->id]);
    }

    public function test_only_the_assigned_branch_or_admin_can_process_and_sees_the_full_details()
    {
        $this->fund($this->branch, 50000);
        $id = $this->api('POST', '/v1/payouts', $this->body())->json('data.id');
        $payout = Transaction::where('reference', $id)->firstOrFail();
        $stranger = User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create();

        $this->actingAs($stranger)->post(route('branch.payouts.complete', $payout), ['bank_utr' => '526812820491'])->assertForbidden();

        $this->actingAs($this->operator)->get(route('branch.payouts.index'))
            ->assertInertia(fn (Assert $page) => $page->component('payouts/index')->where('items.data.0.pay_to.account_number', '50100482716640'));
        $this->actingAs($stranger)->get(route('branch.payouts.index'))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 0));

        // After it's paid, even the branch sees it masked.
        $this->actingAs($this->operator)->post(route('branch.payouts.complete', $payout), ['bank_utr' => '526812820491']);
        $this->actingAs($this->operator)->get(route('branch.payouts.index', ['tab' => 'paid']))
            ->assertInertia(fn (Assert $page) => $page->where('items.data.0.pay_to', null));
    }

    public function test_payouts_need_them_enabled_and_respect_limits()
    {
        $this->fund($this->branch, 500000);
        $this->partner->update(['withdrawal_max_amount' => 20000, 'withdrawal_daily_limit' => 25000]);

        $this->api('POST', '/v1/payouts', $this->body(['amount' => 30000]))->assertStatus(422)->assertJsonPath('error.code', 'amount_out_of_range');
        $this->api('POST', '/v1/payouts', $this->body(['amount' => 20000]))->assertCreated();
        $this->api('POST', '/v1/payouts', $this->body(['amount' => 10000]))->assertStatus(422)->assertJsonPath('error.code', 'daily_limit_reached');

        $this->partner->update(['is_payout_enabled' => false]);
        $this->api('POST', '/v1/payouts', $this->body(['amount' => 100]))->assertForbidden()->assertJsonPath('error.code', 'payout_disabled');

        $this->api('POST', '/v1/payouts', ['order_id' => 'x', 'amount' => 1000, 'customer' => ['id' => 'c'], 'beneficiary' => ['type' => 'bank', 'name' => 'A']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['beneficiary.account_number', 'beneficiary.ifsc']]]);
    }

    public function test_a_started_payout_stays_in_the_to_pay_list()
    {
        $this->fund($this->branch, 50000);
        $id = $this->api('POST', '/v1/payouts', $this->body())->json('data.id');
        $this->actingAs($this->operator)->post(route('branch.payouts.start', Transaction::where('reference', $id)->firstOrFail()));

        $this->actingAs($this->operator)->get(route('branch.payouts.index'))
            ->assertInertia(fn (Assert $page) => $page->where('items.data.0.status', 'processing')->where('tabs.to_pay.count', 1)->where('tabs.paying.count', 1));
    }
}
