<?php

namespace Tests\Feature\Partners;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Actions\SavePaymentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class PartnerAccountsTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildNetwork();
        $this->owner = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->create();
    }

    public function test_partners_see_the_masked_accounts_of_their_mapped_branches_without_branch_details()
    {
        $this->branch->update(['code' => 'BR-SECRET', 'name' => 'Hidden Branch Name']);
        $active = $this->activeAccount(null, ['label' => 'Branch label one', 'upi_display_name' => 'Shop UPI', 'daily_amount_limit' => 20000000]);
        app(SavePaymentAccount::class)->handle($this->networkAdmin, $this->branch, null, [
            'label' => 'Waiting account',
            'account_holder_name' => 'Pending Holder',
            'is_bank_enabled' => true,
            'bank_name' => 'ICICI Bank',
            'ifsc' => 'ICIC0000001',
            'account_number' => '123456789012',
            'is_upi_enabled' => false,
            'is_qr_enabled' => false,
            'max_open_sessions' => 5,
        ]);

        $this->activeAccount(Branch::factory()->create(), ['account_holder_name' => 'Unmapped Holder']);
        $inactive = Branch::factory()->create();
        PartnerBranchMapping::create(['partner_id' => $this->partner->id, 'branch_id' => $inactive->id, 'status' => 'inactive']);
        $this->activeAccount($inactive, ['account_holder_name' => 'Inactive Pair Holder']);
        $other = Partner::factory()->create();
        $this->mapPair($other, $otherBranch = Branch::factory()->create());
        $this->activeAccount($otherBranch, ['account_holder_name' => 'Other Partner Holder']);

        $response = $this->actingAs($this->owner)->get(route('partner.accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('partner/accounts')
                ->has('accounts', 1)
                ->where('accounts.0.label', 'Branch label one')
                ->where('accounts.0.holder', $active->account_holder_name)
                ->where('accounts.0.account_number', $active->maskedAccountNumber())
                ->where('accounts.0.upi_id', $active->maskedUpiId())
                ->where('accounts.0.upi_display_name', 'Shop UPI')
                ->where('accounts.0.daily_amount_limit', 20000000)
                ->where('accounts.0.status', 'active')
                ->where('accounts.0.can.update', false)
                ->where('accounts.0.can.switch_to', [])
                ->missing('accounts.0.branch')
                ->missing('accounts.0.branch_id'));

        $html = $response->getContent() ?: '';
        foreach (['BR-SECRET', 'Hidden Branch Name', 'Waiting account', 'Pending Holder', '123456789012', (string) $active->account_number_encrypted, (string) $active->upi_id_encrypted, 'Unmapped Holder', 'Inactive Pair Holder', 'Other Partner Holder'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }
    }

    public function test_only_roles_with_account_view_open_the_page_and_partners_never_edit_accounts()
    {
        $this->actingAs($this->owner)->get(route('partner.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('accounts.view')
                && ! collect($permissions)->contains('accounts.create')
                && ! collect($permissions)->contains('accounts.update')));

        $developer = User::factory()->partner(SystemRoles::PARTNER_DEVELOPER, $this->partner)->create();
        $viewer = User::factory()->partner(SystemRoles::PARTNER_VIEWER, $this->partner)->create();
        $this->actingAs($developer)->get(route('partner.accounts.index'))->assertOk();
        $this->actingAs($viewer)->get(route('partner.accounts.index'))->assertOk();

        $this->assertSame([Permission::AccountsView], array_values(array_filter(
            Permission::forType(UserType::Partner),
            fn (Permission $permission) => str_starts_with($permission->value, 'accounts.'),
        )));
    }

    public function test_section_rollout_switches_the_page_on_and_off_for_partners()
    {
        $super = User::factory()->admin()->withTwoFactor()->create();
        $rollout = fn (array $partnerOpen) => $this->actingAs($super)->put(route('admin.section-rollout.update'), [
            'active' => true,
            'open' => ['admin' => [], 'branch' => [], 'partner' => $partnerOpen],
        ])->assertSessionHasNoErrors();

        $this->actingAs($super)->get(route('admin.section-rollout.index'))
            ->assertInertia(fn (Assert $page) => $page->where('portals.partner', fn ($groups) => collect($groups)->pluck('items')->flatten(1)->contains('key', 'accounts')));

        $rollout([]);
        $this->actingAs($this->owner)->get(route('partner.accounts.index'))->assertRedirect(route('partner.dashboard'));

        $rollout(['accounts']);
        $this->actingAs($this->owner)->get(route('partner.accounts.index'))->assertOk();
    }
}
