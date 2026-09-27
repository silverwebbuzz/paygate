<?php

namespace Tests\Feature\Payins;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\CommissionCalculator;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Ledger\Ledger;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Webhook\Jobs\DeliverWebhook;
use App\Domain\Webhook\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->buildNetwork();
        $this->partner->update(['payin_webhook_url' => 'https://atoz.example/hooks/payin']);
        $this->activeAccount();
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
    }

    private function approve(Transaction $payin, string $utr = '626812820491', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => $utr]);
    }

    private function counter(string $scope, string $id, string $column): int
    {
        return (int) DB::table('usage_counters')
            ->where(['scope_type' => $scope, 'scope_id' => $id, 'business_date' => UsageCounters::businessDate(), 'direction' => 'deposit'])
            ->value($column);
    }

    public function test_approving_books_commission_and_the_ledger_in_one_step()
    {
        $payin = $this->submittedPayin(['amount' => 1253400]);

        $this->approve($payin)->assertSessionHasNoErrors();
        $payin->refresh();

        // ₹12,534 × 6% = ₹752.04 paid by the partner; × 4% = ₹501.36 to the branch.
        $this->assertSame('success', $payin->status);
        $this->assertSame(75204, $payin->partner_commission);
        $this->assertSame(50136, $payin->branch_commission);
        $this->assertSame(25068, $payin->platform_margin);
        $this->assertSame('6.0000', $payin->partner_rate_percent);
        $this->assertSame('626812820491', $payin->bank_utr_normalized);
        $this->assertSame($this->operator->id, $payin->decided_by);
        $this->assertNotNull($payin->succeeded_at);

        $ledger = app(Ledger::class);
        $this->assertSame(1253400 - 75204, $ledger->balance(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id));
        $this->assertSame(-(1253400 - 50136), $ledger->balance(Ledger::BRANCH_POSITION, $this->partner->id, $this->branch->id));
        $this->assertSame(25068, $ledger->balance(Ledger::PLATFORM_MARGIN));
        $this->assertSame(0, (int) DB::table('ledger_entries')->sum('amount'));

        // The reservation became confirmed usage.
        $this->assertSame(0, $this->counter('account', $payin->payment_account_id, 'reserved_amount'));
        $this->assertSame(1253400, $this->counter('account', $payin->payment_account_id, 'confirmed_amount'));
        $this->assertSame(1253400, $this->counter('partner', $this->partner->id, 'confirmed_amount'));
        $this->assertSame(0, $this->counter('account', $payin->payment_account_id, 'open_sessions'));

        $this->assertDatabaseHas('webhook_events', ['transaction_id' => $payin->id, 'event_type' => 'payin.success']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payin.approved', 'subject_id' => $payin->id, 'actor_id' => $this->operator->id]);
        Queue::assertPushed(DeliverWebhook::class);
    }

    public function test_commissions_round_half_up_to_the_paisa()
    {
        $this->assertSame(309, CommissionCalculator::commission(12345, '2.5'));     // 308.625
        $this->assertSame(309, CommissionCalculator::commission(12345, '2.4995'));  // 308.56… up
        $this->assertSame(307, CommissionCalculator::commission(12345, '2.49'));    // 307.39… down
        $this->assertSame(1, CommissionCalculator::commission(20, '2.5'));          // 0.5 → 1
        $this->assertSame(0, CommissionCalculator::commission(19, '2.5'));          // 0.475
    }

    public function test_pair_rates_override_the_defaults_and_a_negative_margin_is_booked_as_such()
    {
        $mapping = PartnerBranchMapping::firstOrFail();
        $this->rate('mapping', $mapping->id, 'deposit', '3.5', 'partner');

        $payin = $this->submittedPayin(['amount' => 100000]);
        $this->approve($payin)->assertSessionHasNoErrors();
        $payin->refresh();

        $this->assertSame(3500, $payin->partner_commission);
        $this->assertSame(4000, $payin->branch_commission);
        $this->assertSame(-500, $payin->platform_margin);
        $this->assertSame(-500, app(Ledger::class)->balance(Ledger::PLATFORM_MARGIN));
    }

    public function test_approval_needs_a_valid_bank_utr_that_is_not_already_used_on_the_account()
    {
        $first = $this->submittedPayin();
        $this->approve($first, 'abc')->assertSessionHasErrors('bank_utr');
        $this->approve($first, '5268 1282 0491')->assertSessionHasNoErrors();

        $second = $this->submittedPayin();
        $this->approve($second, '526812820491')->assertSessionHasErrors('bank_utr');
        $this->assertSame('payment_submitted', $second->fresh()?->status);
        $this->assertSame(1, (int) DB::table('ledger_journals')->count());
    }

    public function test_without_rates_nothing_is_approved()
    {
        DB::table('commission_rates')->where('subject_type', 'branch')->delete();
        $payin = $this->submittedPayin();

        $this->approve($payin)->assertSessionHasErrors('commission');
        $this->assertSame('payment_submitted', $payin->fresh()?->status);
        $this->assertSame(0, (int) DB::table('ledger_journals')->count());
    }

    public function test_only_the_receiving_branch_or_admin_may_decide()
    {
        $payin = $this->submittedPayin();
        $otherBranch = User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create();
        $partner = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->create();
        $viewer = User::factory()->admin(SystemRoles::ADMIN_VIEWER)->withTwoFactor()->create();

        $this->approve($payin, as: $otherBranch)->assertForbidden();
        $this->actingAs($partner)->post(route('admin.deposits.approve', $payin), ['bank_utr' => '626812820491'])->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.deposits.approve', $payin), ['bank_utr' => '626812820491'])->assertForbidden();

        $ops = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();
        $this->actingAs($ops)->post(route('admin.deposits.approve', $payin), ['bank_utr' => '626812820491'])->assertSessionHasNoErrors();
        $this->assertSame('success', $payin->fresh()?->status);
    }

    public function test_a_pay_in_is_booked_only_once()
    {
        $payin = $this->submittedPayin();
        $this->approve($payin)->assertSessionHasNoErrors();
        $this->approve($payin, '999988887777')->assertSessionHasErrors('status');

        $this->assertSame(1, (int) DB::table('ledger_journals')->where('transaction_id', $payin->id)->count());
    }

    public function test_hold_then_decline_releases_the_reservation_and_notifies_the_partner()
    {
        $payin = $this->submittedPayin(['amount' => 300000]);

        $this->actingAs($this->operator)->post(route('branch.deposits.hold', $payin), [])->assertSessionHasErrors('reason');
        $this->actingAs($this->operator)->post(route('branch.deposits.hold', $payin), ['reason' => 'Checking with bank'])->assertSessionHasNoErrors();
        $this->assertSame('under_review', $payin->fresh()?->status);

        $this->actingAs($this->operator)->post(route('branch.deposits.decline', $payin), ['reason_code' => 'other'])->assertSessionHasErrors('note');
        $this->actingAs($this->operator)->post(route('branch.deposits.decline', $payin), ['reason_code' => 'payment_not_found'])->assertSessionHasNoErrors();

        $payin->refresh();
        $this->assertSame('rejected', $payin->status);
        $this->assertSame('payment_not_found', $payin->status_reason_code);
        $this->assertSame(0, $this->counter('account', $payin->payment_account_id, 'reserved_amount'));
        $this->assertSame(0, $this->counter('account', $payin->payment_account_id, 'confirmed_amount'));
        $this->assertDatabaseHas('webhook_events', ['transaction_id' => $payin->id, 'event_type' => 'payin.rejected']);
        $this->assertSame(0, (int) DB::table('ledger_journals')->count());

        // A declined UTR can be used for a genuine pay-in later.
        $again = $this->submittedPayin();
        $this->approve($again)->assertSessionHasNoErrors();
    }

    public function test_a_topup_branch_allowance_shrinks_by_what_it_received()
    {
        $this->branch->update(['deposit_limit_type' => 'topup', 'deposit_topup_balance' => 1000000]);
        $payin = $this->submittedPayin(['amount' => 250000]);

        $this->approve($payin)->assertSessionHasNoErrors();

        $this->assertSame(750000, $this->branch->fresh()?->deposit_topup_balance);
    }

    public function test_partners_get_webhooks_for_submitted_and_decided_pay_ins_only_with_a_url()
    {
        $payin = $this->submittedPayin();
        $this->assertSame(['payin.submitted'], WebhookEvent::where('transaction_id', $payin->id)->pluck('event_type')->all());

        $this->partner->update(['payin_webhook_url' => null]);
        $quiet = $this->submittedPayin();
        $this->assertSame(0, WebhookEvent::where('transaction_id', $quiet->id)->count());
    }
}
