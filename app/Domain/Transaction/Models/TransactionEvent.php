<?php

namespace App\Domain\Transaction\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

/**
 * The transaction timeline: every state change, who caused it and why.
 * Append-only (database trigger).
 *
 * @property string $id
 * @property string $transaction_id
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string $event
 * @property string $actor_type system / user / partner_api / customer
 * @property string|null $actor_id
 * @property string|null $reason
 * @property array<string, mixed>|null $data
 * @property string|null $request_id
 * @property Carbon $created_at
 */
class TransactionEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function record(Transaction $transaction, string $event, ?string $from, ?string $to, string $actorType, ?string $actorId = null, ?string $reason = null, ?array $data = null): self
    {
        return self::create([
            'transaction_id' => $transaction->id,
            'from_status' => $from,
            'to_status' => $to,
            'event' => $event,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason' => $reason,
            'data' => $data,
            'request_id' => Context::get('request_id'),
        ]);
    }
}
