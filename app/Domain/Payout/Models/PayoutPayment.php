<?php

namespace App\Domain\Payout\Models;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Transaction\Models\PayoutReturn;
use App\Domain\Transaction\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One bank transfer of a payout (decided 2026-10-23): the branch may pay a
 * withdrawal in several parts, each with its UTR, date and proof. Append
 * only. See ProcessPayout::pay().
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $branch_id
 * @property int $amount
 * @property string $utr as typed
 * @property string $utr_normalized unique system-wide
 * @property CarbonInterface $paid_at
 * @property string|null $proof_file_id null only on parts made from older payouts
 * @property string|null $note
 * @property string|null $journal_id its journal, booked on its day (`payout_part`; the completing transfer's is `payout_success`)
 * @property bool $fees_booked true on the transfer whose journal booked the fees
 * @property string|null $created_by
 * @property CarbonInterface $created_at
 * @property-read Transaction $transaction
 * @property-read StoredFile|null $proof
 * @property-read User|null $creator
 * @property-read StatementEntry|null $statementEntry
 * @property-read PayoutReturn|null $approvedReturn
 */
class PayoutPayment extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime', 'fees_booked' => 'boolean'];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function proof(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'proof_file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The branch's bank statement line for this transfer.
     *
     * @return HasOne<StatementEntry, $this>
     */
    public function statementEntry(): HasOne
    {
        return $this->hasOne(StatementEntry::class);
    }

    /**
     * The confirmed return of this transfer, if it came back.
     *
     * @return HasOne<PayoutReturn, $this>
     */
    public function approvedReturn(): HasOne
    {
        return $this->hasOne(PayoutReturn::class)->where('status', 'approved');
    }
}
