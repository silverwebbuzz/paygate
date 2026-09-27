<?php

namespace Tests\Feature\Security;

use App\Domain\Core\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['paygate.auth.enforce_two_factor' => true]);
    }

    public function test_admins_without_two_factor_are_sent_to_set_it_up()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('security.edit'));
    }

    public function test_branch_users_without_two_factor_are_sent_to_set_it_up()
    {
        $branch = User::factory()->branch()->create();

        $this->actingAs($branch)
            ->get(route('branch.dashboard'))
            ->assertRedirect(route('security.edit'));
    }

    public function test_users_with_two_factor_can_use_their_portal()
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_partners_are_not_forced_to_use_two_factor()
    {
        $partner = User::factory()->partner()->create();

        $this->actingAs($partner)->get(route('partner.dashboard'))->assertOk();
    }

    public function test_enforcement_can_be_switched_off_for_local_development()
    {
        config(['paygate.auth.enforce_two_factor' => false]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }
}
