<?php

namespace App\Domain\Reconciliation\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Reconciliation\Enums\CaseType;
use App\Domain\Reconciliation\Enums\Resolution;
use App\Domain\Transaction\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An exception in the unsettled-UTR queue (Requirements §7.7): a bank line
 * that didn't match cleanly. Open until someone resolves it; every
 * resolution is audited. An operational queue, not a transaction status.
 *
 * @property string $id
 * @property string $reference e.g. RC260928K7QX4MZD
 * @property CaseType $type
 * @property string $status open / resolved
 * @property Resolution|null $resolution
 * @property string $branch_id
 * @property string|null $transaction_id the transaction the bank line points at, if any
 * @property string|null $statement_entry_id
 * @property string|null $assigned_to
 * @property string|null $notes
 * @property string|null $resolved_by
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Branch $branch
 * @property-read Transaction|null $transaction
 * @property-read StatementEntry|null $entry
 * @property-read User|null $resolver
 */
class ReconciliationCase extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CaseType::class,
            'resolution' => Resolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    public static function newReference(): string
    {
        return 'RC'.substr(Transaction::newPayinReference(), 2);
    }

    public function isOpen(): bool
    {
        return $this->status !== 'resolved';
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<StatementEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(StatementEntry::class, 'statement_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
