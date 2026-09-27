<?php

namespace Tests\Feature\Security;

use App\Domain\Core\Audit\Enums\SecurityEvent;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Audit\Models\SecurityLog;
use App\Domain\Core\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_login_is_logged_with_the_attempted_email()
    {
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong']);

        $this->assertDatabaseHas('security_logs', [
            'event' => SecurityEvent::LoginFailed->value,
            'email' => 'nobody@example.com',
            'ip_address' => '127.0.0.1',
        ]);
    }

    public function test_successful_login_is_logged_and_recorded_on_the_user()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('security_logs', [
            'event' => SecurityEvent::LoginSucceeded->value,
            'user_id' => $user->id,
        ]);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertSame('127.0.0.1', $user->last_login_ip);
    }

    public function test_log_entries_carry_the_request_id()
    {
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong'], [
            'X-Request-Id' => 'test-request-0001',
        ])->assertHeader('X-Request-Id', 'test-request-0001');

        $this->assertDatabaseHas('security_logs', ['request_id' => 'test-request-0001']);
    }

    public function test_security_logs_cannot_be_changed_or_deleted()
    {
        $log = SecurityLog::record(SecurityEvent::LoginFailed, email: 'someone@example.com');

        $this->assertThrowsAppendOnly(fn () => $log->update(['email' => 'changed@example.com']));
        $this->assertThrowsAppendOnly(fn () => $log->delete());
    }

    public function test_audit_logs_cannot_be_changed_or_deleted()
    {
        $user = User::factory()->create();
        $log = AuditLog::record('user.created', $user, new: ['email' => $user->email], actor: $user);

        $this->assertThrowsAppendOnly(fn () => $log->update(['action' => 'something.else']));
        $this->assertThrowsAppendOnly(fn () => $log->delete());
    }

    public function test_users_with_log_history_cannot_be_deleted()
    {
        $user = User::factory()->create();
        SecurityLog::record(SecurityEvent::LoginSucceeded, $user);

        $this->expectException(QueryException::class);

        $user->delete();
    }

    private function assertThrowsAppendOnly(callable $callback): void
    {
        try {
            // Savepoint, so the aborted statement doesn't poison the test transaction.
            DB::transaction($callback);
            $this->fail('Expected the database to reject modifying a log row.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }
}
