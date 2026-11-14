<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Ledger\Ledger;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Payout\Models\PayoutPayment;
use App\Domain\Platform\Settings;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\StatementMatcher;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Webhook\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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
        // The matching itself, with the branch approving by hand; auto-approval: AutoApproveDepositTest.
        $this->manualApproval();
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

        $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '999912820491', 'note' => 'Bank shows another entry'])->assertSessionHasNoErrors();

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

    public function test_a_deposit_declined_for_the_wrong_amount_leaves_its_line_waiting_for_the_right_one()
    {
        $payin = $this->submittedPayin(['amount' => 100000]);
        $this->addLine()->assertSessionHasNoErrors(); // the bank shows ₹5,000
        $case = ReconciliationCase::sole();
        $this->assertSame('amount_mismatch', $case->type->value);

        $this->actingAs($this->operator)->post(route('branch.deposits.decline', $payin), ['reason_code' => 'wrong_amount', 'note' => 'Bank shows 5000'])
            ->assertSessionHasNoErrors();

        // The deposit is declined; the bank line stays unsettled, saying why.
        $this->assertSame('rejected', $payin->refresh()->status);
        $line = $this->line();
        $this->assertSame('unmatched', $line->status);
        $this->assertSame('open', $case->refresh()->status);
        $this->assertSame('amount_mismatch', $case->type->value);
        $this->assertStringContainsString('was declined', (string) $case->notes);
        $this->actingAs($this->admin)->get(route('admin.statements.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.display', 'unsettled')->where('items.data.0.case.type', 'amount_mismatch'));

        // The customer submits the same UTR on a deposit of what the bank
        // shows: the same line links and the deposit is approved.
        app(Settings::class)->set(Settings::AUTO_APPROVE_DEPOSITS, true, $this->networkAdmin);
        $again = $this->submittedPayin(['amount' => 500000]);

        $this->assertSame('success', $again->refresh()->status);
        $this->assertSame($again->id, $line->refresh()->transaction_id);
        $this->assertSame('reconciled', $line->status);
        $this->assertSame('resolved', $case->refresh()->status);
        $this->assertSame(1, StatementEntry::count());
    }

    public function test_approving_a_short_payment_for_the_amount_received_closes_its_amount_mismatch_case()
    {
        $payin = $this->submittedPayin(['amount' => 500000]);
        $this->addLine(['credit' => '500'])->assertSessionHasNoErrors();
        $case = ReconciliationCase::sole();
        $this->assertSame('amount_mismatch', $case->type->value);

        $candidates = $this->partial($this->owner, route('branch.cases.index', ['case' => $case->id]), 'reconciliation/cases', 'candidates')->json('props.candidates');
        $this->assertSame([[$payin->reference, false]], array_map(fn (array $candidate) => [$candidate['reference'], $candidate['same_amount']], $candidates));
        $this->actingAs($this->owner)->post(route('branch.cases.link', $case), ['transaction_id' => $payin->id])
            ->assertSessionHasErrors('transaction');

        $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '626812820491', 'received_amount' => '500', 'note' => 'Paid 500 only'])
            ->assertSessionHasNoErrors();

        $this->assertSame('reconciled', $this->line()->status);
        $this->assertSame($payin->id, $this->line()->transaction_id);
        $this->assertSame('resolved', $case->refresh()->status);
    }

    public function test_money_for_a_declined_deposit_with_another_amount_is_approved_late_for_what_arrived()
    {
        $payin = $this->submittedPayin(['amount' => 500000]);
        $this->actingAs($this->operator)->post(route('branch.deposits.decline', $payin), ['reason_code' => 'wrong_amount'])->assertSessionHasNoErrors();

        $this->addLine(['credit' => '500'])->assertSessionHasNoErrors();
        $case = ReconciliationCase::sole();
        $this->assertSame('late_payment', $case->type->value);
        $this->assertStringContainsString('500.00', (string) $case->notes);

        $this->actingAs($this->admin)->post(route('admin.cases.approve-late', $case), ['transaction_id' => $payin->id])->assertSessionHasNoErrors();

        $payin->refresh();
        $this->assertSame('success', $payin->status);
        $this->assertSame(50000, $payin->amount);
        $this->assertSame(500000, $payin->order_amount);
        $this->assertSame(50000 - 3000, app(Ledger::class)->balance(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id));
        $this->assertSame('reconciled', $this->line()->status);
        $this->assertSame('resolved', $case->refresh()->status);
    }

    public function test_a_utr_of_another_branchs_deposit_is_a_wrong_branch_case()
    {
        $other = Branch::factory()->create();
        $this->mapPair($this->partner, $other);
        $otherAccount = $this->activeAccount($other);
        // Only the other branch's account can take the deposit.
        $this->account->update(['status' => 'inactive']);
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

        $this->postProof($token, '6268 1282 0491')->assertSessionHasNoErrors();

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

    public function test_the_same_line_twice_and_the_same_utr_on_another_line_are_refused()
    {
        $this->submittedPayin(['amount' => 500000]);
        $this->addLine();

        $this->addLine()->assertSessionHasErrors('utr');
        $this->assertSame(1, StatementEntry::count());

        // Client request 2026-11-14: the same UTR is refused when typed, whatever the date or amount.
        $yesterday = now(config('app.business_timezone'))->subDay()->toDateString();
        $this->addLine(['value_date' => $yesterday, 'credit' => '700'])
            ->assertSessionHasErrors(['utr' => __('UTR :utr is already on a credit line of ₹5,000.00 (:account, :date, added :when). One UTR is one bank transfer: check the number; Admin can correct a wrong line.', [
                'utr' => '626812820491',
                'account' => $this->account->label,
                'date' => now(config('app.business_timezone'))->format('d M Y'),
                'when' => StatementEntry::sole()->created_at->timezone(config('app.business_timezone'))->format('d M Y, H:i'),
            ])]);
        $this->assertSame(1, StatementEntry::count());
        $this->assertSame(0, ReconciliationCase::count());
    }

    /**
     * The deposit went to the branch's second account, but the line is typed
     * under the first one: a "wrong account" case.
     */
    private function lineTypedUnderTheWrongAccount(): array
    {
        $second = $this->activeAccount($this->branch);
        $this->account->update(['status' => 'inactive']);
        $payin = $this->submittedPayin(['amount' => 500000]);
        $this->assertSame($second->id, $payin->payment_account_id);
        $this->account->update(['status' => 'active']);

        $this->addLine()->assertSessionHasNoErrors();
        $case = ReconciliationCase::sole();
        $this->assertSame('wrong_branch', $case->type->value);

        return [$second, $payin, $case];
    }

    public function test_a_line_typed_under_the_wrong_account_moves_to_the_right_one_and_settles()
    {
        [$second, $payin, $case] = $this->lineTypedUnderTheWrongAccount();

        $this->partial($this->owner, route('branch.cases.index', ['case' => $case->id]), 'reconciliation/cases', 'cases')
            ->assertJsonPath('props.cases.data.0.move_line.0.id', $second->id);

        // The operator role may not resolve cases; another branch's account is refused.
        $this->actingAs($this->operator)->post(route('branch.cases.move-line', $case), ['payment_account_id' => $second->id, 'note' => 'x'])->assertForbidden();
        $foreign = $this->activeAccount(Branch::factory()->create());
        $this->actingAs($this->owner)->post(route('branch.cases.move-line', $case), ['payment_account_id' => $foreign->id, 'note' => 'x'])->assertNotFound();
        $this->actingAs($this->owner)->post(route('branch.cases.move-line', $case), ['payment_account_id' => $second->id])->assertSessionHasErrors('note');

        $this->actingAs($this->owner)->post(route('branch.cases.move-line', $case), ['payment_account_id' => $second->id, 'note' => 'Typed under the wrong account'])
            ->assertSessionHasNoErrors();

        $line = $this->line();
        $this->assertSame($second->id, $line->payment_account_id);
        $this->assertSame('matched', $line->status);
        $this->assertSame($payin->id, $line->transaction_id);
        $this->assertSame('linked', $case->refresh()->resolution->value);
        $this->assertStringContainsString('Typed under the wrong account', (string) $case->notes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'statement.entry_moved', 'subject_id' => $line->id, 'actor_id' => $this->owner->id]);

        // Linked: nothing more to move.
        $this->actingAs($this->owner)->post(route('branch.cases.move-line', $case), ['payment_account_id' => $this->account->id, 'note' => 'x'])
            ->assertSessionHasErrors('case');
    }

    public function test_closing_the_wrong_line_lets_the_same_line_typed_again_on_the_right_account_settle()
    {
        [$second, $payin, $wrong] = $this->lineTypedUnderTheWrongAccount();

        // Typed again under the right account: refused while the wrong line holds the UTR.
        $this->addLine(['payment_account_id' => $second->id])->assertSessionHasErrors('utr');

        $this->actingAs($this->owner)->post(route('branch.cases.close', $wrong), ['resolution' => 'rejected', 'note' => 'Typed under the wrong account by mistake'])
            ->assertSessionHasNoErrors();

        // Closed as not a customer payment, the wrong line no longer holds it.
        $this->addLine(['payment_account_id' => $second->id])->assertSessionHasNoErrors();
        $right = StatementEntry::where('payment_account_id', $second->id)->sole();
        $this->assertSame('matched', $right->status);
        $this->assertSame($payin->id, $right->transaction_id);
        $this->assertSame(0, ReconciliationCase::query()->where('status', '!=', 'resolved')->count());
    }

    public function test_only_admin_corrects_a_lines_utr_and_amount_and_it_is_matched_again()
    {
        $payin = $this->submittedPayin(['amount' => 500000]);
        // The branch typed a wrong UTR and amount.
        $this->addLine(['utr' => '626812820999', 'description' => 'UPI typo', 'credit' => '50'])->assertSessionHasNoErrors();
        $this->addLine(['utr' => '111122223333', 'description' => 'Other', 'credit' => '100'])->assertSessionHasNoErrors();
        $wrong = $this->line('626812820999');
        $case = $wrong->openCase()->sole();
        $url = route('admin.cases.edit-line', $case);
        $fix = ['utr' => '626812820491', 'amount' => '5000', 'note' => 'Branch mistyped the UTR'];

        // Client request 2026-11-14: branch users can't (no branch route, the admin portal refuses them).
        $this->assertFalse(Route::has('branch.cases.edit-line'));
        $this->actingAs($this->owner)->post($url, $fix)->assertForbidden();
        $viewer = User::factory()->admin(SystemRoles::ADMIN_VIEWER)->withTwoFactor()->create();
        $this->actingAs($viewer)->post($url, $fix)->assertForbidden();

        // A UTR already on another line is refused; a reason is required.
        $this->actingAs($this->admin)->post($url, [...$fix, 'utr' => '111122223333'])->assertSessionHasErrors('utr');
        $this->actingAs($this->admin)->post($url, [...$fix, 'note' => ''])->assertSessionHasErrors('note');

        $this->actingAs($this->admin)->post($url, $fix)->assertSessionHasNoErrors();

        $wrong->refresh();
        $this->assertSame(['626812820491', 500000], [$wrong->utr_normalized, $wrong->amount]);
        $this->assertSame($payin->id, $wrong->transaction_id);
        $this->assertSame('linked', $case->refresh()->resolution->value);
        $this->assertStringContainsString('Branch mistyped the UTR', (string) $case->notes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'statement.entry_edited', 'subject_id' => $wrong->id, 'actor_id' => $this->admin->id]);

        // Settled: nothing more to correct.
        $this->actingAs($this->admin)->post($url, [...$fix, 'amount' => '4000'])->assertSessionHasErrors('case');
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

        // The branch records that transfer: the waiting debit links itself to it.
        $part = PayoutPayment::create([
            'transaction_id' => $payout->id, 'branch_id' => $this->branch->id, 'amount' => 250000,
            'utr' => 'IMPS601234567890', 'utr_normalized' => 'IMPS601234567890', 'paid_at' => now(), 'created_at' => now(),
        ]);
        $payout->forceFill(['status' => 'success', 'bank_utr_normalized' => 'IMPS601234567890', 'partner_commission' => 0, 'branch_commission' => 0, 'platform_margin' => 0, 'succeeded_at' => now()])->save();
        DB::transaction(fn () => app(StatementMatcher::class)->payoutPartPaid($part, $payout));

        $this->assertSame('reconciled', $this->line('IMPS601234567890')->status);
        $this->assertSame($part->id, $this->line('IMPS601234567890')->payout_payment_id);
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
            ->assertJsonPath('props.detail.reconciliation.transaction_branch', $this->branch->name);

        $this->actingAs($partnerUser)->get(route('partner.payins.index'))
            ->assertInertia(fn ($page) => $page->where('transactions.data.0.bank_line', null));
    }

    public function test_admin_filters_lines_by_several_branches_and_sees_the_approved_total()
    {
        $payin = $this->submittedPayin(['amount' => 500000]);
        $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '626812820491']);
        $this->addLine()->assertSessionHasNoErrors();

        $other = Branch::factory()->create();
        $otherAccount = $this->activeAccount($other);
        $this->addLine(['payment_account_id' => $otherAccount->id, 'utr' => '626812820999', 'credit' => '700'], $this->admin)->assertSessionHasNoErrors();
        $third = Branch::factory()->create();

        $lines = fn (array $branches) => $this->actingAs($this->admin)->get(route('admin.statements.index', ['branches' => $branches]))->viewData('page')['props'];

        $this->assertCount(2, $lines([])['items']['data']);
        $this->assertCount(1, $lines([$this->branch->id])['items']['data']);
        $this->assertCount(2, $lines([$this->branch->id, $other->id])['items']['data']);
        $this->assertCount(0, $lines([$third->id])['items']['data']);

        $this->assertSame(500000, $lines([])['kpis']['approved']);
        $this->assertSame(0, $lines([$other->id])['kpis']['approved']);
    }
}
