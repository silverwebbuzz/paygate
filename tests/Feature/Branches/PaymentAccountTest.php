<?php

namespace Tests\Feature\Branches;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Enums\AccountVerification;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Support\Crypto\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentAccountTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['deposit_min_amount' => 50000, 'deposit_max_amount' => 5000000]);
        $this->owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'label' => 'HDFC current 1',
            'account_holder_name' => 'Ashan Ali Shaik',
            'is_bank_enabled' => true,
            'bank_name' => 'HDFC Bank',
            'ifsc' => 'hdfc0001203',
            'account_number' => '5010 0482 716640',
            'is_upi_enabled' => true,
            'upi_id' => 'AshanAli@HDFCBank',
            'upi_display_name' => 'Ashan Ali',
            'is_qr_enabled' => true,
            'min_amount' => '500',
            'max_amount' => '50000',
            'daily_amount_limit' => '200000',
            'daily_count_limit' => '-1',
            'max_open_sessions' => 5,
            ...$overrides,
        ];
    }

    private function add(array $overrides = []): PaymentAccount
    {
        $this->actingAs($this->owner)->post(route('branch.accounts.store'), $this->payload($overrides))->assertSessionHasNoErrors();

        return PaymentAccount::latest('created_at')->firstOrFail();
    }

    public function test_a_branch_adds_an_account_stored_encrypted_and_waiting_for_verification()
    {
        $account = $this->add();

        $this->assertSame(AccountVerification::Pending, $account->verification);
        $this->assertSame(AccountStatus::Inactive, $account->status);
        $this->assertSame('HDFC0001203', $account->ifsc);
        $this->assertSame('50100482716640', $account->account_number_encrypted);
        $this->assertSame('6640', $account->account_number_last4);
        $this->assertSame('ashanali@hdfcbank', $account->upi_id_encrypted);
        $this->assertSame(BlindIndex::of('bank_account', '50100482716640'), $account->account_number_hash);
        $this->assertSame(50000, $account->min_amount);
        $this->assertNull($account->daily_count_limit);

        // Nothing readable in the database row itself.
        $raw = (array) DB::table('payment_accounts')->where('id', $account->id)->first();
        $this->assertStringNotContainsString('50100482716640', json_encode($raw) ?: '');
        $this->assertStringNotContainsString('ashanali', json_encode($raw) ?: '');

        // Nor in the audit log.
        $audit = DB::table('audit_logs')->where(['action' => 'payment_account.created', 'subject_id' => $account->id])->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('50100482716640', (string) $audit->new_values);
    }

    public function test_minus_one_means_the_account_has_no_limit()
    {
        $account = $this->add([
            'min_amount' => '-1',
            'max_amount' => '-1',
            'daily_amount_limit' => '-1',
            'daily_count_limit' => '-1',
        ]);

        $this->assertNull($account->min_amount);
        $this->assertNull($account->max_amount);
        $this->assertNull($account->daily_amount_limit);
        $this->assertNull($account->daily_count_limit);
    }

    public function test_the_same_account_or_upi_id_cannot_be_registered_twice_even_by_another_branch()
    {
        $this->add();
        $other = User::factory()->branch(SystemRoles::BRANCH_OWNER)->withTwoFactor()->create();

        $this->actingAs($other)->post(route('branch.accounts.store'), $this->payload(['label' => 'Copy', 'upi_id' => 'someone@okaxis']))
            ->assertSessionHasErrors('account_number');
        $this->actingAs($other)->post(route('branch.accounts.store'), $this->payload(['label' => 'Copy', 'account_number' => '999988887777']))
            ->assertSessionHasErrors('upi_id');
    }

    public function test_limits_must_sit_inside_the_branch_limits()
    {
        $this->actingAs($this->owner)->post(route('branch.accounts.store'), $this->payload(['min_amount' => '100']))
            ->assertSessionHasErrors('min_amount');
        $this->actingAs($this->owner)->post(route('branch.accounts.store'), $this->payload(['max_amount' => '60000']))
            ->assertSessionHasErrors('max_amount');
    }

    public function test_admin_verifies_then_the_branch_activates_and_pauses()
    {
        $account = $this->add();
        $admin = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();

        // A branch can't activate an unverified account.
        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'active'])->assertSessionHasErrors('status');

        $this->actingAs($admin)->post(route('admin.accounts.approve', $account))->assertSessionHasNoErrors();
        $account->refresh();
        $this->assertSame(AccountVerification::Verified, $account->verification);
        $this->assertSame(AccountStatus::Inactive, $account->status);
        $this->assertSame($admin->id, $account->verified_by);

        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'inactive'])->assertSessionHasErrors('reason');
        $this->assertSame(AccountStatus::Active, $account->fresh()?->status);
        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'inactive', 'reason' => 'Bank asked us to stop for a day'])->assertSessionHasNoErrors();
        $this->assertSame(AccountStatus::Inactive, $account->fresh()?->status);
        $this->assertSame(AccountVerification::Verified, $account->fresh()?->verification);
    }

    public function test_changing_payment_details_needs_verification_again_but_limits_do_not()
    {
        $account = $this->add();
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $this->actingAs($admin)->post(route('admin.accounts.approve', $account));
        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'active']);

        // Limits only: stays active. Empty numbers keep the stored ones.
        $this->actingAs($this->owner)->put(route('branch.accounts.update', $account), $this->payload(['account_number' => '', 'upi_id' => '', 'daily_amount_limit' => '300000']))
            ->assertSessionHasNoErrors();
        $account->refresh();
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertSame('50100482716640', $account->account_number_encrypted);

        // New account number: back to verification.
        $this->actingAs($this->owner)->put(route('branch.accounts.update', $account), $this->payload(['account_number' => '11112222333344', 'upi_id' => '']))
            ->assertSessionHasNoErrors();
        $account->refresh();
        $this->assertSame(AccountVerification::Pending, $account->verification);
        $this->assertSame(AccountStatus::Inactive, $account->status);
        $this->assertNull($account->verified_at);
    }

    public function test_rejection_needs_a_reason_and_editing_resubmits()
    {
        $account = $this->add();
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->post(route('admin.accounts.reject', $account), [])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.accounts.reject', $account), ['reason' => 'IFSC does not match'])->assertSessionHasNoErrors();
        $account->refresh();
        $this->assertSame(AccountVerification::Unverified, $account->verification);
        $this->assertSame(AccountStatus::Inactive, $account->status);
        $this->assertSame('IFSC does not match', $account->rejected_reason);

        $this->actingAs($this->owner)->put(route('branch.accounts.update', $account), $this->payload(['ifsc' => 'HDFC0001204', 'account_number' => '', 'upi_id' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame(AccountVerification::Pending, $account->fresh()?->verification);
    }

    public function test_branches_only_see_and_change_their_own_accounts()
    {
        $account = $this->add();
        $stranger = User::factory()->branch(SystemRoles::BRANCH_OWNER)->withTwoFactor()->create();

        $this->actingAs($stranger)->get(route('branch.accounts.index'))
            ->assertInertia(fn (Assert $page) => $page->component('branch/accounts')->has('accounts', 0));
        $this->actingAs($stranger)->put(route('branch.accounts.status', $account), ['status' => 'disabled', 'reason' => 'x'])->assertNotFound();
        $this->actingAs($stranger)->put(route('branch.accounts.update', $account), $this->payload())->assertNotFound();
    }

    public function test_operators_can_view_but_not_add_accounts()
    {
        $operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();

        $this->actingAs($operator)->get(route('branch.accounts.index'))->assertOk();
        $this->actingAs($operator)->post(route('branch.accounts.store'), $this->payload())->assertForbidden();
    }

    public function test_lists_show_the_full_bank_and_upi_details_and_revealing_is_audited()
    {
        $account = $this->add();
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->get(route('admin.accounts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.data.0.account_number', '50100482716640')
                ->where('accounts.data.0.upi_id', 'ashanali@hdfcbank')
                ->where('accounts.data.0.verification', 'pending')
                ->where('accounts.data.0.can.set_verification', true)
                ->where('accounts.data.0.can.switch_to', []));

        $this->actingAs($this->owner)->get(route('branch.accounts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.account_number', '50100482716640')
                ->where('accounts.0.upi_id', 'ashanali@hdfcbank'));

        $this->actingAs($admin)
            ->get(route('admin.accounts.index', ['reveal' => $account->id]), [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'admin/accounts/index',
                'X-Inertia-Partial-Data' => 'reveal',
                'X-Inertia-Version' => Inertia::getVersion(),
            ])
            ->assertJsonPath('props.reveal.account_number', '50100482716640');

        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_account.revealed', 'subject_id' => $account->id, 'actor_id' => $admin->id]);
    }

    public function test_an_admin_can_change_an_account_to_any_status_and_turn_a_disabled_one_back_on()
    {
        $account = $this->add();
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->put(route('admin.accounts.status', $account), ['status' => 'active'])
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)->put(route('admin.accounts.verification', $account), [
            'verification' => 'verified',
        ])->assertSessionHasNoErrors();
        $account->refresh();
        $this->assertSame(AccountVerification::Verified, $account->verification);
        $this->assertSame(AccountStatus::Inactive, $account->status);

        $this->actingAs($admin)->put(route('admin.accounts.status', $account), ['status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.accounts.status', $account), ['status' => 'inactive'])
            ->assertSessionHasNoErrors();
        $this->assertSame(AccountStatus::Inactive, $account->fresh()?->status);

        $this->actingAs($admin)->put(route('admin.accounts.verification', $account), [
            'verification' => 'unverified',
        ])->assertSessionHasNoErrors();
        $account->refresh();
        $this->assertSame(AccountVerification::Unverified, $account->verification);
        $this->assertSame(AccountStatus::Inactive, $account->status);

        $this->actingAs($admin)->put(route('admin.accounts.status', $account), ['status' => 'active'])
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)->put(route('admin.accounts.verification', $account), [
            'verification' => 'verified',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.accounts.status', $account), ['status' => 'active'])
            ->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertSame(AccountVerification::Verified, $account->verification);
        $this->assertNotNull($account->verified_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_account.status_changed',
            'subject_id' => $account->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_a_branch_can_turn_a_verified_disabled_account_back_on()
    {
        $account = $this->add();
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->post(route('admin.accounts.approve', $account))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'active'])->assertSessionHasNoErrors();

        $this->actingAs($admin)->put(route('admin.accounts.status', $account), [
            'status' => 'inactive',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('branch.accounts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.can.update', true)
                ->where('accounts.0.can.switch_to', ['active']));

        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'active'])->assertSessionHasNoErrors();
        $account->refresh();
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertNotNull($account->verified_at);

        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), [
            'status' => 'inactive',
            'reason' => 'Paused the account ourselves',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame(AccountStatus::Active, $account->fresh()?->status);

        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), [
            'status' => 'inactive',
            'reason' => 'Changing the account number',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('branch.accounts.update', $account), $this->payload([
            'account_number' => '11112222333344',
            'upi_id' => '',
        ]))->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame(AccountVerification::Pending, $account->verification);
        $this->assertSame(AccountStatus::Inactive, $account->status);
        $this->assertNull($account->verified_at);
        $this->actingAs($this->owner)->put(route('branch.accounts.status', $account), ['status' => 'active'])->assertSessionHasErrors('status');
    }
}
