<?php

namespace App\Domain\Webhook\Jobs;

use App\Domain\Notification\AlertDispatcher;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Domain\Webhook\Models\WebhookAttempt;
use App\Domain\Webhook\Models\WebhookEvent;
use App\Domain\Webhook\WebhookTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one webhook (Architecture §10):
 *
 *   POST <partner url>, JSON body = the stored payload
 *   X-PayGate-Event-Id:  the event id (deduplicate on it)
 *   X-PayGate-Event:     e.g. payin.success
 *   X-PayGate-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>
 *
 * signed with the partner's current API secret. Only a 2xx answer within
 * 10 s counts. Otherwise it is retried after 1 m, 5 m, 15 m, 1 h, 6 h and
 * 24 h, then marked failed (a person can resend it). Redirects are not
 * followed and private network addresses are refused (SSRF).
 *
 * Safe to run twice: the event is claimed with a short lease first.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Minutes to wait after attempt 1, 2, … */
    public const BACKOFF_MINUTES = [1, 5, 15, 60, 360, 1440];

    public int $tries = 1;

    public function __construct(public string $eventId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        // Claim: only one worker delivers an event at a time.
        $claimed = DB::table('webhook_events')
            ->where('id', $this->eventId)
            ->whereIn('status', ['pending', 'retrying'])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['next_attempt_at' => now()->addMinutes(2)]);

        if ($claimed !== 1) {
            return;
        }

        $event = WebhookEvent::query()->with('partner')->findOrFail($this->eventId);
        $attemptNo = $event->attempts + 1;
        $started = microtime(true);
        $status = null;
        $body = null;
        $error = null;

        try {
            WebhookTarget::ensureAllowed($event->url);

            $secret = PartnerApiKey::query()->where('partner_id', $event->partner_id)->where('status', 'active')->first()?->secret_encrypted;

            if (! is_string($secret)) {
                throw new \RuntimeException('The partner has no active API key to sign with.');
            }

            $json = (string) json_encode($event->payload);
            $timestamp = time();

            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'PayGate-Webhooks/1',
                    'X-PayGate-Event-Id' => $event->id,
                    'X-PayGate-Event' => $event->event_type,
                    'X-PayGate-Signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$json, $secret),
                ])
                ->withBody($json, 'application/json')
                ->post($event->url);

            $status = $response->status();
            $body = mb_substr($response->body(), 0, 2048);
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 1000);
        }

        $delivered = $status !== null && $status >= 200 && $status < 300;

        DB::transaction(function () use ($event, $attemptNo, $started, $status, $body, $error, $delivered) {
            WebhookAttempt::create([
                'webhook_event_id' => $event->id,
                'attempt_no' => $attemptNo,
                'response_status' => $status,
                'response_body' => $body,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $error,
            ]);

            $wait = self::BACKOFF_MINUTES[$attemptNo - 1] ?? null;

            $event->forceFill([
                'attempts' => $attemptNo,
                'status' => $delivered ? 'delivered' : ($wait === null ? 'failed' : 'retrying'),
                'delivered_at' => $delivered ? now() : null,
                'next_attempt_at' => $delivered || $wait === null ? null : now()->addMinutes($wait),
            ])->save();

            if (! $delivered && $wait === null) {
                app(AlertDispatcher::class)->webhookFailed($event);
            }
        });
    }

    /**
     * Queues every webhook whose retry time has come (run every minute, so
     * nothing is lost if a queued job disappears).
     */
    public static function dispatchDue(int $limit = 200): int
    {
        $ids = WebhookEvent::query()
            ->whereIn('status', ['pending', 'retrying'])
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            self::dispatch((string) $id);
        }

        return $ids->count();
    }
}
