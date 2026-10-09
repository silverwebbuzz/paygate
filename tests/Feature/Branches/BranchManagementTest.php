<?php

namespace Tests\Feature\Branches;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\Models\CommissionRate;
use App\Domain\Commission\RateBook;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = SystemRoles::ADMIN_SUPER): User
    {
        return User::factory()->admin($role)->withTwoFactor()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'code' => 'BR-297',
            'name' => 'Delux HP',
            'is_deposit_enabled' => true,
            'is_withdrawal_enabled' => true,
            'deposit_limit_type' => 'daily_reset',
            'deposit_min_amount' => '500',
            'deposit_max_amount' => '50000',
            'deposit_daily_limit' => '5000000',
            'withdrawal_min_amount' => '-1',
            'withdrawal_max_amount' => '-1',
            'withdrawal_daily_limit' => '-1',
            'deposit_rate' => '3.5',
            'withdrawal_rate' => '1.5',
            'partner_ids' => [],
            ...$overrides,
        ];
    }

    public function test_branch_list_and_form_open_for_admins_only()
    {
        Branch::factory()->count(2)->create();

        $this->actingAs($this->admin())->get(route('admin.branches.index'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/branches/index')->has('branches.data', 2));
        $this->actingAs($this->admin())->get(route('admin.branches.create'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/branches/form'));

        $this->actingAs(User::factory()->branch()->withTwoFactor()->create())->get(route('admin.branches.index'))->assertForbidden();
        $this->actingAs($this->admin(SystemRoles::ADMIN_FINANCE))->get(route('admin.branches.create'))->assertForbidden();
    }

    public function test_creating_a_branch_saves_limits_rates_partners_and_its_admin()
    {
        $admin = $this->admin();
        $partner = Partner::factory()->create();

        $this->actingAs($admin)->post(route('admin.branches.store'), $this->payload([
            'partner_ids' => [$partner->id],
            'admin_username' => 'delux.admin',
            'admin_password' => 'Str0ng-pass!word',
            'admin_password_confirmation' => 'Str0ng-pass!word',
        ]))->assertSessionHasNoErrors();

        $branch = Branch::where('code', 'BR-297')->firstOrFail();
        $this->assertSame(OrganisationStatus::Draft, $branch->status);
        $this->assertSame(50000, $branch->deposit_min_amount);
        $this->assertSame(500000000, $branch->deposit_daily_limit);
        $this->assertNull($branch->withdrawal_max_amount);
        $this->assertSame('3.5000', app(RateBook::class)->branchRate($branch, Direction::Deposit));
        $this->assertTrue($branch->partners()->whereKey($partner->id)->exists());

        $branchAdmin = User::where('username', 'delux.admin')->firstOrFail();
        $this->assertNull($branchAdmin->email);
        $this->assertSame($branch->id, $branchAdmin->branch_id);
        $this->assertSame(SystemRoles::BRANCH_OWNER, $branchAdmin->role->slug);
        $this->assertSame('active', $branchAdmin->displayStatus());

        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.created', 'subject_id' => $branch->id, 'actor_id' => $admin->id]);
    }

    public function test_activation_needs_rates_for_enabled_directions()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.branches.store'), $this->payload(['withdrawal_rate' => '', 'activate' => true]))
            ->assertSessionHasErrors('status');
        $this->assertSame(0, Branch::count());

        $this->actingAs($admin)->post(route('admin.branches.store'), $this->payload(['activate' => true]))->assertSessionHasNoErrors();
        $this->assertSame(OrganisationStatus::Active, Branch::firstOrFail()->status);
    }

    public function test_a_branch_rate_above_a_partner_rate_is_allowed_but_flagged()
    {
        $admin = $this->admin();
        $partner = Partner::factory()->create(['code' => 'ATOZ']);
        CommissionRate::create([
            'subject_type' => 'partner', 'subject_id' => $partner->id, 'side' => 'partner', 'direction' => 'deposit',
            'rate_percent' => '3', 'effective_from' => now()->subDay(),
        ]);

        $this->actingAs($admin)->post(route('admin.branches.store'), $this->payload(['deposit_rate' => '3.5', 'partner_ids' => [$partner->id]]))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'warning');

        $audit = AuditLog::where('action', 'commission_rate.set')->get()->first(fn ($log) => ($log->new_values['direction'] ?? null) === 'deposit');
        $this->assertSame('ATOZ', $audit->new_values['negative_margin_pairs'][0]['code']);
    }

    public function test_topups_change_the_allowance_with_history_and_never_go_negative()
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['deposit_limit_type' => 'topup']);

        $this->actingAs($admin)->post(route('admin.branches.topups.store', $branch), ['kind' => 'topup', 'amount' => '15000', 'reason' => 'Weekly allowance'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.branches.topups.store', $branch), ['kind' => 'correction', 'amount' => '2500.50', 'reason' => 'Typo'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1249950, $branch->fresh()?->deposit_topup_balance);
        $this->assertSame([1500000, -250050], $branch->topups()->orderBy('created_at')->pluck('amount')->all());

        $this->actingAs($admin)->post(route('admin.branches.topups.store', $branch), ['kind' => 'correction', 'amount' => '99999', 'reason' => 'Too much'])
            ->assertSessionHasErrors('amount');
        $this->assertSame(1249950, $branch->fresh()?->deposit_topup_balance);
    }

    public function test_daily_limit_branches_cannot_be_topped_up()
    {
        $branch = Branch::factory()->create(['deposit_limit_type' => 'daily_reset']);

        $this->actingAs($this->admin())->post(route('admin.branches.topups.store', $branch), ['kind' => 'topup', 'amount' => '100', 'reason' => 'x'])
            ->assertSessionHasErrors('amount');
    }

    public function test_status_changes_follow_the_lifecycle()
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.branches.store'), $this->payload(['activate' => true]));
        $branch = Branch::firstOrFail();

        $this->actingAs($admin)->put(route('admin.branches.status', $branch), ['status' => 'suspended', 'reason' => 'Bank issues'])->assertSessionHasNoErrors();
        $this->assertSame(OrganisationStatus::Suspended, $branch->fresh()?->status);
        $this->actingAs($admin)->put(route('admin.branches.status', $branch), ['status' => 'draft', 'reason' => 'x'])->assertSessionHasErrors('status');
    }

    public function test_limits_from_the_list_change_only_deposit_and_withdrawal_limits()
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create([
            'name' => 'Delux HP',
            'code' => 'BR-297',
            'deposit_limit_type' => 'topup',
            'deposit_topup_balance' => 1500000,
            'is_deposit_enabled' => true,
            'is_withdrawal_enabled' => false,
            'deposit_min_amount' => 50000,
            'deposit_daily_limit' => 500000000,
            'withdrawal_daily_limit' => null,
        ]);
        CommissionRate::create([
            'subject_type' => 'branch', 'subject_id' => $branch->id, 'side' => 'branch', 'direction' => 'deposit',
            'rate_percent' => '3.5', 'effective_from' => now()->subDay(),
        ]);

        $this->actingAs($admin)->get(route('admin.branches.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('branches.data.0.limits.deposit_min_amount', '500.00')
                ->where('branches.data.0.limits.deposit_daily_limit', '5000000.00')
                ->where('branches.data.0.limits.withdrawal_daily_limit', '-1'));

        $limits = [
            'deposit_min_amount' => '250',
            'deposit_max_amount' => '8000',
            'deposit_daily_limit' => '5000000',
            'withdrawal_min_amount' => '100',
            'withdrawal_max_amount' => '2000',
            'withdrawal_daily_limit' => '10000',
        ];

        $this->actingAs($admin)->put(route('admin.branches.limits', $branch), [
            ...$limits,
            'withdrawal_max_amount' => '50',
        ])->assertSessionHasErrors('withdrawal_max_amount');
        $this->assertSame(50000, $branch->fresh()?->deposit_min_amount);

        $this->actingAs($admin)->put(route('admin.branches.limits', $branch), $limits)->assertSessionHasNoErrors();

        $branch->refresh();
        $this->assertSame(25000, $branch->deposit_min_amount);
        $this->assertSame(800000, $branch->deposit_max_amount);
        $this->assertSame(500000000, $branch->deposit_daily_limit);
        $this->assertSame(10000, $branch->withdrawal_min_amount);
        $this->assertSame(200000, $branch->withdrawal_max_amount);
        $this->assertSame(1000000, $branch->withdrawal_daily_limit);
        $this->assertSame('Delux HP', $branch->name);
        $this->assertSame('BR-297', $branch->code);
        $this->assertSame('topup', $branch->deposit_limit_type);
        $this->assertSame(1500000, $branch->deposit_topup_balance);
        $this->assertTrue($branch->is_deposit_enabled);
        $this->assertFalse($branch->is_withdrawal_enabled);
        $this->assertSame('3.5000', app(RateBook::class)->branchRate($branch, Direction::Deposit));
        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.updated', 'subject_id' => $branch->id, 'actor_id' => $admin->id]);
        $log = AuditLog::query()->where(['action' => 'branch.updated', 'subject_id' => $branch->id])->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertContains('Before Deposit minimum: ₹500.00. Now: ₹250.00', $log->changeLines());
        $this->assertContains('Before Withdrawal minimum: Unlimited. Now: ₹100.00', $log->changeLines());

        $this->actingAs($admin)->get(route('admin.branches.logs', ['search' => 'Delux', 'event' => 'branch.updated', 'party' => $branch->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/activity/index')
                ->where('kind', 'branch')
                ->where('logs.data', function ($rows) {
                    $changes = collect($rows)->flatMap(fn ($row) => $row['changes']);

                    return $changes->contains(fn ($change) => $change['field'] === 'Deposit minimum' && $change['before'] === '₹500.00' && $change['now'] === '₹250.00');
                }));

        $this->actingAs($this->admin(SystemRoles::ADMIN_FINANCE))
            ->put(route('admin.branches.limits', $branch), $limits)
            ->assertForbidden();
        $this->assertSame(25000, $branch->fresh()?->deposit_min_amount);
    }
}
