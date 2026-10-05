<?php

namespace Tests\Feature\Branches;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountLogTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['code' => 'BR-LOG', 'name' => 'Log Branch', 'deposit_min_amount' => 50000, 'deposit_max_amount' => 5000000]);
        $this->owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create(['name' => 'Branch Owner', 'username' => 'branch.owner']);
        $this->admin = User::factory()->admin()->withTwoFactor()->create(['name' => 'Ops Admin', 'username' => 'ops.admin']);
    }

    private function activeAccount(string $label = 'HDFC current 1', string $accountNumber = '5010 0482 716640'): PaymentAccount
    {
        $this->actingAs($this->owner)->post(route('branch.accounts.store'), [
            'label' => $label,
            'account_holder_name' => 'Ashan Ali Shaik',
            'is_bank_enabled' => true,
            'bank_name' => 'HDFC Bank',
            'ifsc' => 'HDFC0001203',
            'account_number' => $accountNumber,
            'is_upi_enabled' => false,
            'min_amount' => '500',
            'max_amount' => '50000',
            'daily_amount_limit' => '200000',
            'daily_count_limit' => '',
            'max_open_sessions' => 5,
        ])->assertSessionHasNoErrors();

        $account = PaymentAccount::query()->where('label', $label)->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.accounts.approve', $account))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'active'])->assertSessionHasNoErrors();

        return $account;
    }

    public function test_pausing_needs_a_reason_from_branch_users_and_admins()
    {
        $account = $this->activeAccount();

        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'paused'])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->put(route('admin.accounts.status', $account), ['status' => 'paused', 'reason' => '  '])->assertSessionHasErrors('reason');
        $this->assertSame(AccountStatus::Active, $account->fresh()?->status);

        $this->actingAs($this->admin)->put(route('admin.accounts.status', $account), ['status' => 'paused', 'reason' => 'Bank flagged the account'])->assertSessionHasNoErrors();
        $this->assertSame(AccountStatus::Paused, $account->fresh()?->status);
    }

    public function test_the_log_shows_every_account_event_with_who_why_ip_and_branch()
    {
        $account = $this->activeAccount();
        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'paused', 'reason' => 'Bank asked us to stop for a day'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('admin.accounts.status', $account), ['status' => 'active'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('admin.accounts.logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/accounts/logs')
                ->where('logs.total', 5)
                ->where('logs.data.0.event', 'activated')
                ->where('logs.data.0.from', 'paused')
                ->where('logs.data.0.to', 'active')
                ->where('logs.data.0.who.username', 'ops.admin')
                ->where('logs.data.0.who.portal', 'admin')
                ->where('logs.data.1.event', 'paused')
                ->where('logs.data.1.from', 'active')
                ->where('logs.data.1.to', 'paused')
                ->where('logs.data.1.reason', 'Bank asked us to stop for a day')
                ->where('logs.data.1.who.name', 'Branch Owner')
                ->where('logs.data.1.who.username', 'branch.owner')
                ->where('logs.data.1.who.role', $this->owner->role->name)
                ->where('logs.data.1.who.portal', 'branch')
                ->where('logs.data.1.ip', '127.0.0.1')
                ->where('logs.data.1.branch.code', 'BR-LOG')
                ->where('logs.data.1.branch.name', 'Log Branch')
                ->where('logs.data.1.account.holder', 'Ashan Ali Shaik')
                ->where('logs.data.1.account.account_number', 'XXXX 6640')
                ->where('logs.data.2.event', 'activated')
                ->where('logs.data.3.event', 'verified')
                ->where('logs.data.4.event', 'created'));
    }

    public function test_edits_list_the_changed_fields()
    {
        $account = $this->activeAccount();
        $this->actingAs($this->owner)->put(route('branch.accounts.update', $account), [
            'label' => 'HDFC current 1',
            'account_holder_name' => 'Ashan Ali Shaik',
            'is_bank_enabled' => true,
            'bank_name' => 'HDFC Bank',
            'ifsc' => 'HDFC0001203',
            'is_upi_enabled' => false,
            'min_amount' => '500',
            'max_amount' => '40000',
            'daily_amount_limit' => '200000',
            'daily_count_limit' => '',
            'max_open_sessions' => 5,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('admin.accounts.logs.index', ['event' => 'updated']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.event', 'updated')
                ->where('logs.data.0.fields', ['Max amount']));
    }

    public function test_filters_narrow_the_log()
    {
        $first = $this->activeAccount();
        $second = $this->activeAccount('ICICI savings', '1234 5678 9012');
        $this->actingAs($this->owner)->put(route('branch.accounts.status', $first), ['status' => 'paused', 'reason' => 'Limit reached early'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('admin.accounts.status', $second), ['status' => 'disabled', 'reason' => 'Closed by the bank'])->assertSessionHasNoErrors();

        $other = Branch::factory()->create();

        $this->actingAs($this->admin)->get(route('admin.accounts.logs.index', ['event' => 'paused']))
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 1)->where('logs.data.0.account.id', $first->id));

        $this->actingAs($this->admin)->get(route('admin.accounts.logs.index', ['account' => $second->id]))
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 4)->where('account.id', $second->id)->where('logs.data.0.event', 'disabled'));

        $this->actingAs($this->admin)->get(route('admin.accounts.logs.index', ['branch' => $other->id]))
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 0));

        $this->actingAs($this->admin)->get(route('admin.accounts.logs.index', ['search' => 'Closed by']))
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 1)->where('logs.data.0.event', 'disabled'));

        $this->actingAs($this->admin)->get(route('admin.accounts.logs.index', ['search' => '9012', 'event' => 'activated']))
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 1)->where('logs.data.0.account.id', $second->id));

        $this->actingAs($this->admin)->get(route('admin.accounts.logs.index', ['search' => 'ops.admin']))
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 3));

        $this->actingAs($this->admin)->get(route('admin.accounts.logs.index', ['from' => now()->subDays(60)->toDateString(), 'to' => now()->subDays(40)->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 0));
    }

    public function test_export_streams_the_filtered_rows_as_safe_csv()
    {
        $account = $this->activeAccount();
        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'paused', 'reason' => '=HYPERLINK("http://x")'])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->admin)->get(route('admin.accounts.logs.export', ['event' => 'paused']));
        $response->assertOk();
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('When,Event', $lines[0]);
        $this->assertStringContainsString('Paused,active,paused', $lines[1]);
        $this->assertStringContainsString('XXXX 6640', $lines[1]);
        $this->assertStringContainsString('branch.owner', $lines[1]);
        $this->assertStringContainsString('BR-LOG', $lines[1]);
        $this->assertStringContainsString('\'=HYPERLINK', $lines[1]);
        $this->assertStringNotContainsString('50100482716640', $csv);
    }

    public function test_only_admins_can_open_the_log()
    {
        $this->actingAs($this->owner)->get(route('admin.accounts.logs.index'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('admin.accounts.logs.export'))->assertForbidden();
        $this->actingAs(User::factory()->partner()->create())->get(route('admin.accounts.logs.index'))->assertForbidden();
    }
}
