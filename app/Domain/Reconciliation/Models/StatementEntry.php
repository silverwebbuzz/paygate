<?php

namespace App\Domain\Reconciliation\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Transaction\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One line of a branch account's bank statement, typed in or imported.
 * Separate from transactions and linked to one when it matches (Req STM-05).
 * Amounts in paise, always positive; `entry_direction` says credit / debit.
 *
 * Status:
 * - matched: linked to a deposit still waiting for the branch's approval
 *   ("bank credit found");
 * - reconciled: linked to an approved deposit / paid payout;
 * - unmatched / duplicate: has an open case in the unsettled queue;
 * - ignored: its case was closed as not a customer payment or returned.
 *
 * @property string $id
 * @property string|null $import_id
 * @property string $branch_id
 * @property string $payment_account_id
 * @property CarbonInterface $value_date
 * @property CarbonInterface|null $posted_at
 * @property string $entry_direction credit / debit
 * @property int $amount
 * @property string|null $utr
 * @property string|null $utr_normalized
 * @property string|null $description
 * @property string $row_hash
 * @property string $status
 * @property string|null $transaction_id
 * @property CarbonInterface|null $matched_at
 * @property string|null $matched_by
 * @property array<string, mixed>|null $raw
 * @property string|null $created_by
 * @property CarbonInterface $created_at
 * @property-read Branch $branch
 * @property-read PaymentAccount $paymentAccount
 * @property-read Transaction|null $transaction
 * @property-read StatementImport|null $import
 * @property-read User|null $creator
 * @property-read User|null $matcher
 * @property-read ReconciliationCase|null $openCase
 */
class StatementEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value_date' => 'immutable_date',
            'posted_at' => 'datetime',
            'amount' => 'integer',
            'matched_at' => 'datetime',
            'raw' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function isCredit(): bool
    {
        return $this->entry_direction === 'credit';
    }

    /**
     * Stops the same bank line being stored twice. The key is the UTR when
     * there is one, else the description; `$occurrence` tells apart identical
     * lines within one file (the 2nd, 3rd… copy), so re-importing the file
     * or an overlapping one still finds them as duplicates.
     */
    public static function rowHash(string $accountId, string $valueDate, string $direction, int $amount, ?string $utr, ?string $description, int $occurrence = 1): string
    {
        $key = $utr ?? mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $description)) ?? '');

        return hash('sha256', implode('|', [$accountId, $valueDate, $direction, $amount, $key, $occurrence]));
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<PaymentAccount, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<StatementImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(StatementImport::class, 'import_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    /**
     * @return HasMany<ReconciliationCase, $this>
     */
    public function cases(): HasMany
    {
        return $this->hasMany(ReconciliationCase::class)->orderBy('created_at');
    }

    /**
     * @return HasOne<ReconciliationCase, $this>
     */
    public function openCase(): HasOne
    {
        return $this->hasOne(ReconciliationCase::class)->where('status', '!=', 'resolved');
    }
}
