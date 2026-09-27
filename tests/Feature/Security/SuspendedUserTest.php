<?php

namespace Tests\Feature\Security;

use App\Domain\Core\Audit\Enums\SecurityEvent;
use App\Domain\Core\Identity\Enums\UserStatus;
use App\Domain\Core\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuspendedUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspended_users_cannot_log_in()
    {
        $user = User::factory()->suspended()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('security_logs', [
            'event' => SecurityEvent::LoginBlockedSuspended->value,
            'user_id' => $user->id,
        ]);
    }

    public function test_a_user_suspended_while_logged_in_is_logged_out()
    {
        $user = User::factory()->partner()->create();
        $this->actingAs($user)->get(route('partner.dashboard'))->assertOk();

        $user->update(['status' => UserStatus::Suspended]);

        $this->get(route('partner.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('security_logs', [
            'event' => SecurityEvent::SessionTerminatedSuspended->value,
            'user_id' => $user->id,
        ]);
    }

    public function test_suspended_users_have_no_permissions()
    {
        $user = User::factory()->admin()->suspended()->create();

        $this->assertFalse($user->can('partner.manage'));
        $this->assertSame([], $user->permissionNames());
    }
}
