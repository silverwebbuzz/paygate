<?php

namespace Tests\Feature\Security;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Webhook\WebhookTarget;
use App\Http\Api\Middleware\AuthenticatePartner;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * Phase 13 hardening: browser security headers, API brute-force lockout,
 * 2FA before private files, webhook DNS pinning and the config check.
 */
class HardeningTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    public function test_portal_pages_send_security_headers()
    {
        $this->get('http://paygate.local/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Content-Security-Policy', SecurityHeaders::HTML_CSP)
            ->assertHeader('Permissions-Policy')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_over_https()
    {
        $this->get('https://paygate.local/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_api_answers_are_never_cached_and_errors_get_headers_too()
    {
        $this->get('http://api.paygate.local/v1/ping')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Content-Security-Policy');

        $this->assertStringContainsString('no-store', (string) $this->get('http://api.paygate.local/v1/ping')->headers->get('Cache-Control'));
        $this->get('http://api.paygate.local/v1/missing')->assertNotFound()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_repeated_failed_api_authentication_locks_the_ip_out()
    {
        for ($i = 0; $i < AuthenticatePartner::MAX_FAILURES_PER_MINUTE; $i++) {
            $this->postJson('http://api.paygate.local/v1/payins', [])->assertUnauthorized();
        }

        $this->postJson('http://api.paygate.local/v1/payins', [])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_private_files_need_two_factor_where_it_is_required()
    {
        config(['paygate.auth.enforce_two_factor' => true]);
        $this->buildNetwork();

        $file = StoredFile::query()->create([
            'disk' => 'local', 'path' => 'payment_proof/x.png', 'original_name' => 'x.png', 'mime' => 'image/png',
            'size_bytes' => 1, 'sha256' => str_repeat('0', 64), 'attachable_type' => 'transaction',
            'attachable_id' => '00000000-0000-0000-0000-000000000000', 'purpose' => 'payment_proof', 'uploaded_by_type' => 'customer',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('files.show', $file))
            ->assertRedirect(route('security.edit'));
    }

    public function test_webhooks_are_pinned_to_the_checked_public_address()
    {
        config(['paygate.webhooks.allow_private_targets' => false]);

        $this->assertSame([], WebhookTarget::pinnedCurlOptions('https://8.8.8.8/hook'), 'An IP literal needs no pinning.');

        $this->expectException(RuntimeException::class);
        WebhookTarget::pinnedCurlOptions('https://127.0.0.1/hook');
    }

    public function test_webhook_checks_are_skipped_only_when_private_targets_are_allowed()
    {
        config(['paygate.webhooks.allow_private_targets' => true]);

        $this->assertSame([], WebhookTarget::pinnedCurlOptions('http://localhost:8080/hook'));
    }

    public function test_security_check_fails_on_unsafe_settings()
    {
        config(['app.debug' => true, 'session.secure' => false]);

        $this->assertSame(1, Artisan::call('paygate:security-check'));
        $this->assertStringContainsString('APP_DEBUG is off', Artisan::output());
    }
}
