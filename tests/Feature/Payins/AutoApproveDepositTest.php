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

    private const CUSTOMER = ['id' => 'abc', 'name' => 'ABC'];

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
        $payin = $this->submittedPayin(['amount' => $amount, 'customer' => self::CUSTOMER], $utr);
        $this->paidAccount->update(['status' => 'active']);
        $this->assertSame($this->account->id, $payin->payment_account_id);

        return $payin;
    }

    /** Customer ABC was shown the paid branch's account on an earlier deposit (then the browser closed). */
    private function givenPaidAccountEarlier(): void
    {
        $this->account->update(['status' => 'inactive']);
        [, $token] = $this->createPayin(['amount' => 500000, 'customer' => self::CUSTOMER]);
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi'])->assertSessionHasNoErrors();
        $this->account->update(['status' => 'active']);
        $this->travel(3)->minutes(); // past the double-click guard
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
        $this->givenPaidAccountEarlier();
        $payin = $this->depositAtFirstBranch();

        $this->addLine($this->paidOperator, $this->paidAccount)->assertSessionHasNoErrors();

        $payin->refresh();
        $this->assertSame('success', $payin->status);
        $this->assertSame($this->paidBranch->id, $payin->branch_id);
        $this->assertSame($this->paidAccount->id, $payin->payment_account_id);
        // Recorded as automatic, not as the person's own move.
        $this->assertTrue(TransactionEvent::where(['transaction_id' => $payin->id, 'event' => 'moved'])->sole()->data['auto'] ?? false);
        $this->assertSame('resolved', ReconciliationCase::sole()->status);
        // Admin and the first branch are told; the paid branch gets no "check and approve" alert.
        $this->assertSame(1, $this->alerted($this->admin, Alert::DepositAutoMoved));
        $this->assertSame(1, $this->alerted($this->operator, Alert::DepositAutoMoved));
        $this->assertSame(0, $this->alerted($this->paidOperator, Alert::DepositMoved));
    }

    public function test_the_customers_utr_after_a_line_at_another_branch_also_moves_and_approves()
    {
        $this->givenPaidAccountEarlier();
        $this->addLine($this->paidOperator, $this->paidAccount)->assertSessionHasNoErrors();

        // Allocated to the first branch, then moved and approved as soon as the UTR is entered.
        $this->paidAccount->update(['status' => 'inactive']);
        [$reference, $token] = $this->createPayin(['amount' => 500000, 'customer' => self::CUSTOMER]);
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi']);
        $this->paidAccount->update(['status' => 'active']);
        $this->assertSame($this->account->id, Transaction::where('reference', $reference)->sole()->payment_account_id);
        $this->postProof($token, self::UTR)->assertSessionHasNoErrors();

        $payin = Transaction::where('reference', $reference)->sole();
        $this->assertSame('success', $payin->status);
        $this->assertSame($this->paidBranch->id, $payin->branch_id);
    }

    /**
     * Client scenario 2026-11-17: ABC paid ₹500 into the paid branch's
     * account on request 1 without entering the UTR, then typed that UTR on a
     * new ₹1,000 request given to the first branch. The paid branch's line
     * (₹500) moves the request there, but doesn't approve the other amount.
     */
    public function test_a_different_amount_at_another_branch_moves_the_deposit_for_the_branch_to_decide()
    {
        $this->givenPaidAccountEarlier();
        $payin = $this->depositAtFirstBranch(amount: 1000000);

        $this->addLine($this->paidOperator, $this->paidAccount, credit: '5000')->assertSessionHasNoErrors();

        $payin->refresh();
        $this->assertSame('payment_submitted', $payin->status);
        $this->assertSame($this->paidBranch->id, $payin->branch_id);
        $this->assertSame($this->paidAccount->id, $payin->payment_account_id);
        $case = ReconciliationCase::sole();
        $this->assertSame('open', $case->status);
        $this->assertSame('amount_mismatch', $case->type->value);
        $this->assertSame($payin->id, $case->transaction_id);
        // The paid branch is asked to check it; Admin and the first branch are told it moved.
        $this->assertSame(1, $this->alerted($this->paidOperator, Alert::DepositMoved));
        $this->assertSame(1, $this->alerted($this->admin, Alert::DepositAutoMoved));
        $this->assertSame(1, $this->alerted($this->operator, Alert::DepositAutoMoved));

        // A different amount is never approved: the paid branch declines it.
        $this->actingAs($this->paidOperator)->post(route('branch.deposits.approve', $payin), [
            'bank_utr' => self::UTR,
            'received_amount' => '5000',
            'note' => 'Customer paid ₹5,000 of ₹10,000',
        ])->assertSessionHasErrors('received_amount');
        $this->actingAs($this->paidOperator)->post(route('branch.deposits.decline', $payin), ['reason_code' => 'wrong_amount'])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $payin->refresh()->status);
        $this->assertSame('open', $case->refresh()->status);
    }

    public function test_the_customers_utr_after_a_line_of_another_amount_also_moves_the_deposit()
    {
        $this->givenPaidAccountEarlier();
        $this->addLine($this->paidOperator, $this->paidAccount, credit: '5000')->assertSessionHasNoErrors();

        // A ₹1,000 request allocated to the first branch; the UTR is entered afterwards.
        $this->paidAccount->update(['status' => 'inactive']);
        [$reference, $token] = $this->createPayin(['amount' => 1000000, 'customer' => self::CUSTOMER]);
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi']);
        $this->paidAccount->update(['status' => 'active']);
        $this->postProof($token, self::UTR)->assertSessionHasNoErrors();

        $payin = Transaction::where('reference', $reference)->sole();
        $this->assertSame('payment_submitted', $payin->status);
        $this->assertSame($this->paidBranch->id, $payin->branch_id);
        $this->assertSame('amount_mismatch', ReconciliationCase::sole()->type->value);
    }

    public function test_a_deposit_the_branch_reported_not_in_its_bank_is_moved_and_approved()
    {
        $this->givenPaidAccountEarlier();
        $payin = $this->depositAtFirstBranch();
        $this->actingAs($this->operator)->post(route('branch.deposits.not-in-bank', $payin), ['note' => 'Not in our account'])->assertSessionHasNoErrors();
        $this->assertSame('under_review', $payin->refresh()->status);

        $this->addLine($this->paidOperator, $this->paidAccount)->assertSessionHasNoErrors();

        $this->assertSame('success', $payin->refresh()->status);
        $this->assertSame($this->paidBranch->id, $payin->branch_id);
    }

    public function test_a_branch_not_mapped_for_deposits_leaves_it_to_admin()
    {
        $this->givenPaidAccountEarlier();
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

    public function test_a_customer_never_given_that_account_is_not_moved_by_itself()
    {
        // A fraudster types a real customer's UTR on a deposit at the first branch; the money is at the paid branch.
        $fake = $this->depositAtFirstBranch();

        // The real customer is refused: that UTR is pending. Admin is alerted.
        $this->account->update(['status' => 'inactive']);
        [, $token] = $this->createPayin(['amount' => 500000]);
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi']);
        $this->postProof($token, self::UTR)->assertSessionHasErrors('utr');
        $this->account->update(['status' => 'active']);
        $this->assertSame(1, $this->alerted($this->admin, Alert::UtrConflict));

        // ABC was never shown the paid branch's account: no automatic move, Admin decides.
        $this->addLine($this->paidOperator, $this->paidAccount)->assertSessionHasNoErrors();

        $this->assertSame('payment_submitted', $fake->refresh()->status);
        $this->assertSame($this->branch->id, $fake->branch_id);
        $this->assertSame('wrong_branch', ReconciliationCase::sole()->type->value);
    }

    public function test_another_branchs_utr_typed_on_a_bank_line_is_refused_and_admin_alerted()
    {
        $this->addLine($this->operator, $this->account)->assertSessionHasNoErrors();
        // Client request 2026-11-14: refused when typed; the other branch's line is not described.
        $this->addLine($this->paidOperator, $this->paidAccount)
            ->assertSessionHasErrors(['utr' => __('UTR :utr is already on a bank line. One UTR is one bank transfer: check the number, or ask Admin.', ['utr' => self::UTR])]);

        $this->assertSame(0, ReconciliationCase::where('type', 'duplicate_utr')->count());
        $this->assertSame(1, $this->alerted($this->admin, Alert::UtrConflict));

        // The first branch's line still approves its deposit.
        $payin = $this->depositAtFirstBranch();
        $this->assertSame('success', $payin->refresh()->status);
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
        $this->postProof($token, self::UTR)->assertSessionHasErrors('utr');
    }
}
