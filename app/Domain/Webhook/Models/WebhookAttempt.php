<?php

namespace App\Domain\Webhook\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One delivery try (append-only).
 *
 * @property string $id
 * @property string $webhook_event_id
 * @property int $attempt_no
 * @property int|null $response_status
 * @property string|null $response_body first 2 KB
 * @property int|null $duration_ms
 * @property string|null $error
 * @property Carbon $created_at
 */
class WebhookAttempt extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['response_status' => 'integer', 'duration_ms' => 'integer', 'attempt_no' => 'integer', 'created_at' => 'datetime'];
    }
}
