<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_name_can_be_updated_and_is_audited()
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'New Name'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('New Name', $user->refresh()->name);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'user.profile_updated',
            'subject_id' => $user->id,
        ]);
    }

    public function test_email_cannot_be_changed_by_the_user()
    {
        $user = User::factory()->create(['email' => 'original@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'attacker@example.com'])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('original@example.com', $user->refresh()->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_users_cannot_delete_their_own_account()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertStatus(405);

        $this->assertModelExists($user);
    }
}
