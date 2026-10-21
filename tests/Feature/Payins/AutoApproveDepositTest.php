<?php

namespace Tests\Feature\Payins;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Notification\Enums\Alert;
use App\Domain\Notification\Notifications\PortalAlert;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Transaction\Actions\DecidePayin;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * Auto-approval (decided 2026-10-21): a branch enters the UTR and amount
 * that reached its account; a waiting deposit with that UTR and exact amount
 * is approved by itself — moved first if it waits at another branch. Anything
 * unclear (repeated UTR, other amount, on hold, unmapped branch) stays for a
 * person.
 */
class AutoApproveDepositTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private const UTR = '626812820491';

    private PaymentAccount $account;

    private Branch $paidBranch;

    private PaymentAccount $paidAccount;

    private User $admin;

    private User $operator;

    private User $paidOperator;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->buildNetwork();
        $this->partner->update(['payin_webhook_url' => 'https://atoz.example/hooks/payin']);
        $this->account = $this->activeAccount();
        $this->paidBranch = Branch::factory()->create();
        $this->mapPair($this->partner, $this->paidBranch);
        $this->paidAccount = $this->activeAccount($this->paidBranch);
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->rate('branch', $this->paidBranch->id, 'deposit', '4');
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
        $this->paidOperator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->paidBranch)->withTwoFactor()->create();
    }

    /** A deposit allocated to $this->account (the paid branch's account is busy then). */
    private function depositAtFirstBranch(string $utr = self::UTR, int $amount = 500000): Transaction
    {
        $this->paidAccount->update(['status' => 'inactive']);
        $payin = $this->submittedPayin(['amount' => $amount], $utr);
        $this->paidAccount->update(['status' => 'active']);
        $this->assertSame($this->account->id, $payin->payment_account_id);

        return $payin;
    }

    private function addLine(User $as, PaymentAccount $account, string $credit = '5000', string $utr = self::UTR)
    {
        return $this->actingAs($as)->post(route($as->type->value.'.statements.store'), [
            'payment_account_id' => $account->id,
            'value_date' => now(config('app.business_timezone'))->toDateString(),
            'utr' => $utr,
            'credit' => $credit,
        ]);
    }

    private function line(string $utr = self::UTR): StatementEntry
    {
        return StatementEntry::where('utr_normalized', $utr)->sole();
    }

    private function alerted(User $user, Alert $alert): int
    {
        return Notification::sent($user, PortalAlert::class, fn (PortalAlert $sent) => $sent->alert === $alert)->count();
    }

    public function test_the_branch_enters_utr_and_amount_and_the_waiting_deposit_is_approved()
    {
        $payin = $this->depositAtFirstBranch();

        $this->addLine($this->operator, $this->account)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast', fn ($toast) => str_contains($toast['message'], 'approved automatically'));

        $payin->refresh();
        $this->assertSame('success', $payin->status);
        $this->assertSame(self::UTR, $payin->bank_utr_normalized);
        $this->assertSame($this->operator->id, $payin->decided_by);
        $this->assertSame(30000, $payin->partner_commission);
        $this->assertSame('reconciled', $this->line()->status);
        $approved = TransactionEvent::where(['transaction_id' => $payin->id, 'event' => 'approved'])->sole();
        $this->assertTrue($approved->data['auto']);
        $this->assertSame(1, DB::table('webhook_events')->where(['transaction_id' => $payin->id, 'event_type' => 'payin.success'])->count());
    }

    public function test_a_line_entered_before_the_customers_utr_approves_when_the_utr_arrives()
    {
        $this->addLine($this->operator, $this->account)->assertSessionHasNoErrors();
        $this->assertSame('utr_not_found', ReconciliationCase::sole()->type->value);

        $payin = $this->depositAtFirstBranch();

        $this->assertSame('success', $payin->refresh()->status);
        $this->assertSame('resolved', ReconciliationCase::sole()->status);
    }

    public function test_money_at_another_branch_moves_the_deposit_there_and_approves_it()
    {
        // The customer paid the paid branch's account earlier, then entered the UTR on a deposit given to the first branch.
        $payin = $this->depositAtFirstBranch();

        $this->addLine($this->paidOperator, $this->paidAccount)->assertSessionHasNoErrors();

        $payin->refresh();
        $this->assertSame('success', $payin->status);
        $this->assertSame($this->paidBranch->id, $payin->branch_id);
        $this->assertSame($this->paidAccount->id, $payin->payment_account_id);
        $this->assertTrue(TransactionEvent::where(['transaction_id' => $payin->id, 'event' => 'moved'])->exists());
        $this->assertSame('resolved', ReconciliationCase::sole()->status);
        // Admin and the first branch are told; the paid branch gets no "check and approve" alert.
        $this->assertSame(1, $this->alerted($this->admin, Alert::DepositAutoMoved));
        $this->assertSame(1, $this->alerted($this->operator, Alert::DepositAutoMoved));
        $this->assertSame(0, $this->alerted($this->paidOperator, Alert::DepositMoved));
    }

    public function test_the_customers_utr_after_a_line_at_another_branch_also_moves_and_approves()
    {
        $this->addLine($this->paidOperator, $this->paidAccount)->assertSessionHasNoErrors();

        // Allocated to the first branch, then moved and approved as soon as the UTR is entered.
        $this->paidAccount->update(['status' => 'inactive']);
        [$reference, $token] = $this->createPayin(['amount' => 500000]);
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi']);
        $this->paidAccount->update(['status' => 'active']);
        $this->assertSame($this->account->id, Transaction::where('reference', $reference)->sole()->payment_account_id);
        $this->post("http://pay.paygate.local/p/{$token}/proof", ['utr' => self::UTR])->assertSessionHasNoErrors();

        $payin = Transaction::where('reference', $reference)->sole();
        $this->assertSame('success', $payin->status);
        $this->assertSame($this->paidBranch->id, $payin->branch_id);
    }

    public function test_a_deposit_the_branch_reported_not_in_its_bank_is_moved_and_approved()
    {
        $payin = $this->depositAtFirstBranch();
        $this->actingAs($this->operator)->post(route('branch.deposits.not-in-bank', $payin), ['note' => 'Not in our account'])->assertSessionHasNoErrors();
        $this->assertSame('under_review', $payin->refresh()->status);

        $this->addLine($this->paidOperator, $this->paidAccount)->assertSessionHasNoErrors();

        $this->assertSame('success', $payin->refresh()->status);
        $this->assertSame($this->paidBranch->id, $payin->branch_id);
    }

    public function test_a_branch_not_mapped_for_deposits_leaves_it_to_admin()
    {
        $payin = $this->depositAtFirstBranch();
        PartnerBranchMapping::where(['partner_id' => $this->partner->id, 'branch_id' => $this->paidBranch->id])->update(['is_deposit_enabled' => false]);

        $this->addLine($this->paidOperator, $this->paidAccount)->assertSessionHasNoErrors();

        $this->assertSame('payment_submitted', $payin->refresh()->status);
        $this->assertSame($this->branch->id, $payin->branch_id);
        $this->assertSame('wrong_branch', ReconciliationCase::sole()->type->value);
    }

    public function test_a_different_amount_or_a_deliberate_hold_is_never_approved_by_itself()
    {
        $payin = $this->depositAtFirstBranch();
        $this->addLine($this->operator, $this->account, credit: '4000')->assertSessionHasNoErrors();
        $this->assertSame('payment_submitted', $payin->refresh()->status);
        $this->assertSame('amount_mismatch', ReconciliationCase::sole()->type->value);

        $held = $this->depositAtFirstBranch('626812820492');
        $this->actingAs($this->operator)->post(route('branch.deposits.hold', $held), ['reason' => 'Checking with the customer'])->assertSessionHasNoErrors();
        $this->addLine($this->operator, $this->account, utr: '626812820492')->assertSessionHasNoErrors();
        $this->assertSame('under_review', $held->refresh()->status);
        $this->assertSame('matched', $this->line('626812820492')->status);
    }

    public function test_with_auto_approval_off_the_branch_approves_by_hand()
    {
        $this->manualApproval();
        $payin = $this->depositAtFirstBranch();

        $this->addLine($this->operator, $this->account)->assertSessionHasNoErrors();

        $this->assertSame('payment_submitted', $payin->refresh()->status);
        $this->assertSame('matched', $this->line()->status);
    }

    public function test_two_customers_claiming_one_utr_the_bank_line_picks_the_real_one()
    {
        // A fraudster types the real customer's UTR first, on a deposit at the first branch…
        $fake = $this->depositAtFirstBranch();
        // …the real customer paid the paid branch and enters the same UTR: accepted, both flagged.
        $this->account->update(['status' => 'inactive']);
        $real = $this->submittedPayin(['amount' => 500000], self::UTR);
        $this->account->update(['status' => 'active']);
        $this->assertSame($this->paidAccount->id, $real->payment_account_id);
        $this->assertSame(1, $this->alerted($this->admin, Alert::UtrConflict));

        $this->addLine($this->paidOperator, $this->paidAccount)->assertSessionHasNoErrors();

        $this->assertSame('success', $real->refresh()->status);
        // The fake is taken off the pending list: on hold as a duplicate, for a person to decline.
        $this->assertSame('under_review', $fake->refresh()->status);
        $this->assertSame(DecidePayin::DUPLICATE_UTR, $fake->status_reason_code);
        $this->assertSame($this->branch->id, $fake->branch_id);
        $flag = TransactionEvent::where(['transaction_id' => $fake->id, 'event' => 'utr_conflict'])->latest('created_at')->first();
        $this->assertSame($real->reference, $flag->data['approved']);
        $this->assertSame(2, $this->alerted($this->admin, Alert::UtrConflict));
    }

    public function test_two_claims_and_a_line_on_neither_account_wait_for_admin()
    {
        $first = $this->depositAtFirstBranch();
        $this->account->update(['status' => 'inactive']);
        $second = $this->submittedPayin(['amount' => 500000], self::UTR);
        $this->account->update(['status' => 'active']);
        $third = $this->activeAccount($this->paidBranch);

        $this->addLine($this->paidOperator, $third)->assertSessionHasNoErrors();

        $this->assertSame('payment_submitted', $first->refresh()->status);
        $this->assertSame('payment_submitted', $second->refresh()->status);
    }

    public function test_the_same_utr_on_a_second_bank_line_alerts_admin_and_approves_nothing()
    {
        $this->addLine($this->operator, $this->account)->assertSessionHasNoErrors();
        $this->addLine($this->paidOperator, $this->paidAccount)->assertSessionHasNoErrors();

        $this->assertSame('duplicate_utr', ReconciliationCase::where('type', 'duplicate_utr')->sole()->type->value);
        $this->assertSame(1, $this->alerted($this->admin, Alert::UtrConflict));

        // The customer's UTR now matches two bank lines: nothing is approved by itself.
        $payin = $this->depositAtFirstBranch();
        $this->assertNotSame('success', $payin->refresh()->status);
    }

    public function test_a_fake_utr_declined_earlier_does_not_block_the_real_customer_later()
    {
        $fake = $this->depositAtFirstBranch();
        $this->actingAs($this->operator)->post(route('branch.deposits.decline', $fake), ['reason_code' => 'payment_not_found'])->assertSessionHasNoErrors();

        $real = $this->depositAtFirstBranch();
        $this->addLine($this->operator, $this->account)->assertSessionHasNoErrors();

        $this->assertSame('success', $real->refresh()->status);
        $event = TransactionEvent::where(['transaction_id' => $real->id, 'event' => 'proof_submitted'])->sole();
        $this->assertSame($fake->reference, $event->data['possible_duplicate_of']);
    }

    public function test_a_utr_of_an_approved_deposit_is_refused_to_anyone_else()
    {
        $this->depositAtFirstBranch();
        $this->addLine($this->operator, $this->account)->assertSessionHasNoErrors();

        [, $token] = $this->createPayin(['amount' => 500000]);
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi']);
        $this->post("http://pay.paygate.local/p/{$token}/proof", ['utr' => self::UTR])->assertSessionHasErrors('utr');
    }
}
