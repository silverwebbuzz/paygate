<?php

namespace Tests\Feature\Settlements;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Transaction\Models\Chargeback;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * Settlement Dashboard: the period's deposits, withdrawals, commission,
 * settlements and chargebacks per side, from the ledger.
 */
class SettlementDashboardTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $admin;

    private User $operator;

    private User $branchOwner;

    private User $partnerOwner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        $this->buildNetwork();
        $this->activeAccount();
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
        $this->branchOwner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        $this->partnerOwner = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->withTwoFactor()->create();
    }

    private function approved(): Transaction
    {
        $payin = $this->submittedPayin(['amount' => 1000000], '626812820491');
        $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '626812820491'])->assertSessionHasNoErrors();

        return $payin->refresh();
    }

    public function test_the_branch_sees_the_days_deposits_and_later_the_chargeback_on_its_approval_day()
    {
        $payin = $this->approved();
        $this->travel(1)->minutes();

        $this->actingAs($this->branchOwner)->get(route('branch.settlement-dashboard'))
            ->assertInertia(fn ($page) => $page->component('settlements/dashboard')
                ->where('side', 'branch')
                ->where('figures.total.deposit.count', 1)
                ->where('figures.total.deposit.amount', 1000000)
                ->where('figures.total.deposit.commission', 40000)
                ->where('figures.total.deposit.rate', 4)
                ->where('figures.total.chargeback.count', 0)
                // The branch holds ₹9,600 for PayGate.
                ->where('figures.total.current', -960000)
                ->where('figures.total.net', -960000)
                ->where('figures.pairs.0.partner.code', $this->partner->code));

        // Raised two days later, approved a week after the deposit.
        $this->travel(2)->days();
        $this->actingAs($this->operator)->post(route('branch.payins.chargeback', $payin), [
            'amount' => '10000', 'utr' => '626812820491', 'proof' => UploadedFile::fake()->create('notice.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $this->travel(5)->days();
        $this->actingAs($this->admin)->post(route('admin.chargebacks.approve', Chargeback::sole()))->assertSessionHasNoErrors();
        $this->travel(1)->minutes();

        $this->actingAs($this->branchOwner)->get(route('branch.settlement-dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('figures.total.deposit.count', 0)
                ->where('figures.total.chargeback.count', 1)
                ->where('figures.total.chargeback.amount', 1000000)
                ->where('figures.total.current', 1000000)
                ->where('figures.total.opening', -960000)
                ->where('figures.total.closing', 40000));

        // The partner bears it, and sees no branch.
        $this->actingAs($this->partnerOwner)->get(route('partner.settlement-dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('side', 'partner')
                ->where('options', null)
                ->where('figures.total.chargeback.amount', -1000000)
                ->where('figures.total.closing', -60000)
                ->where('figures.pairs', []));

        // Admin, a custom range covering both days: deposit, chargeback and margin.
        $this->actingAs($this->admin)->get(route('admin.settlement-dashboard', ['side' => 'admin', 'range' => 'custom', 'from' => now()->subDays(8)->toDateString(), 'to' => now()->toDateString()]))
            ->assertInertia(fn ($page) => $page
                ->where('side', 'admin')
                ->where('figures.admin.margin', 20000)
                ->where('figures.admin.partner.deposit.amount', 1000000)
                ->where('figures.admin.partner.chargeback.amount', -1000000)
                ->where('figures.admin.branch.chargeback.amount', 1000000));
    }

    public function test_each_portal_sees_only_its_own_side()
    {
        $this->approved();
        $this->travel(1)->minutes();
        $other = User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create();

        // Another branch: nothing, even when it asks for this branch.
        $this->actingAs($other)->get(route('branch.settlement-dashboard', ['branches' => [$this->branch->id]]))
            ->assertInertia(fn ($page) => $page->where('figures.total.deposit.count', 0)->where('figures.pairs', []));

        // Admin's branch view, filtered to another branch: nothing; to this one: the deposit.
        $this->actingAs($this->admin)->get(route('admin.settlement-dashboard', ['side' => 'branch', 'branches' => [$other->branch_id]]))
            ->assertInertia(fn ($page) => $page->where('figures.total.deposit.count', 0));
        $this->actingAs($this->admin)->get(route('admin.settlement-dashboard', ['side' => 'partner', 'branches' => [$this->branch->id]]))
            ->assertInertia(fn ($page) => $page->where('figures.total.deposit.commission', 60000)->where('figures.pairs.0.branch.code', $this->branch->code));

        // Without settlements.view: no dashboard.
        $this->actingAs($this->operator)->get(route('branch.settlement-dashboard'))->assertForbidden();
    }
}
