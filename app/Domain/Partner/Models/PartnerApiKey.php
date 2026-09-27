<?php

namespace App\Domain\Partner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * API credentials of a partner: a public key id and a secret. The secret is
 * stored encrypted (it is needed to verify request signatures and to sign
 * webhooks) and is shown to people only once, when it is generated.
 *
 * @property string $id
 * @property string $partner_id
 * @property string $key_id
 * @property string $secret_encrypted
 * @property string $secret_last4
 * @property string $status active / rotating / revoked
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class PartnerApiKey extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['secret_encrypted'];

    protected function casts(): array
    {
        return [
            'secret_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
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
     * Whether requests signed with this key are accepted right now.
     */
    public function isUsable(): bool
    {
        return match ($this->status) {
            'active' => true,
            'rotating' => $this->expires_at !== null && $this->expires_at->isFuture(),
            default => false,
        };
    }
}
