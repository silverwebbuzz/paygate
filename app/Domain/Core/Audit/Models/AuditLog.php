<?php

namespace App\Domain\Core\Audit\Models;

use App\Domain\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

/**
 * Append-only record of who changed what (enforced by a database trigger).
 *
 * @property string $id
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * Record an action performed by the current user (or the system when there is none).
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function record(string $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null): self
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $actor ??= $request?->user();

        return self::create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => Context::get('request_id'),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
