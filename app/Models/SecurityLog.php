<?php

namespace App\Models;

use App\Enums\SecurityEvent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

/**
 * Append-only record of authentication and access events (enforced by a database trigger).
 *
 * @property string $id
 * @property SecurityEvent $event
 * @property string|null $user_id
 * @property string|null $email
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 */
class SecurityLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event' => SecurityEvent::class,
            'context' => 'array',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(SecurityEvent $event, ?User $user = null, ?string $email = null, array $context = []): self
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return self::create([
            'event' => $event,
            'user_id' => $user?->getKey(),
            'email' => $email ?? $user?->email,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => Context::get('request_id'),
            'context' => $context ?: null,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
