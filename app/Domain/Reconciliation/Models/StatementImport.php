<?php

namespace App\Domain\Reconciliation\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Platform\Models\StoredFile;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded statement file of one account (Statement History). The file
 * itself is kept privately; the same file can't be imported twice for an
 * account (file_sha256).
 *
 * @property string $id
 * @property string $branch_id
 * @property string $payment_account_id
 * @property string $source upload / manual_entry / auto
 * @property string|null $file_id
 * @property string|null $file_sha256
 * @property string|null $template_id
 * @property CarbonInterface|null $period_from
 * @property CarbonInterface|null $period_to
 * @property string $status processing / completed / failed
 * @property int $rows_total
 * @property int $rows_imported
 * @property int $rows_duplicate
 * @property int $rows_failed
 * @property int $credit_total
 * @property int $debit_total
 * @property list<array{row: int, message: string}>|null $errors
 * @property string|null $imported_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $completed_at
 * @property-read Branch $branch
 * @property-read PaymentAccount $paymentAccount
 * @property-read StoredFile|null $file
 * @property-read User|null $importer
 */
class StatementImport extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'rows_total' => 'integer',
            'rows_imported' => 'integer',
            'rows_duplicate' => 'integer',
            'rows_failed' => 'integer',
            'credit_total' => 'integer',
            'debit_total' => 'integer',
            'errors' => 'array',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
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
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /**
     * @return HasMany<StatementEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(StatementEntry::class, 'import_id');
    }
}
