<?php

namespace Tests\Feature\Auth;

use App\Domain\Core\Identity\Models\User;
use App\Support\SingleSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One login per user at a time (client request 2026-11-27): a newer sign-in
 * on another browser ends the older session.
 */
class SingleSessionTest extends TestCase
{
    use RefreshDatabase;

    private function logIn(User $user): void
    {
        $this->post(route('login.store'), ['login' => $user->username, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_login_stores_the_same_token_on_the_user_and_in_the_session()
    {
        $user = User::factory()->partner()->create();

        $this->logIn($user);

        $token = $user->fresh()?->session_token;
        $this->assertIsString($token);
        $this->assertSame($token, session(SingleSession::SESSION_KEY));
        $this->get(route('partner.dashboard'))->assertOk();
    }

    public function test_the_older_browser_is_logged_out_when_the_user_logs_in_elsewhere()
    {
        $user = User::factory()->partner()->create();
        $this->logIn($user);

        // The same username signs in on another browser: a new token.
        $user->fresh()?->forceFill(['session_token' => str_repeat('x', 64)])->saveQuietly();
        // A real next request loads the user afresh.
        $this->app['auth']->forgetGuards();

        $this->get(route('partner.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->assertDatabaseHas('security_logs', ['event' => 'session.replaced', 'user_id' => $user->id]);
    }

    public function test_a_new_login_replaces_the_token()
    {
        $user = User::factory()->partner()->create();
        $this->logIn($user);
        $first = $user->fresh()?->session_token;

        $this->post(route('logout'));
        $this->logIn($user);

        $this->assertNotSame($first, $user->fresh()?->session_token);
        $this->get(route('partner.dashboard'))->assertOk();
    }
}
