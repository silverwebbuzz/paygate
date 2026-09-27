<?php

namespace Tests\Feature\Security;

use App\Domain\Core\Audit\Enums\SecurityEvent;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_cannot_open_another_portal()
    {
        $partner = User::factory()->partner()->create();
        $branch = User::factory()->branch()->withTwoFactor()->create();
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($partner)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($partner)->get(route('branch.dashboard'))->assertForbidden();
        $this->actingAs($branch)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($branch)->get(route('partner.dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('partner.dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('branch.dashboard'))->assertForbidden();
    }

    public function test_denied_access_is_written_to_the_security_log()
    {
        $partner = User::factory()->partner()->create();

        $this->actingAs($partner)->get(route('admin.dashboard'))->assertForbidden();

        $this->assertDatabaseHas('security_logs', [
            'event' => SecurityEvent::AccessDenied->value,
            'user_id' => $partner->id,
        ]);
    }

    public function test_guests_are_sent_to_login_from_every_portal()
    {
        foreach (['admin.dashboard', 'partner.dashboard', 'branch.dashboard'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_horizon_is_only_for_admins_with_the_horizon_permission()
    {
        $superAdmin = User::factory()->admin(SystemRoles::ADMIN_SUPER)->withTwoFactor()->create();
        $ops = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();
        $partner = User::factory()->partner()->create();

        $this->actingAs($superAdmin)->get('/horizon')->assertOk();
        $this->actingAs($ops)->get('/horizon')->assertForbidden();
        $this->actingAs($partner)->get('/horizon')->assertForbidden();
    }
}
