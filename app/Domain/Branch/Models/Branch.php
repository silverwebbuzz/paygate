<?php

namespace App\Domain\Branch\Models;

use App\Domain\Core\Identity\Models\User;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An operational payment entity that provides bank / UPI accounts.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $status
 */
#[UseFactory(BranchFactory::class)]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_deposit_enabled' => 'boolean',
            'is_withdrawal_enabled' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
