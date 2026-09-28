<?php

namespace App\Domain\Settlement\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Transaction\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual change to one pair position (Requirements §6.9), made by one
 * admin and approved by another before it is booked (decided 2026-09-28,
 * G-44 maker–checker). `amount` is signed from the party's point of view:
 * positive = the platform owes the party more.
 *
 * - topup: the partner paid the platform outside PayGate to fund payouts
 *   at that branch (G-16): partner position +, settlement clearing −.
 * - correction / goodwill: the position ±, platform adjustments ∓.
 *
 * @property string $id
 * @property string $reference e.g. AJ260928K7QX4MZD
 * @property string $type topup / correction / goodwill (chargeback / refund: Phase 12)
 * @property string $partner_id
 * @property string $branch_id
 * @property string $side partner / branch
 * @property int $amount
 * @property string|null $transaction_id
 * @property string|null $case_id
 * @property string $reason
 * @property string $status pending / approved / rejected
 * @property string $requested_by
 * @property string|null $approved_by who approved or rejected
 * @property CarbonInterface|null $approved_at
 * @property string|null $decision_note the checker's note
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Partner $partner
 * @property-read Branch $branch
 * @property-read User $requester
 * @property-read User|null $approver
 * @property-read ReconciliationCase|null $case
 */
class Adjustment extends Model
{
    use HasUuids;

    public const TYPES = ['topup', 'correction', 'goodwill'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'approved_at' => 'datetime'];
    }

    public static function newReference(): string
    {
        return 'AJ'.substr(Transaction::newPayinReference(), 2);
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
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<ReconciliationCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(ReconciliationCase::class, 'case_id');
    }
}
