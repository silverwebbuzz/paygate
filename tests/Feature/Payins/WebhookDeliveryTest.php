<?php

namespace Tests\Feature\Payins;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Webhook\Jobs\DeliverWebhook;
use App\Domain\Webhook\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['paygate.webhooks.allow_private_targets' => true]);
        $this->buildNetwork();
        $this->partner->update(['payin_webhook_url' => 'https://atoz.example/hooks/payin']);
        $this->activeAccount();
    }

    private function event(): WebhookEvent
    {
        $this->submittedPayin();

        return WebhookEvent::firstOrFail();
    }

    public function test_a_webhook_is_delivered_signed_so_the_partner_can_verify_it()
    {
        Http::fake(['atoz.example/*' => Http::response('ok', 200)]);
        $event = $this->event();

        (new DeliverWebhook($event->id))->handle();

        Http::assertSent(function (HttpRequest $request) use ($event) {
            [$t, $v1] = array_map(fn ($part) => explode('=', $part, 2)[1], explode(',', $request->header('X-PayGate-Signature')[0]));

            return $request->url() === 'https://atoz.example/hooks/payin'
                && $request->header('X-PayGate-Event-Id')[0] === $event->id
                && $request->header('X-PayGate-Event')[0] === 'payin.submitted'
                && hash_equals(hash_hmac('sha256', $t.'.'.$request->body(), $this->secret), $v1)
                && $request['data']['status'] === 'payment_submitted'
                && ! isset($request['data']['branch']);
        });

        $event->refresh();
        $this->assertSame('delivered', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertDatabaseHas('webhook_attempts', ['webhook_event_id' => $event->id, 'attempt_no' => 1, 'response_status' => 200]);
    }

    public function test_failures_are_retried_with_growing_waits_then_marked_failed()
    {
        Http::fake(['atoz.example/*' => Http::response('down', 503)]);
        $event = $this->event();

        (new DeliverWebhook($event->id))->handle();
        $event->refresh();
        $this->assertSame('retrying', $event->status);
        $this->assertEqualsWithDelta(now()->addMinute()->timestamp, $event->next_attempt_at?->timestamp, 2);

        // Not due yet: nothing happens.
        (new DeliverWebhook($event->id))->handle();
        $this->assertSame(1, $event->fresh()?->attempts);

        foreach (DeliverWebhook::BACKOFF_MINUTES as $minutes) {
            $this->travel($minutes + 1)->minutes();
            (new DeliverWebhook($event->id))->handle();
        }

        $event->refresh();
        $this->assertSame('failed', $event->status);
        $this->assertSame(7, $event->attempts);
        $this->assertNull($event->next_attempt_at);
    }

    public function test_the_scheduler_picks_up_due_webhooks()
    {
        $this->event();

        $this->assertSame(1, DeliverWebhook::dispatchDue());
        Queue::assertPushedOn('webhooks', DeliverWebhook::class);
    }

    public function test_private_network_targets_are_refused_on_servers()
    {
        config(['paygate.webhooks.allow_private_targets' => false]);
        Http::fake();
        $this->partner->update(['payin_webhook_url' => 'https://127.0.0.1/hook']);
        $event = $this->event();

        (new DeliverWebhook($event->id))->handle();

        Http::assertNothingSent();
        $this->assertStringContainsString('private or reserved', (string) $event->attemptsLog()->first()?->error);
    }

    public function test_partners_and_admin_can_resend_but_not_other_partners()
    {
        Http::fake(['*' => Http::response('', 500)]);
        $event = $this->event();
        (new DeliverWebhook($event->id))->handle();

        $owner = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->create();
        $this->actingAs($owner)->post(route('partner.webhooks.resend', $event))->assertRedirect();
        $this->assertLessThanOrEqual(now()->timestamp, $event->fresh()?->next_attempt_at?->timestamp);
        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.resent', 'actor_id' => $owner->id]);

        $stranger = User::factory()->partner(SystemRoles::PARTNER_OWNER)->create();
        $this->actingAs($stranger)->post(route('partner.webhooks.resend', $event))->assertNotFound();
    }
}
