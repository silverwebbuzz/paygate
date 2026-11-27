<?php

namespace Tests\Feature\Security;

use App\Domain\Core\Identity\Models\User;
use App\Providers\FortifyServiceProvider;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 2FA is on live only, and only Admin resets a user's 2FA (client request
 * 2026-11-27).
 */
class TwoFactorLiveOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unset_it_follows_the_server()
    {
        config(['paygate.auth.two_factor' => null]);

        config(['paygate.environment' => 'live']);
        $this->assertTrue(TwoFactor::on());

        config(['paygate.environment' => 'sandbox']);
        $this->assertFalse(TwoFactor::on());

        config(['paygate.environment' => 'testbox']);
        $this->assertFalse(TwoFactor::on());
    }

    public function test_off_no_code_is_asked_at_login_and_no_setup_is_forced()
    {
        config(['paygate.auth.two_factor' => false, 'paygate.auth.enforce_two_factor' => true]);
        (new FortifyServiceProvider($this->app))->register();

        $admin = User::factory()->admin()->withTwoFactor()->create();
        $this->post(route('login.store'), ['login' => $admin->username, 'password' => 'password']);
        $this->assertAuthenticatedAs($admin);

        $branch = User::factory()->branch()->create();
        $this->actingAs($branch)->get(route('branch.dashboard'))->assertOk();
    }

    public function test_on_a_user_with_two_factor_is_asked_for_the_code()
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->post(route('login.store'), ['login' => $admin->username, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_only_admin_has_reset_two_factor()
    {
        $this->assertTrue(Route::has('admin.users.two-factor'));
        $this->assertFalse(Route::has('branch.users.two-factor'));
        $this->assertFalse(Route::has('partner.users.two-factor'));
    }
}
