<?php

namespace Tests\Feature\Payins;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * One UTR is one bank transfer, across the whole system (decided 2026-10-19):
 * across accounts and branches, and between deposits and payouts.
 */
class UtrUniquenessTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private const UTR = '626812820491';

    private User $admin;

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->buildNetwork();
        $this->activeAccount();
        $this->other = Branch::factory()->create();
        $this->mapPair($this->partner, $this->other);
        $this->activeAccount($this->other);
        $this->partner->update(['is_payout_enabled' => true]);
        foreach (['deposit', 'withdrawal'] as $direction) {
            $this->rate('partner', $this->partner->id, $direction, '5');
            $this->rate('branch', $this->branch->id, $direction, '3');
            $this->rate('branch', $this->other->id, $direction, '3');
        }
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
    }

    private function approve(Transaction $payin, string $utr = self::UTR)
    {
        // A bank UTR other than the customer's needs a note (and alerts Admin).
        return $this->actingAs($this->admin)->post(route('admin.deposits.approve', $payin), ['bank_utr' => $utr, 'note' => 'Checked the bank entry']);
    }

    private function payout(): Transaction
    {
        DB::transaction(function () {
            $ledger = app(Ledger::class);
            foreach ([$this->branch, $this->other] as $branch) {
                $ledger->post('adjustment', [
                    $ledger->account(Ledger::PARTNER_POSITION, $this->partner->id, $branch->id) => 1000000,
                    $ledger->account(Ledger::SETTLEMENT_CLEARING) => -1000000,
                ], null, 'Test funding');
            }
        });

        $reference = $this->api('POST', '/v1/payouts', [
            'order_id' => 'WD-'.bin2hex(random_bytes(4)),
            'amount' => 9000,
            'customer' => ['id' => 'cust-9'],
            'beneficiary' => ['type' => 'bank', 'name' => 'Ravi Kumar', 'account_number' => '50100482716640', 'ifsc' => 'HDFC0001203', 'bank_name' => 'HDFC Bank'],
        ])->assertCreated()->json('data.id');

        return Transaction::where('reference', $reference)->firstOrFail();
    }

    private function pay(Transaction $payout, string $utr = self::UTR)
    {
        return $this->actingAs($this->admin)->post(route('admin.payouts.complete', $payout), ['bank_utr' => $utr]);
    }

    public function test_a_bank_utr_approves_one_deposit_on_any_account_or_branch()
    {
        $first = $this->submittedPayin([], '111111111111');
        $second = $this->submittedPayin([], '222222222222');
        $this->assertNotSame($first->payment_account_id, $second->payment_account_id);

        $this->approve($first)->assertSessionHasNoErrors();
        $this->approve($second)->assertSessionHasErrors(['bank_utr' => "UTR 626812820491 already belongs to deposit {$first->reference} (success). One UTR is one bank transfer."]);
        $this->assertSame('payment_submitted', $second->fresh()->status);
    }

    public function test_a_deposit_and_a_payout_never_share_a_utr()
    {
        $payout = $this->payout();
        $this->pay($payout)->assertSessionHasNoErrors();

        // Not as a bank UTR on approval, nor as a customer's claim.
        $payin = $this->submittedPayin([], '333333333333');
        $this->approve($payin)->assertSessionHasErrors('bank_utr');
        [, $token] = $this->createPayin();
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi']);
        $this->post("http://pay.paygate.local/p/{$token}/proof", ['utr' => self::UTR])->assertSessionHasErrors('utr');

        // And a deposit's UTR can't mark a payout paid.
        $this->approve($payin, '444444444444')->assertSessionHasNoErrors();
        $this->pay($this->payout(), '444444444444')->assertSessionHasErrors('bank_utr');
    }

    public function test_a_waiting_customers_utr_cannot_mark_a_payout_paid()
    {
        $this->submittedPayin([], self::UTR);

        $this->pay($this->payout())->assertSessionHasErrors('bank_utr');
    }

    public function test_two_payouts_cannot_be_paid_with_one_utr()
    {
        $first = $this->payout();
        $second = $this->payout();

        $this->pay($first)->assertSessionHasNoErrors();
        $this->pay($second)->assertSessionHasErrors('bank_utr');
        $this->assertNotSame('success', $second->fresh()->status);
    }

    public function test_the_database_refuses_a_second_holder_even_without_the_app_check()
    {
        $first = $this->submittedPayin([], '111111111111');
        $this->approve($first)->assertSessionHasNoErrors();
        $payout = $this->payout();

        $this->expectException(QueryException::class);
        DB::table('transactions')->where('id', $payout->id)->update(['status' => 'success', 'bank_utr_normalized' => self::UTR]);
    }

    public function test_a_statement_utr_already_on_another_accounts_line_opens_a_duplicate_case()
    {
        $accounts = PaymentAccount::query()->orderBy('created_at')->get();
        $line = fn ($account) => $this->actingAs($this->admin)->post(route('admin.statements.store'), [
            'payment_account_id' => $account->id,
            'value_date' => now(config('app.business_timezone'))->toDateString(),
            'utr' => self::UTR,
            'credit' => '700',
        ]);

        $line($accounts[0])->assertSessionHasNoErrors();
        $line($accounts[1])->assertSessionHasNoErrors();

        $case = ReconciliationCase::query()->where('type', 'duplicate_utr')->sole();
        $this->assertSame($this->other->id, $case->branch_id);
        $this->assertStringContainsString('already on a credit line of '.$this->branch->code, (string) $case->notes);
    }
}
