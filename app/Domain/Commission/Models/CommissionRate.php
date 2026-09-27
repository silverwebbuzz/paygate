<?php

namespace App\Domain\Commission\Models;

use App\Domain\Commission\Enums\Direction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A commission rate in force for a period. Rates are never edited: a new rate
 * closes the current one (sets effective_to) and starts where it ends, so the
 * history of every rate stays available for audits and past transactions.
 *
 * @property string $id
 * @property string $subject_type partner / branch / mapping
 * @property string $subject_id
 * @property string $side partner (what the partner pays) / branch (what the branch earns)
 * @property Direction $direction
 * @property string $fee_type
 * @property string $rate_percent e.g. "2.5000"
 * @property array<string, mixed>|null $config
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class CommissionRate extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'config' => 'array',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Rates of one subject, side and direction.
     *
     * @param  Builder<self>  $query
     */
    public function scopeFor(Builder $query, string $subjectType, string $subjectId, string $side, Direction $direction): void
    {
        $query->where([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'side' => $side,
            'direction' => $direction->value,
        ]);
    }

    /**
     * Rates in force at a moment (default: now).
     *
     * @param  Builder<self>  $query
     */
    public function scopeInForceAt(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();

        $query->where('effective_from', '<=', $at)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }
}
