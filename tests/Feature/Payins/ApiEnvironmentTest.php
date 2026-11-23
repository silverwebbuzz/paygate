<?php

namespace Tests\Feature\Payins;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Actions\IssueApiKey;
use App\Domain\Partner\GoLiveCheck;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Domain\PartnerApi\Models\ApiRequestLog;
use App\Domain\Webhook\Jobs\DeliverWebhook;
use App\Domain\Webhook\Models\WebhookEvent;
use Database\Seeders\TestPartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * Client request 2026-11-23: partners use testbox / sandbox / live servers.
 * Each answer and webhook says which server sent it, a key of the other kind
 * is refused (wrong_environment), calls are traced (host, key id, error),
 * and the go-live check flags partner URLs that look like test sites.
 */
class ApiEnvironmentTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['paygate.webhooks.allow_private_targets' => true, 'paygate.environment' => 'sandbox']);
        $this->buildNetwork();
        $this->partner->update(['payin_webhook_url' => 'https://atoz.example/hooks/payin']);
        $this->activeAccount();
    }

    public function test_every_answer_says_which_server_gave_it()
    {
        $this->assertStringStartsWith('pk_test_', $this->keyId);

        $this->getJson('http://api.paygate.local/v1/ping')
            ->assertHeader('X-PayGate-Environment', 'sandbox')
            ->assertJsonPath('environment', 'sandbox');

        $this->api('POST', '/v1/payins', $this->payinBody())
            ->assertCreated()
            ->assertHeader('X-PayGate-Environment', 'sandbox')
            ->assertJsonPath('environment', 'sandbox')
            ->assertJsonMissingPath('warnings');

        // Errors too.
        $this->api('GET', '/v1/payins/NOPE')
            ->assertNotFound()
            ->assertHeader('X-PayGate-Environment', 'sandbox')
            ->assertJsonPath('environment', 'sandbox')
            ->assertJsonPath('error.code', 'not_found');

        // The top-bar pill shows the server.
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get(route('admin.ip-management.index'))
            ->assertInertia(fn (Assert $page) => $page->where('environment', 'sandbox'));
    }

    public function test_a_webhook_says_which_server_sent_it()
    {
        Http::fake(['atoz.example/*' => Http::response('ok', 200)]);
        $this->submittedPayin();
        config(['paygate.environment' => 'testbox']);

        (new DeliverWebhook(WebhookEvent::firstOrFail()->id))->handle();

        Http::assertSent(fn (HttpRequest $request) => $request->header('X-PayGate-Environment')[0] === 'testbox'
            && $request['environment'] === 'testbox'
            && $request['type'] === 'payin.submitted');
    }

    public function test_a_test_key_on_the_live_server_is_refused_before_lookup_and_traced()
    {
        config(['paygate.environment' => 'live', 'paygate.api.other_environment_url' => 'https://api.sandbox.example/v1/']);

        // A sandbox key, unknown on live: still told it is the wrong server.
        $this->api('POST', '/v1/payins', $this->payinBody(), keyId: 'pk_test_fromsandbox')
            ->assertUnauthorized()
            ->assertHeader('X-PayGate-Environment', 'live')
            ->assertJsonPath('environment', 'live')
            ->assertJsonPath('error.code', 'wrong_environment')
            ->assertJsonPath('error.key_kind', 'test')
            ->assertJsonPath('error.server_environment', 'live')
            ->assertJsonPath('error.message', 'This is a test key (pk_test_…), but this is the live server. Use the key this server issued, or send test-key requests to https://api.sandbox.example/v1.');

        $log = ApiRequestLog::query()->latest('created_at')->firstOrFail();
        $this->assertSame('wrong_environment', $log->error_code);
        $this->assertSame('pk_test_fromsandbox', $log->key_id_sent);
        $this->assertSame('api.paygate.local', $log->host);
        $this->assertNull($log->partner_id);

        // Admin sees it on IP Management.
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get(route('admin.ip-management.index'))
            ->assertInertia(fn (Assert $page) => $page->where('wrong_server.0.key_id', 'pk_test_fromsandbox')->where('wrong_server.0.error_code', 'wrong_environment'));

        // A key issued on live works there.
        $issued = app(IssueApiKey::class)->handle($this->networkAdmin, $this->partner);
        $this->assertStringStartsWith('pk_live_', $issued['key']->key_id);
        $this->assertStringStartsWith('sk_live_', $issued['secret']);
        $this->api('POST', '/v1/payins', $this->payinBody(), secret: $issued['secret'], keyId: $issued['key']->key_id)->assertCreated();
    }

    public function test_a_live_key_on_a_test_server_is_refused_and_an_unknown_key_names_the_server()
    {
        $this->api('GET', '/v1/balance', keyId: 'pk_live_abc')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'wrong_environment')
            ->assertJsonPath('error.message', 'This is a live key (pk_live_…), but this is the sandbox (staging) server. Use the key this server issued, or send live-key requests to the live server.');

        // A testbox key on sandbox: same kind, so unknown here, and the message names this server.
        $this->api('GET', '/v1/balance', keyId: 'pk_test_fromtestbox')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonPath('error.message', 'Unknown or revoked API key on the sandbox (staging) server. Check that your base URL is the server that issued this key.');
    }

    public function test_a_refused_call_with_the_partners_key_shows_in_their_api_logs()
    {
        $this->api('GET', '/v1/balance', secret: 'sk_test_wrong')->assertJsonPath('error.code', 'invalid_signature');

        $log = ApiRequestLog::query()->sole();
        $this->assertSame($this->partner->id, $log->partner_id);
        $this->assertSame($this->keyId, $log->key_id_sent);
        $this->assertSame('invalid_signature', $log->error_code);

        $developer = User::factory()->partner(SystemRoles::PARTNER_DEVELOPER, $this->partner)->create();
        $this->actingAs($developer)->get(route('partner.api-logs'))
            ->assertInertia(fn (Assert $page) => $page->where('logs.data.0.error_code', 'invalid_signature')
                ->where('logs.data.0.key_id', $this->keyId)
                ->where('server.environment', 'sandbox'));
    }

    public function test_the_check_can_be_switched_off_and_the_command_lists_keys_it_would_refuse()
    {
        config(['paygate.environment' => 'live']);

        $this->artisan('paygate:key-environments')
            ->expectsOutputToContain('This server: live')
            ->expectsOutputToContain('1 usable key(s) are of the other kind')
            ->assertSuccessful();

        config(['paygate.api.enforce_key_environment' => false]);
        $this->api('POST', '/v1/payins', $this->payinBody())->assertCreated();

        PartnerApiKey::query()->update(['status' => 'revoked']);
        $this->artisan('paygate:key-environments')->expectsOutputToContain('No usable key is of the other kind.')->assertSuccessful();
    }

    public function test_the_go_live_check_flags_test_urls_on_live_only()
    {
        $this->partner->update([
            'return_url' => 'https://staging.shop.in/done',
            'callback_url' => 'https://shop.in/callback',
            'payin_webhook_url' => 'https://partner4.a27pay.tech/webhook.php',
            'payout_webhook_url' => null,
            'is_payout_enabled' => true,
        ]);

        // Sandbox: test URLs are expected; only the missing one is flagged.
        $check = app(GoLiveCheck::class)->check($this->partner->refresh());
        $states = collect($check['items'])->pluck('state', 'field');
        $this->assertSame('ok', $states['return_url']);
        $this->assertSame('missing', $states['payout_webhook_url']);
        $this->assertFalse($check['ready']);

        config(['paygate.environment' => 'live', 'paygate.other_hosts' => 'a27pay.tech, testbox.example.org']);
        $check = app(GoLiveCheck::class)->check($this->partner->refresh());
        $items = collect($check['items'])->keyBy('field');
        $this->assertSame('warning', $items['return_url']['state']);
        $this->assertStringContainsString('"staging"', (string) $items['return_url']['reason']);
        $this->assertSame('ok', $items['callback_url']['state']);
        $this->assertStringContainsString('a27pay.tech', (string) $items['payin_webhook_url']['reason']);
        $this->assertSame('Not https.', GoLiveCheck::problem('http://shop.in/x'));
        $this->assertSame('A local / test address, not reachable on the internet.', GoLiveCheck::problem('https://shop.local/x'));
        $this->assertNull(GoLiveCheck::problem('https://contest.shop.in/x'));

        // Admin's partner drawer shows it.
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get(route('admin.partners.index', ['partner' => $this->partner->id]), [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'admin/partners/index',
                'X-Inertia-Partial-Data' => 'detail',
                'X-Inertia-Version' => Inertia::getVersion(),
            ])
            ->assertOk()
            ->assertJsonPath('props.detail.go_live.environment', 'live')
            ->assertJsonPath('props.detail.go_live.items.0.state', 'warning');
    }

    public function test_on_live_a_test_looking_return_url_is_accepted_with_a_warning()
    {
        config(['paygate.environment' => 'live']);
        $issued = app(IssueApiKey::class)->handle($this->networkAdmin, $this->partner);

        $this->api('POST', '/v1/payins', $this->payinBody(['return_url' => 'https://dev.atoz.example.com/done']), secret: $issued['secret'], keyId: $issued['key']->key_id)
            ->assertCreated()
            ->assertJsonPath('warnings.0', 'return_url: The address contains "dev": looks like a test site.');
    }

    public function test_test_partners_are_never_seeded_on_live()
    {
        config(['paygate.environment' => 'live']);

        $this->expectExceptionMessage('TestPartnerSeeder is for sandbox / testbox only');
        $this->seed(TestPartnerSeeder::class);
    }
}
