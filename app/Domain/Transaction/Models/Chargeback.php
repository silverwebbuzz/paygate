<?php

namespace App\Domain\Transaction\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Platform\Models\StoredFile;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's bank took back an approved deposit (decided 2026-10-21).
 * The branch raises it with the bank's notice and the transaction's UTR;
 * Admin approves (booked that day: partner −amount, branch +amount) or
 * rejects. The partner always bears it. The deposit's status stays
 * `success`; `transactions.chargeback_status` mirrors this row's status.
 *
 * @property string $id
 * @property string $reference e.g. CB261021K7QX4MZD
 * @property string $transaction_id
 * @property string $partner_id
 * @property string $branch_id
 * @property int $amount
 * @property string $utr
 * @property string|null $reason
 * @property string|null $proof_file_id
 * @property string $status requested / approved / rejected
 * @property string $requested_by
 * @property string|null $decided_by
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_note
 * @property string|null $journal_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Transaction $transaction
 * @property-read Partner $partner
 * @property-read Branch $branch
 * @property-read StoredFile|null $proof
 * @property-read User $requester
 * @property-read User|null $decider
 */
class Chargeback extends Model
{
    use HasUuids;

    public const STATUSES = ['requested', 'approved', 'rejected'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'decided_at' => 'datetime'];
    }

    public static function newReference(): string
    {
        return 'CB'.substr(Transaction::newPayinReference(), 2);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
