<?php

namespace App\Domain\Partner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An address (or CIDR range) the partner's servers call the API from.
 *
 * @property string $id
 * @property string $partner_id
 * @property string $cidr e.g. 52.66.45.184/32
 * @property string|null $label
 * @property bool $is_active
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class PartnerIpRule extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
     * The address without the /32 (or /128) suffix of a single host.
     */
    public function display(): string
    {
        return preg_replace('#/(32|128)$#', '', $this->cidr) ?? $this->cidr;
    }
}
