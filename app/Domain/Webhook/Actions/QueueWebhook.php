<?php

namespace App\Domain\Webhook\Actions;

use App\Domain\Payout\PayoutData;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\PayinData;
use App\Domain\Webhook\Jobs\DeliverWebhook;
use App\Domain\Webhook\Models\WebhookEvent;

/**
 * Records a webhook for a pay-in's or payout's new status (call inside the
 * same DB transaction as the status change) and queues its delivery after
 * commit. Nothing is recorded when the partner has no URL for it.
 */
class QueueWebhook
{
    public const PAYIN_EVENTS = ['payin.submitted', 'payin.success', 'payin.rejected', 'payin.expired', 'payin.chargeback', 'payin.refunded'];

    public const PAYOUT_EVENTS = ['payout.success', 'payout.failed', 'payout.returned'];

    public function forPayin(Transaction $payin, string $eventType): ?WebhookEvent
    {
        return $this->record($payin, $eventType, $payin->partner->payin_webhook_url, fn () => PayinData::forPartner($payin->loadMissing('customer')));
    }

    /**
     * Payout results go to the partner's payout webhook URL.
     */
    public function forPayout(Transaction $payout, string $eventType): ?WebhookEvent
    {
        return $this->record($payout, $eventType, $payout->partner->payout_webhook_url, fn () => PayoutData::forPartner($payout->loadMissing(['customer', 'beneficiary'])));
    }

    /**
     * @param  callable(): array<string, mixed>  $data
     */
    private function record(Transaction $payin, string $eventType, ?string $url, callable $data): ?WebhookEvent
    {
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
            'data' => $data(),
        ];
        $event->save();

        DeliverWebhook::dispatch($event->id)->afterCommit();

        return $event;
    }
}
