<?php

namespace Tests\Feature;

use App\Auth\SystemRoles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function portals(): array
    {
        return [
            'admin' => [SystemRoles::ADMIN_OPS, 'admin.dashboard'],
            'partner' => [SystemRoles::PARTNER_VIEWER, 'partner.dashboard'],
            'branch' => [SystemRoles::BRANCH_OPERATOR, 'branch.dashboard'],
        ];
    }

    #[DataProvider('portals')]
    public function test_dashboard_sends_each_user_to_their_own_portal(string $role, string $portalRoute)
    {
        $user = User::factory()->role($role)->withTwoFactor()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route($portalRoute));

        $this->actingAs($user)
            ->get(route($portalRoute))
            ->assertOk();
    }
}
