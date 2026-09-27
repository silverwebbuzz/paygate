<?php

namespace App\Domain\Webhook\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Webhook\Jobs\DeliverWebhook;
use App\Domain\Webhook\Models\WebhookEvent;

/**
 * Sends a webhook again now (e.g. after the partner fixed their endpoint).
 * The payload is unchanged, so partners can deduplicate by its id.
 */
class ResendWebhook
{
    public function handle(User $actor, WebhookEvent $event): void
    {
        $event->forceFill(['status' => 'retrying', 'next_attempt_at' => now()])->save();

        AuditLog::record('webhook.resent', $event->partner, [], ['webhook_event_id' => $event->id, 'event_type' => $event->event_type], $actor);

        DeliverWebhook::dispatch($event->id);
    }
}
