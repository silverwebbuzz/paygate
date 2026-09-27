<?php

namespace Tests\Feature\Auth;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Identity\Notifications\UserInvitation;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function invite(): array
    {
        Notification::fake();

        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'New Partner User',
            'email' => 'new@example.com',
            'role_id' => Role::bySlug(SystemRoles::PARTNER_VIEWER)->id,
            'organisation_id' => Partner::factory()->create()->id,
        ])->assertSessionHasNoErrors();

        auth()->logout();

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $token = null;

        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        return [$user, $token];
    }

    public function test_the_invitation_email_links_to_the_set_password_page()
    {
        [$user, $token] = $this->invite();

        $mail = (new UserInvitation($token, 'Admin'))->toMail($user);
        $this->assertStringContainsString('/invitation/'.$token, $mail->actionUrl);

        $this->get(route('invitation.show', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/accept-invitation')->where('email', $user->email));
    }

    public function test_setting_a_password_verifies_the_email_and_allows_login()
    {
        [$user, $token] = $this->invite();

        $this->post(route('invitation.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('a-new-password', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->isInvited());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.invitation_accepted', 'subject_id' => $user->id]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'a-new-password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_link_works_only_once()
    {
        [$user, $token] = $this->invite();
        $payload = ['token' => $token, 'email' => $user->email, 'password' => 'a-new-password', 'password_confirmation' => 'a-new-password'];

        $this->post(route('invitation.store'), $payload)->assertRedirect(route('login'));
        $this->post(route('invitation.store'), $payload)->assertSessionHasErrors('email');
    }

    public function test_invitation_links_last_72_hours()
    {
        [$user, $token] = $this->invite();
        $payload = ['token' => $token, 'email' => $user->email, 'password' => 'a-new-password', 'password_confirmation' => 'a-new-password'];

        $this->travel(71)->hours();
        $this->assertTrue(Password::broker('invites')->tokenExists($user, $token));

        $this->travel(2)->hours();
        $this->post(route('invitation.store'), $payload)->assertSessionHasErrors('email');
        $this->assertTrue($user->fresh()?->isInvited());
    }

    public function test_an_invited_user_cannot_log_in_before_setting_a_password()
    {
        [$user] = $this->invite();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }
}
