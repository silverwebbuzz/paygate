<?php

namespace App\Domain\Webhook\Actions;

use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\PayinData;
use App\Domain\Webhook\Jobs\DeliverWebhook;
use App\Domain\Webhook\Models\WebhookEvent;

/**
 * Records a webhook for a pay-in's new status (call inside the same DB
 * transaction as the status change) and queues its delivery after commit.
 * Nothing is recorded when the partner has no pay-in webhook URL.
 */
class QueueWebhook
{
    public const PAYIN_EVENTS = ['payin.submitted', 'payin.success', 'payin.rejected', 'payin.expired'];

    public function forPayin(Transaction $payin, string $eventType): ?WebhookEvent
    {
        $url = $payin->partner->payin_webhook_url;

        if ($url === null || $url === '') {
            return null;
        }

        $event = new WebhookEvent([
            'partner_id' => $payin->partner_id,
            'transaction_id' => $payin->id,
            'event_type' => $eventType,
            'url' => $url,
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);
        $event->id = $event->newUniqueId();
        $event->payload = [
            'id' => $event->id,
            'type' => $eventType,
            'created_at' => now()->toIso8601String(),
            'data' => PayinData::forPartner($payin->loadMissing('customer')),
        ];
        $event->save();

        DeliverWebhook::dispatch($event->id)->afterCommit();

        return $event;
    }
}
