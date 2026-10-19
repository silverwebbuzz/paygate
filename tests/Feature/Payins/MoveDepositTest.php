<?php

namespace Tests\Feature\Payins;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Notification\Enums\Alert;
use App\Domain\Notification\Notifications\PortalAlert;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * The customer paid one branch's account but submitted their UTR on a later
 * request given to another branch: the branch reports it, Admin moves it.
 */
class MoveDepositTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

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
        $this->buildNetwork();
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

    private function counter(string $scope, string $id, string $column = 'reserved_amount'): int
    {
        return (int) DB::table('usage_counters')
            ->where(['scope_type' => $scope, 'scope_id' => $id, 'business_date' => UsageCounters::businessDate(), 'direction' => 'deposit'])
            ->value($column);
    }

    /** A deposit at $this->branch (the paid branch's account is busy at that moment). */
    private function wrongDeposit(): Transaction
    {
        $this->paidAccount->update(['status' => 'inactive']);
        $payin = $this->submittedPayin(['amount' => 500000]);
        $this->paidAccount->update(['status' => 'active']);
        $this->assertSame($this->account->id, $payin->payment_account_id);

        return $payin;
    }

    private function move(Transaction $payin, ?PaymentAccount $to = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('admin.deposits.move', $payin), [
            'payment_account_id' => ($to ?? $this->paidAccount)->id,
            'reason' => 'Customer paid HDFC of the other branch',
        ]);
    }

    public function test_admin_moves_a_deposit_with_its_reservation_and_the_new_branch_approves_it()
    {
        Notification::fake();
        $payin = $this->wrongDeposit();

        $this->actingAs($this->admin)->get(route('admin.deposits.index', ['move' => $payin->id]), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'deposits/index',
            'X-Inertia-Partial-Data' => 'move_options',
            'X-Inertia-Version' => Inertia::getVersion(),
        ])->assertJsonPath('props.move_options.0.id', $this->paidAccount->id);

        $this->move($payin)->assertSessionHasNoErrors();

        $payin->refresh();
        $this->assertSame($this->paidBranch->id, $payin->branch_id);
        $this->assertSame($this->paidAccount->id, $payin->payment_account_id);
        $this->assertSame('payment_submitted', $payin->status);
        $this->assertSame(0, $this->counter('account', $this->account->id));
        $this->assertSame(0, $this->counter('branch', $this->branch->id));
        $this->assertSame(500000, $this->counter('account', $this->paidAccount->id));
        $this->assertSame(500000, $this->counter('branch', $this->paidBranch->id));
        $this->assertSame(500000, $this->counter('partner', $this->partner->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payin.moved']);
        $this->assertCount(1, Notification::sent($this->paidOperator, PortalAlert::class, fn (PortalAlert $alert) => $alert->alert === Alert::DepositMoved));

        // The old branch no longer sees it; the new one decides it.
        $this->actingAs($this->operator)->get(route('branch.deposits.index'))->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
        $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '626812820491'])->assertForbidden();
        $this->actingAs($this->paidOperator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '626812820491'])->assertSessionHasNoErrors();

        $this->assertSame('success', $payin->fresh()->status);
        $this->assertSame(0, $this->counter('account', $this->paidAccount->id));
        $this->assertSame(500000, $this->counter('account', $this->paidAccount->id, 'confirmed_amount'));
        $this->assertSame(500000, $this->counter('partner', $this->partner->id, 'confirmed_amount'));
    }

    public function test_only_admin_moves_only_waiting_deposits_to_mapped_branches()
    {
        $payin = $this->wrongDeposit();
        $unmapped = $this->activeAccount(Branch::factory()->create());

        $this->move($payin, as: $this->operator)->assertForbidden();
        $this->move($payin, $this->account)->assertSessionHasErrors('payment_account_id');
        $this->move($payin, $unmapped)->assertSessionHasErrors('payment_account_id');
        $this->assertSame($this->account->id, $payin->fresh()->payment_account_id);

        $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '626812820491']);
        $this->move($payin)->assertSessionHasErrors('payment_account_id');
    }

    public function test_the_branch_reports_money_not_in_its_bank_and_admin_is_alerted()
    {
        Notification::fake();
        $payin = $this->wrongDeposit();

        $this->actingAs($this->operator)->post(route('branch.deposits.not-in-bank', $payin), ['note' => 'Not in our HDFC statement'])->assertSessionHasNoErrors();

        $payin->refresh();
        $this->assertSame('under_review', $payin->status);
        $this->assertSame('not_in_our_bank', $payin->status_reason_code);
        $this->assertSame('Not in our HDFC statement', $payin->status_note);
        $this->assertCount(1, Notification::sent($this->admin, PortalAlert::class, fn (PortalAlert $alert) => $alert->alert === Alert::DepositNotInBank));

        $this->actingAs($this->operator)->post(route('branch.deposits.not-in-bank', $payin))->assertSessionHasErrors('note');

        // Moving puts it back in the new branch's pending queue.
        $this->move($payin)->assertSessionHasNoErrors();
        $payin->refresh();
        $this->assertSame('payment_submitted', $payin->status);
        $this->assertNull($payin->status_reason_code);
        $this->actingAs($this->paidOperator)->get(route('branch.deposits.index'))
            ->assertInertia(fn (Assert $page) => $page->where('items.data.0.reference', $payin->reference));
    }

    public function test_a_wrong_branch_bank_line_moves_the_deposit_and_links_itself()
    {
        $payin = $this->wrongDeposit();

        $this->actingAs($this->paidOperator)->post(route('branch.statements.store'), [
            'payment_account_id' => $this->paidAccount->id,
            'value_date' => now(config('app.business_timezone'))->toDateString(),
            'utr' => '626812820491',
            'description' => 'UPI/626812820491/Payment',
            'credit' => '5000',
        ])->assertSessionHasNoErrors();

        $case = ReconciliationCase::sole();
        $this->assertSame('wrong_branch', $case->type->value);

        $this->actingAs($this->admin)->get(route('admin.cases.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cases.data.0.move.reference', $payin->reference)
                ->where('cases.data.0.move.branch', $this->branch->code));
        $this->actingAs($this->paidOperator)->get(route('branch.cases.index'))
            ->assertInertia(fn (Assert $page) => $page->where('cases.data.0.move', null));
        $this->actingAs($this->paidOperator)->post(route('admin.cases.move', $case), ['note' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.cases.move', $case), ['note' => 'Paid to this account'])->assertSessionHasNoErrors();

        $payin->refresh();
        $this->assertSame($this->paidAccount->id, $payin->payment_account_id);
        $this->assertSame('resolved', $case->fresh()->status);
        $this->assertSame($payin->id, StatementEntry::sole()->transaction_id);
        $this->assertSame('matched', StatementEntry::sole()->status);

        $this->actingAs($this->paidOperator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '626812820491'])->assertSessionHasNoErrors();
        $this->assertSame('reconciled', StatementEntry::sole()->status);
    }

    public function test_a_deposit_whose_bank_line_is_already_linked_stays_put()
    {
        $payin = $this->wrongDeposit();

        $this->actingAs($this->operator)->post(route('branch.statements.store'), [
            'payment_account_id' => $this->account->id,
            'value_date' => now(config('app.business_timezone'))->toDateString(),
            'utr' => '626812820491',
            'credit' => '5000',
        ])->assertSessionHasNoErrors();
        $this->assertSame($payin->id, StatementEntry::sole()->transaction_id);

        $this->move($payin)->assertSessionHasErrors('payment_account_id');
    }
}
