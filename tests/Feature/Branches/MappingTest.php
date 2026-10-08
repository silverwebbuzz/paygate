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

    public function test_the_status_filter_uses_the_mapping_status()
    {
        $active = PartnerBranchMapping::create([
            'partner_id' => Partner::factory()->create()->id,
            'branch_id' => Branch::factory()->create()->id,
            'status' => 'active',
        ]);
        PartnerBranchMapping::create([
            'partner_id' => Partner::factory()->create()->id,
            'branch_id' => Branch::factory()->create()->id,
            'status' => 'inactive',
        ]);

        $this->actingAs(User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create())
            ->get(route('admin.mappings.index', ['status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('mappings.data', 1)
                ->where('mappings.data.0.id', $active->id)
                ->where('mappings.data.0.status', 'active'));
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
        $base = ['status' => 'active', 'is_deposit_enabled' => true, 'is_withdrawal_enabled' => false, 'deposit_daily_limit' => '100000', 'withdrawal_daily_limit' => '-1'];

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
            'status' => 'active', 'is_deposit_enabled' => true, 'is_withdrawal_enabled' => true, 'deposit_daily_limit' => '-1', 'withdrawal_daily_limit' => '-1',
            'overrides' => ['partner' => ['deposit' => '', 'withdrawal' => ''], 'branch' => ['deposit' => '3.5', 'withdrawal' => '']],
        ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.type', 'warning');

        $this->assertDatabaseHas('audit_logs', ['action' => 'mapping.negative_margin', 'subject_id' => $mapping->id]);
    }

    public function test_a_partner_is_ticked_onto_many_branches_and_unticking_stops_that_pair()
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $partner = Partner::factory()->create();
        $other = Partner::factory()->create();
        $kept = Branch::factory()->create();
        $added = Branch::factory()->create();
        $removed = Branch::factory()->create();
        PartnerBranchMapping::create(['partner_id' => $partner->id, 'branch_id' => $removed->id, 'status' => 'active']);
        PartnerBranchMapping::create(['partner_id' => $other->id, 'branch_id' => $removed->id, 'status' => 'active']);

        $this->actingAs($admin)->get(route('admin.mappings.by-partner', ['partner' => $partner->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/mappings/assign')
                ->where('side', 'partner')
                ->where('selected', $partner->id)
                ->where('selected_ids', [$removed->id]));

        $this->actingAs($admin)->put(route('admin.mappings.by-partner.update'), [
            'partner_id' => $partner->id,
            'branch_ids' => [$kept->id, $added->id],
        ])->assertRedirect(route('admin.mappings.by-partner', ['partner' => $partner->id]));

        $this->assertSame('inactive', PartnerBranchMapping::query()->where(['partner_id' => $partner->id, 'branch_id' => $removed->id])->value('status'));
        $this->assertSame('active', PartnerBranchMapping::query()->where(['partner_id' => $other->id, 'branch_id' => $removed->id])->value('status'));
        $this->assertSame(2, PartnerBranchMapping::query()->where(['partner_id' => $partner->id, 'status' => 'active'])->count());
    }

    public function test_a_branch_is_ticked_onto_many_partners()
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $branch = Branch::factory()->create();
        $first = Partner::factory()->create();
        $second = Partner::factory()->create();

        $this->actingAs($admin)->put(route('admin.mappings.by-branch.update'), [
            'branch_id' => $branch->id,
            'partner_ids' => [$first->id, $second->id],
        ])->assertRedirect(route('admin.mappings.by-branch', ['branch' => $branch->id]));

        $this->actingAs($admin)->get(route('admin.mappings.by-branch', ['branch' => $branch->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('side', 'branch')
                ->where('selected_ids', fn ($ids) => collect($ids)->sort()->values()->all() === collect([$first->id, $second->id])->sort()->values()->all()));

        $this->actingAs(User::factory()->branch()->withTwoFactor()->create())
            ->get(route('admin.mappings.by-partner'))
            ->assertForbidden();
    }

    public function test_admins_without_commission_rights_cannot_set_pair_rates()
    {
        $partner = Partner::factory()->create();
        $branch = Branch::factory()->create();
        $mapping = PartnerBranchMapping::create(['partner_id' => $partner->id, 'branch_id' => $branch->id, 'status' => 'active']);
        $ops = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();

        $this->actingAs($ops)->put(route('admin.mappings.update', $mapping), [
            'status' => 'active', 'is_deposit_enabled' => true, 'is_withdrawal_enabled' => true, 'deposit_daily_limit' => '-1', 'withdrawal_daily_limit' => '-1',
            'overrides' => ['partner' => ['deposit' => '1', 'withdrawal' => ''], 'branch' => ['deposit' => '', 'withdrawal' => '']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, CommissionRate::where('subject_type', 'mapping')->count());
    }
}
