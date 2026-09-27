<?php

namespace App\Domain\Network\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\Partner\Models\Partner;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A branch allowed to collect deposits and pay withdrawals for a partner.
 * Mappings are deactivated, never deleted (transactions and ledger accounts
 * refer to the pair).
 *
 * @property string $id
 * @property string $partner_id
 * @property string $branch_id
 * @property string $status active / inactive
 * @property bool $is_deposit_enabled
 * @property bool $is_withdrawal_enabled
 * @property int $priority
 * @property int|null $deposit_daily_limit
 * @property int|null $withdrawal_daily_limit
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PartnerBranchMapping extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_deposit_enabled' => 'boolean',
            'is_withdrawal_enabled' => 'boolean',
            'priority' => 'integer',
            'deposit_daily_limit' => 'integer',
            'withdrawal_daily_limit' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'last_payout_assigned_at' => 'datetime',
        ];
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
}
