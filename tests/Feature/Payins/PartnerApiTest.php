<?php

namespace Tests\Feature\Payins;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Actions\IssueApiKey;
use App\Domain\Partner\Actions\RevokeApiKey;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Domain\Partner\Models\PartnerIpRule;
use App\Domain\PartnerApi\Models\ApiRequestLog;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class PartnerApiTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildNetwork();
    }

    public function test_ping_is_public()
    {
        $this->getJson('http://api.paygate.local/v1/ping')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_unsigned_or_badly_signed_requests_are_refused_with_a_stable_error_shape()
    {
        $this->postJson('http://api.paygate.local/v1/payins', $this->payinBody())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);

        $this->api('POST', '/v1/payins', $this->payinBody(), secret: 'sk_test_wrong')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_signature');

        $this->api('POST', '/v1/payins', $this->payinBody(), keyId: 'pk_test_unknown')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_old_timestamps_and_reused_nonces_are_refused()
    {
        $body = '';
        $sign = fn (string $timestamp, string $nonce) => hash_hmac('sha256', implode("\n", [$timestamp, $nonce, 'GET', '/v1/payins/X', hash('sha256', $body)]), $this->secret);
        $headers = fn (string $timestamp, string $nonce) => [
            'HTTP_X_KEY_ID' => $this->keyId, 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $sign($timestamp, $nonce), 'HTTP_ACCEPT' => 'application/json',
        ];

        $old = (string) (time() - 600);
        $this->call('GET', 'http://api.paygate.local/v1/payins/X', [], [], [], $headers($old, 'nonce-old-0000000001'))
            ->assertJsonPath('error.code', 'timestamp_out_of_range');

        $now = (string) time();
        $this->call('GET', 'http://api.paygate.local/v1/payins/X', [], [], [], $headers($now, 'nonce-once-000000001'))->assertNotFound();
        $this->call('GET', 'http://api.paygate.local/v1/payins/X', [], [], [], $headers($now, 'nonce-once-000000001'))
            ->assertJsonPath('error.code', 'nonce_reused');
    }

    public function test_a_revoked_key_stops_working()
    {
        app(RevokeApiKey::class)->handle($this->networkAdmin, PartnerApiKey::where('key_id', $this->keyId)->firstOrFail(), 'test');

        $this->api('GET', '/v1/payins/X')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_calls_from_unlisted_ips_are_refused_and_an_empty_list_blocks_everything()
    {
        PartnerIpRule::query()->update(['cidr' => '10.0.0.0/24']);
        $this->api('POST', '/v1/payins', $this->payinBody())->assertForbidden()->assertJsonPath('error.code', 'ip_not_allowed');

        PartnerIpRule::query()->delete();
        $this->api('POST', '/v1/payins', $this->payinBody())->assertForbidden()->assertJsonPath('error.code', 'ip_not_allowed');

        config(['paygate.api.enforce_ip_allowlist' => false]);
        $this->api('POST', '/v1/payins', $this->payinBody())->assertCreated();
    }

    public function test_inactive_partners_and_disabled_payins_are_refused()
    {
        $this->partner->update(['is_payin_enabled' => false]);
        $this->api('POST', '/v1/payins', $this->payinBody())->assertForbidden()->assertJsonPath('error.code', 'payin_disabled');

        $this->partner->update(['status' => 'suspended']);
        $this->api('POST', '/v1/payins', $this->payinBody())->assertForbidden()->assertJsonPath('error.code', 'partner_inactive');
    }

    public function test_creating_a_payin_returns_a_payment_link_and_repeats_are_safe()
    {
        $body = $this->payinBody(['order_id' => 'ORD-1', 'metadata' => ['cart' => '42']]);

        $first = $this->api('POST', '/v1/payins', $body)
            ->assertCreated()
            ->assertJsonPath('data.order_id', 'ORD-1')
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.amount', 1253400)
            ->assertJsonPath('data.customer_id', 'cust-1')
            ->assertJsonPath('data.metadata.cart', '42')
            ->json('data');

        $this->assertStringStartsWith('http://pay.paygate.local/p/', $first['payment_url']);
        $this->assertMatchesRegularExpression('/^PI\d{6}[A-Z2-9]{8}$/', $first['id']);
        $this->assertArrayNotHasKey('branch', $first);

        // Same order again (e.g. after a timeout): the original, same link.
        $this->api('POST', '/v1/payins', $body)
            ->assertOk()
            ->assertJsonPath('data.id', $first['id'])
            ->assertJsonPath('data.payment_url', $first['payment_url']);

        // Same order id, different amount: refused.
        $this->api('POST', '/v1/payins', [...$body, 'amount' => 999900])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'duplicate_order_id')
            ->assertJsonPath('error.id', $first['id']);

        $this->assertSame(1, Transaction::count());
        $this->assertDatabaseHas('partner_customers', ['partner_id' => $this->partner->id, 'external_id' => 'cust-1', 'mobile' => '9876543210']);
    }

    public function test_invalid_requests_list_the_fields()
    {
        $this->api('POST', '/v1/payins', ['order_id' => 'bad id!', 'amount' => '12.50'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['order_id', 'amount', 'customer']]]);

        $this->api('POST', '/v1/payins', $this->payinBody(['amount' => 5000]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'amount_out_of_range')
            ->assertJsonPath('error.min_amount', 10000);
    }

    public function test_return_urls_must_be_on_the_partners_domain()
    {
        $this->api('POST', '/v1/payins', $this->payinBody(['return_url' => 'https://evil.example/steal']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'return_url_not_allowed');

        $this->api('POST', '/v1/payins', $this->payinBody(['return_url' => 'https://shop.atoz.example/thanks']))->assertCreated();
    }

    public function test_payins_are_found_by_id_or_order_id_and_only_by_their_partner()
    {
        [$reference] = $this->createPayin(['order_id' => 'ORD-FIND']);

        $this->api('GET', "/v1/payins/{$reference}")->assertOk()->assertJsonPath('data.order_id', 'ORD-FIND');
        $this->api('GET', '/v1/payins?order_id=ORD-FIND')->assertOk()->assertJsonPath('data.id', $reference);

        // Another partner can't see it.
        $other = Partner::factory()->create();
        $issued = app(IssueApiKey::class)->handle(User::factory()->admin()->create(), $other);
        PartnerIpRule::create(['partner_id' => $other->id, 'cidr' => '127.0.0.1/32']);

        $this->api('GET', "/v1/payins/{$reference}", secret: $issued['secret'], keyId: $issued['key']->key_id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_many_statuses_at_once()
    {
        [$a] = $this->createPayin(['order_id' => 'ORD-A']);
        $this->createPayin(['order_id' => 'ORD-B']);

        $this->api('POST', '/v1/payins/status', ['ids' => [$a, 'PI000000NOPE'], 'order_ids' => ['ORD-B']])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('not_found', ['PI000000NOPE']);
    }

    public function test_open_payins_can_be_cancelled_once()
    {
        [$reference] = $this->createPayin();

        $this->api('POST', "/v1/payins/{$reference}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.payment_url', null);
        $this->api('POST', "/v1/payins/{$reference}/cancel")->assertStatus(409)->assertJsonPath('error.code', 'not_cancellable');
    }

    public function test_partners_are_rate_limited()
    {
        config(['paygate.api.rate_limit' => 2]);

        $this->api('GET', '/v1/payins/X')->assertNotFound();
        $this->api('GET', '/v1/payins/X')->assertNotFound();
        $this->api('GET', '/v1/payins/X')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_every_call_is_logged_without_bodies()
    {
        $this->createPayin(['order_id' => 'ORD-LOG']);
        $this->postJson('http://api.paygate.local/v1/payins', [])->assertUnauthorized();

        $this->assertDatabaseHas('api_request_logs', ['partner_id' => $this->partner->id, 'method' => 'POST', 'path' => '/v1/payins', 'status_code' => 201, 'partner_transaction_id' => 'ORD-LOG']);
        $this->assertDatabaseHas('api_request_logs', ['partner_id' => null, 'status_code' => 401]);
        $this->assertSame(2, ApiRequestLog::count());
    }
}
