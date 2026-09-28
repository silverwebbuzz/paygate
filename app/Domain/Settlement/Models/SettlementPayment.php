<?php

namespace App\Domain\Settlement\Models;

use App\Domain\Core\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin's "tick as settled" for one settlement line: money that moved
 * outside PayGate. Posts a `settlement` journal. Never edited.
 *
 * @property string $id
 * @property string $settlement_id
 * @property string|null $settlement_line_id
 * @property int $amount
 * @property string $direction party_to_platform / platform_to_party
 * @property string $method
 * @property string|null $external_reference
 * @property CarbonInterface $paid_at
 * @property string $recorded_by
 * @property CarbonInterface $created_at
 * @property-read User $recorder
 */
class SettlementPayment extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'date', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
