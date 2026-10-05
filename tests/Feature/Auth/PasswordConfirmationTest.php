<?php

namespace Tests\Feature\Auth;

use App\Domain\Core\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('password.confirm'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/confirm-password'),
        );
    }

    public function test_the_current_password_opens_security_settings()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('password.confirm'));

        $this->actingAs($user)
            ->post(route('password.confirm.store'), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->post(route('password.confirm.store'), ['password' => 'password'])
            ->assertRedirect(route('security.edit'));

        $this->actingAs($user)
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/security'));
    }

    public function test_password_confirmation_requires_authentication()
    {
        $response = $this->get(route('password.confirm'));

        $response->assertRedirect(route('login'));
    }
}
