<?php

namespace App\Domain\Branch\Models;

use App\Domain\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Admin change to a top-up branch's deposit allowance (append-only; a
 * negative amount is a correction).
 *
 * @property string $id
 * @property string $branch_id
 * @property int $amount
 * @property int $balance_after
 * @property string $reason
 * @property string $created_by
 * @property Carbon $created_at
 */
class BranchLimitTopup extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
