<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One balanced booking (entries sum to zero, checked by a database trigger
 * at commit). Append-only; corrections are new `reversal` journals.
 *
 * @property string $id
 * @property string $type payin_success / payout_success / reversal / adjustment / settlement
 * @property string|null $transaction_id
 * @property string|null $description
 * @property Carbon $posted_at
 * @property string|null $created_by
 * @property string|null $request_id
 */
class LedgerJournal extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'journal_id');
    }
}
