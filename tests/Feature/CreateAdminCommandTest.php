<?php

namespace Tests\Feature;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_super_admin_and_audits_it()
    {
        $this->artisan('paygate:create-admin', ['--username' => 'root'])
            ->expectsQuestion('Password', 'a-long-Secret-123!')
            ->assertSuccessful();

        $user = User::where('username', 'root')->firstOrFail();

        $this->assertSame(SystemRoles::ADMIN_SUPER, $user->role->slug);
        $this->assertNull($user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'subject_id' => $user->id]);
    }

    public function test_it_rejects_a_duplicate_username()
    {
        User::factory()->create(['username' => 'root']);

        $this->artisan('paygate:create-admin', ['--username' => 'root'])
            ->expectsQuestion('Password', 'a-long-Secret-123!')
            ->assertFailed();
    }
}
