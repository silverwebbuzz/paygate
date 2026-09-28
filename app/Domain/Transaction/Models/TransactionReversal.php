<?php

namespace App\Domain\Transaction\Models;

use App\Domain\Core\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chargeback, pay-in refund or returned payout recorded by Admin
 * (decided 2026-09-28, G-20 / G-21 / G-22). One per transaction; never
 * changed (append-only).
 *
 * @property string $id
 * @property string $reference e.g. RV260928K7QX4MZD
 * @property string $transaction_id
 * @property string $kind chargeback / refund / return
 * @property string|null $bearer partner / branch (chargebacks only)
 * @property int $amount
 * @property string $reason
 * @property string|null $external_reference
 * @property string|null $journal_id
 * @property string $created_by
 * @property CarbonInterface $created_at
 * @property-read Transaction $transaction
 * @property-read User $creator
 */
class TransactionReversal extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
