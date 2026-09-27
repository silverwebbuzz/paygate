<?php

namespace App\Domain\Webhook\Models;

use App\Domain\Partner\Models\Partner;
use App\Domain\Transaction\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A webhook to deliver to a partner (outbox: written in the same database
 * transaction as the status change it reports, so none is ever lost).
 *
 * @property string $id
 * @property string $partner_id
 * @property string|null $transaction_id
 * @property string $event_type e.g. payin.success
 * @property string $url
 * @property array<string, mixed> $payload
 * @property string $status pending / retrying / delivered / failed
 * @property int $attempts
 * @property CarbonInterface|null $next_attempt_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface $created_at
 * @property-read Partner $partner
 */
class WebhookEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return HasMany<WebhookAttempt, $this>
     */
    public function attemptsLog(): HasMany
    {
        return $this->hasMany(WebhookAttempt::class)->orderBy('attempt_no');
    }
}
