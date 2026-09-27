<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Ledger\Ledger;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\StatementMatcher;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Webhook\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Inertia;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class StatementMatchingTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $owner;

    private User $operator;

    private User $admin;

    private PaymentAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->buildNetwork();
        $this->partner->update(['payin_webhook_url' => 'https://atoz.example/hooks/payin']);
        $this->account = $this->activeAccount();
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function addLine(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->operator)->post(route(($as ?? $this->operator)->type->value.'.statements.store'), [
            'payment_account_id' => $this->account->id,
            'value_date' => now(config('app.business_timezone'))->toDateString(),
            'utr' => '626812820491',
            'description' => 'UPI/626812820491/Payment',
            'credit' => '5000',
            ...$overrides,
        ]);
    }

    private function partial(User $user, string $url, string $component, string $props)
    {
        return $this->actingAs($user)->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $props,
            'X-Inertia-Version' => Inertia::getVersion(),
        ]);
    }

    private function line(string $utr = '626812820491'): StatementEntry
    {
        return StatementEntry::where('utr_normalized', $utr)->sole();
    }

    public function test_a_credit_with_the_deposits_utr_and_amount_is_linked_and_the_branch_still_approves()
    {
        $payin = $this->submittedPayin(['amount' => 500000]);

        $this->addLine()->assertSessionHasNoErrors();

        $line = $this->line();
        $this->assertSame('matched', $line->status);
        $this->assertSame($payin->id, $line->transaction_id);
        $this->assertSame(0, ReconciliationCase::count());
        // Not approved by the match (G-23): the branch still decides.
        $this->assertSame('payment_submitted', $payin->refresh()->status);
        $this->assertTrue($payin->events()->where('event', 'bank_line_matched')->exists());

        // The deposit queue shows the bank line; approving reconciles it.
        $this->actingAs($this->operator)->get(route('branch.deposits.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.bank_line.status', 'matched'));
        $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '626812820491'])->assertSessionHasNoErrors();

        $this->assertSame('reconciled', $line->refresh()->status);
        $this->assertSame('success', $payin->refresh()->status);
    }

    public function test_approving_with_another_utr_than_the_linked_line_sends_the_line_to_review()
    {
        $payin = $this->submittedPayin(['amount' => 500000]);
        $this->addLine();

        $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '999912820491'])->assertSessionHasNoErrors();

        $line = $this->line();
        $this->assertSame('unmatched', $line->status);
        $this->assertNull($line->transaction_id);
        $case = ReconciliationCase::sole();
        $this->assertSame('manual_review', $case->type->value);
        $this->assertSame($payin->id, $case->transaction_id);
        $this->assertStringContainsString('999912820491', (string) $case->notes);
    }

    public function test_an_approved_deposit_is_reconciled_when_its_bank_line_arrives()
    {
        $payin = $this->submittedPayin(['amount' => 500000]);
        $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '626812820491']);

        $this->addLine()->assertSessionHasNoErrors();

        $this->assertSame('reconciled', $this->line()->status);
        $this->assertSame($payin->id, $this->line()->transaction_id);
    }

    public function test_a_different_amount_opens_an_amount_mismatch_case()
    {
        $payin = $this->submittedPayin(['amount' => 500000]);

        $this->addLine(['credit' => '4990'])->assertSessionHasNoErrors();

        $case = ReconciliationCase::sole();
        $this->assertSame('amount_mismatch', $case->type->value);
        $this->assertSame($payin->id, $case->transaction_id);
        $this->assertSame('unmatched', $this->line()->status);
        $this->assertStringContainsString('4,990.00', (string) $case->notes);

        // Same branch, so the branches match even though the line isn't linked.
        $this->actingAs($this->owner)->get(route('branch.statements.index', ['match' => 'matched']))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.branch_match', 'matched')->where('items.data.0.linked', false));
        $this->actingAs($this->owner)->get(route('branch.statements.index', ['match' => 'none']))->assertInertia(fn ($page) => $page->has('items.data', 0));
    }

    public function test_a_utr_of_another_branchs_deposit_is_a_wrong_branch_case()
    {
        $other = Branch::factory()->create();
        $this->mapPair($this->partner, $other);
        $otherAccount = $this->activeAccount($other);
        // Only the other branch's account can take the deposit.
        $this->account->update(['status' => 'paused']);
        $payin = $this->submittedPayin(['amount' => 500000]);
        $this->assertSame($otherAccount->id, $payin->payment_account_id);
        $this->account->update(['status' => 'active']);

        $this->addLine()->assertSessionHasNoErrors();

        $case = ReconciliationCase::sole();
        $this->assertSame('wrong_branch', $case->type->value);
        $this->assertSame($payin->id, $case->transaction_id);
        $this->assertSame($this->branch->id, $case->branch_id);

        $this->actingAs($this->admin)->get(route('admin.statements.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.branch_match', 'mismatch'));
        $this->actingAs($this->admin)->get(route('admin.statements.index', ['match' => 'mismatch']))->assertInertia(fn ($page) => $page->has('items.data', 1));
        $this->actingAs($this->admin)->get(route('admin.statements.index', ['match' => 'matched']))->assertInertia(fn ($page) => $page->has('items.data', 0));
    }

    public function test_a_line_that_arrives_before_the_customers_claim_links_itself_later()
    {
        [$reference, $token] = $this->createPayin(['amount' => 500000]);
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi']);

        $this->addLine()->assertSessionHasNoErrors();
        $case = ReconciliationCase::sole();
        $this->assertSame('utr_not_found', $case->type->value);

        $this->post("http://pay.paygate.local/p/{$token}/proof", ['utr' => '6268 1282 0491'])->assertSessionHasNoErrors();

        $payin = Transaction::where('reference', $reference)->firstOrFail();
        $this->assertSame('matched', $this->line()->status);
        $this->assertSame($payin->id, $this->line()->transaction_id);
        $case->refresh();
        $this->assertSame('resolved', $case->status);
        $this->assertSame('linked', $case->resolution->value);
        $this->assertNull($case->resolved_by); // the system
    }

    public function test_declining_a_deposit_whose_credit_was_found_opens_a_late_payment_case_admin_can_approve()
    {
        $payin = $this->submittedPayin(['amount' => 500000]);
        $this->addLine();
        $this->actingAs($this->operator)->post(route('branch.deposits.decline', $payin), ['reason_code' => 'payment_not_found'])->assertSessionHasNoErrors();

        $case = ReconciliationCase::sole();
        $this->assertSame('late_payment', $case->type->value);
        $this->assertSame('unmatched', $this->line()->status);

        // Branches can't approve late payments.
        $this->actingAs($this->owner)->post('/branch/unsettled/'.$case->id.'/approve-late', ['transaction_id' => $payin->id])->assertNotFound();
        $this->actingAs($this->owner)->post(route('branch.cases.link', $case), ['transaction_id' => $payin->id])
            ->assertSessionHasErrors(['transaction' => "{$payin->reference} is rejected: an administrator can approve it late."]);

        $this->actingAs($this->admin)->post(route('admin.cases.approve-late', $case), ['transaction_id' => $payin->id])->assertSessionHasNoErrors();

        $payin->refresh();
        $this->assertSame('success', $payin->status);
        $this->assertSame('late_payment', $payin->status_reason_code);
        $this->assertSame('626812820491', $payin->bank_utr_normalized);
        $this->assertSame('reconciled', $this->line()->status);
        $this->assertSame('resolved', $case->refresh()->status);
        $this->assertSame($this->admin->id, $case->resolved_by);

        // Booked like any approval; the partner hears success with late: true.
        $this->assertSame(500000 - 30000, app(Ledger::class)->balance(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id));
        $hook = WebhookEvent::where(['transaction_id' => $payin->id, 'event_type' => 'payin.success'])->sole();
        $this->assertTrue($hook->payload['data']['late']);
        $this->assertSame(500000, (int) DB::table('usage_counters')->where(['scope_type' => 'account', 'scope_id' => $this->account->id, 'business_date' => UsageCounters::businessDate(), 'direction' => 'deposit'])->value('confirmed_amount'));
        $this->assertTrue($payin->events()->where('event', 'approved_late')->exists());
    }

    public function test_the_same_line_twice_is_refused_and_the_same_utr_on_another_line_is_a_duplicate_case()
    {
        $this->submittedPayin(['amount' => 500000]);
        $this->addLine();

        $this->addLine()->assertSessionHasErrors('utr');
        $this->assertSame(1, StatementEntry::count());

        $yesterday = now(config('app.business_timezone'))->subDay()->toDateString();
        $this->addLine(['value_date' => $yesterday])->assertSessionHasNoErrors();

        $second = StatementEntry::whereDate('value_date', $yesterday)->sole();
        $this->assertSame('duplicate', $second->status);
        $this->assertSame('duplicate_utr', ReconciliationCase::sole()->type->value);
    }

    public function test_a_line_without_utr_is_linked_by_hand_only_to_the_same_amount()
    {
        $payin = $this->submittedPayin(['amount' => 500000]);
        $small = $this->submittedPayin(['amount' => 250000], '626812820492');

        $this->addLine(['utr' => '', 'description' => 'CASH DEPOSIT BY SELF'])->assertSessionHasNoErrors();
        $case = ReconciliationCase::sole();
        $this->assertSame('manual_review', $case->type->value);

        // The operator role may not resolve cases; the branch admin may.
        $this->actingAs($this->operator)->post(route('branch.cases.link', $case), ['transaction_id' => $payin->id])->assertForbidden();
        $this->actingAs($this->owner)->post(route('branch.cases.link', $case), ['transaction_id' => $small->id])
            ->assertSessionHasErrors('transaction');

        $candidates = $this->partial($this->owner, route('branch.cases.index', ['case' => $case->id]), 'reconciliation/cases', 'candidates')->json('props.candidates');
        // Same account and amount only (the ₹2,500 deposit isn't offered).
        $this->assertSame([$payin->reference], array_column($candidates, 'reference'));
        $this->actingAs($this->owner)->post(route('branch.cases.link', $case), ['transaction_id' => $payin->id])->assertSessionHasNoErrors();

        $entry = StatementEntry::sole();
        $this->assertSame('matched', $entry->status);
        $this->assertSame($payin->id, $entry->transaction_id);
        $this->assertSame($this->owner->id, $entry->matched_by);
        $this->assertSame('linked', $case->refresh()->resolution->value);
    }

    public function test_a_case_can_be_closed_as_not_a_customer_payment_or_returned_money()
    {
        $this->addLine(['utr' => '', 'description' => 'SMS CHARGES', 'credit' => '', 'debit' => '17.70'])->assertSessionHasNoErrors();
        $charges = ReconciliationCase::sole();

        $this->actingAs($this->owner)->post(route('branch.cases.close', $charges), ['resolution' => 'refunded', 'note' => 'x'])
            ->assertSessionHasErrors('resolution');
        $this->actingAs($this->owner)->post(route('branch.cases.close', $charges), ['resolution' => 'rejected', 'note' => 'Bank SMS charges'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ignored', StatementEntry::sole()->status);
        $this->assertSame('rejected', $charges->refresh()->resolution->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reconciliation.case_resolved', 'subject_id' => $charges->id, 'actor_id' => $this->owner->id]);

        // Already resolved.
        $this->actingAs($this->owner)->post(route('branch.cases.close', $charges), ['resolution' => 'rejected', 'note' => 'again'])
            ->assertSessionHasErrors('case');
    }

    public function test_a_debit_is_reconciled_with_the_payout_paid_with_its_utr()
    {
        $payout = Transaction::create([
            'reference' => Transaction::newPayoutReference(),
            'direction' => 'payout',
            'partner_id' => $this->partner->id,
            'partner_transaction_id' => 'WD-1',
            'branch_id' => $this->branch->id,
            'amount' => 250000,
            'status' => 'processing',
        ]);

        $this->addLine(['utr' => 'IMPS601234567890', 'credit' => '', 'debit' => '2500'])->assertSessionHasNoErrors();
        $this->assertSame('utr_not_found', ReconciliationCase::sole()->type->value);

        // Marked paid with that UTR: the waiting debit links itself.
        $payout->forceFill(['status' => 'success', 'bank_utr_normalized' => 'IMPS601234567890', 'partner_commission' => 0, 'branch_commission' => 0, 'platform_margin' => 0, 'succeeded_at' => now()])->save();
        DB::transaction(fn () => app(StatementMatcher::class)->payoutPaid($payout));

        $this->assertSame('reconciled', $this->line('IMPS601234567890')->status);
        $this->assertSame('resolved', ReconciliationCase::sole()->status);
    }

    public function test_branches_see_only_their_own_lines_and_cases()
    {
        $other = Branch::factory()->create();
        $stranger = User::factory()->branch(SystemRoles::BRANCH_OWNER, $other)->withTwoFactor()->create();
        $this->addLine(['utr' => '', 'description' => 'UNKNOWN']);
        $case = ReconciliationCase::sole();

        $this->actingAs($stranger)->get(route('branch.statements.index'))->assertInertia(fn ($page) => $page->has('items.data', 0));
        $this->actingAs($stranger)->get(route('branch.cases.index'))->assertInertia(fn ($page) => $page->has('cases.data', 0));
        $this->actingAs($stranger)->post(route('branch.cases.close', $case), ['resolution' => 'rejected', 'note' => 'x'])->assertNotFound();
        $this->actingAs($stranger)->post(route('branch.statements.store'), [
            'payment_account_id' => $this->account->id,
            'value_date' => now(config('app.business_timezone'))->toDateString(),
            'utr' => '626812820491',
            'credit' => '10',
        ])->assertSessionHasErrors('payment_account_id');

        $this->actingAs($this->owner)->get(route('branch.statements.index'))
            ->assertInertia(fn ($page) => $page->component('statements/index')->has('items.data', 1)->where('items.data.0.display', 'unsettled'));
    }

    public function test_the_transaction_drawer_compares_the_bank_line_but_partners_never_see_it()
    {
        $payin = $this->submittedPayin(['amount' => 500000]);
        $this->addLine();
        $partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->withTwoFactor()->create();

        $this->actingAs($this->admin)->get(route('admin.transactions.index'))
            ->assertInertia(fn ($page) => $page->where('transactions.data.0.bank_line.utr', '626812820491'));
        $this->partial($this->admin, route('admin.transactions.index', ['txn' => $payin->id]), 'transactions/index', 'detail')
            ->assertJsonPath('props.detail.reconciliation.line.utr', '626812820491')
            ->assertJsonPath('props.detail.reconciliation.transaction_branch', $this->branch->code);

        $this->actingAs($partnerUser)->get(route('partner.payins.index'))
            ->assertInertia(fn ($page) => $page->where('transactions.data.0.bank_line', null));
    }
}
