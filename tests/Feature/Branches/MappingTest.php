<?php

namespace Tests\Feature\Branches;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\Models\CommissionRate;
use App\Domain\Commission\RateBook;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MappingTest extends TestCase
{
    use RefreshDatabase;

    private function rate(string $type, string $id, string $direction, string $rate): void
    {
        CommissionRate::create([
            'subject_type' => $type, 'subject_id' => $id, 'side' => $type, 'direction' => $direction,
            'rate_percent' => $rate, 'effective_from' => now()->subDay(),
        ]);
    }

    public function test_pairs_are_listed_with_their_rates_and_margin()
    {
        $partner = Partner::factory()->create();
        $branch = Branch::factory()->create();
        $this->rate('partner', $partner->id, 'deposit', '6');
        $this->rate('branch', $branch->id, 'deposit', '4');
        PartnerBranchMapping::create(['partner_id' => $partner->id, 'branch_id' => $branch->id, 'status' => 'active']);

        $this->actingAs(User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create())
            ->get(route('admin.mappings.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/mappings/index')
                ->where('mappings.data.0.rates.deposit.margin', '2.0000')
                ->where('mappings.data.0.rates.withdrawal.margin', null));
    }

    public function test_mapping_a_pair_twice_reactivates_the_same_mapping()
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $partner = Partner::factory()->create();
        $branch = Branch::factory()->create();
        $payload = ['partner_id' => $partner->id, 'branch_id' => $branch->id];

        $this->actingAs($admin)->post(route('admin.mappings.store'), $payload)->assertSessionHasNoErrors();
        PartnerBranchMapping::query()->update(['status' => 'inactive']);
        $this->actingAs($admin)->post(route('admin.mappings.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, PartnerBranchMapping::count());
        $this->assertSame('active', PartnerBranchMapping::firstOrFail()->status);
    }

    public function test_pair_rates_override_and_can_be_removed()
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $partner = Partner::factory()->create();
        $branch = Branch::factory()->create();
        $this->rate('partner', $partner->id, 'deposit', '6');
        $this->rate('branch', $branch->id, 'deposit', '4');
        $mapping = PartnerBranchMapping::create(['partner_id' => $partner->id, 'branch_id' => $branch->id, 'status' => 'active']);
        $base = ['status' => 'active', 'is_deposit_enabled' => true, 'is_withdrawal_enabled' => false, 'deposit_daily_limit' => '100000', 'withdrawal_daily_limit' => ''];

        $this->actingAs($admin)->put(route('admin.mappings.update', $mapping), [
            ...$base,
            'overrides' => ['partner' => ['deposit' => '5.5', 'withdrawal' => ''], 'branch' => ['deposit' => '', 'withdrawal' => '']],
        ])->assertSessionHasNoErrors();

        $mapping->refresh();
        $this->assertFalse($mapping->is_withdrawal_enabled);
        $this->assertSame(10000000, $mapping->deposit_daily_limit);
        $this->assertSame(['partner' => '5.5000', 'branch' => '4.0000'], app(RateBook::class)->forPair($mapping, Direction::Deposit));

        $this->travel(1)->minutes();
        $this->actingAs($admin)->put(route('admin.mappings.update', $mapping), [
            ...$base,
            'overrides' => ['partner' => ['deposit' => '', 'withdrawal' => ''], 'branch' => ['deposit' => '', 'withdrawal' => '']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('6.0000', app(RateBook::class)->forPair($mapping, Direction::Deposit)['partner']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'commission_rate.cleared', 'subject_id' => $mapping->id]);
    }

    public function test_a_losing_pair_is_saved_but_warned_and_audited()
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $partner = Partner::factory()->create();
        $branch = Branch::factory()->create();
        $this->rate('partner', $partner->id, 'deposit', '3');
        $mapping = PartnerBranchMapping::create(['partner_id' => $partner->id, 'branch_id' => $branch->id, 'status' => 'active']);

        $this->actingAs($admin)->put(route('admin.mappings.update', $mapping), [
            'status' => 'active', 'is_deposit_enabled' => true, 'is_withdrawal_enabled' => true,
            'overrides' => ['partner' => ['deposit' => '', 'withdrawal' => ''], 'branch' => ['deposit' => '3.5', 'withdrawal' => '']],
        ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.type', 'warning');

        $this->assertDatabaseHas('audit_logs', ['action' => 'mapping.negative_margin', 'subject_id' => $mapping->id]);
    }

    public function test_admins_without_commission_rights_cannot_set_pair_rates()
    {
        $partner = Partner::factory()->create();
        $branch = Branch::factory()->create();
        $mapping = PartnerBranchMapping::create(['partner_id' => $partner->id, 'branch_id' => $branch->id, 'status' => 'active']);
        $ops = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();

        $this->actingAs($ops)->put(route('admin.mappings.update', $mapping), [
            'status' => 'active', 'is_deposit_enabled' => true, 'is_withdrawal_enabled' => true,
            'overrides' => ['partner' => ['deposit' => '1', 'withdrawal' => ''], 'branch' => ['deposit' => '', 'withdrawal' => '']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, CommissionRate::where('subject_type', 'mapping')->count());
    }
}
