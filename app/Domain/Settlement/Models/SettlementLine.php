<?php

namespace App\Domain\Settlement\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\Partner\Models\Partner;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One partner↔branch pair inside a party's settlement: the pair position's
 * movement for the period. Payments are recorded per line (decided
 * 2026-09-28, G-11); whatever isn't paid stays in the position.
 *
 * @property string $id
 * @property string $settlement_id
 * @property string $ledger_account_id
 * @property int $opening_balance
 * @property int $gross_payin
 * @property int $gross_payout
 * @property int $partner_commission
 * @property int $branch_commission
 * @property int $platform_margin
 * @property int $adjustments_total
 * @property int $settlements_total
 * @property int $closing_balance
 * @property int $net_amount
 * @property int $settled_amount
 * @property-read Settlement $settlement
 */
class SettlementLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_fill_keys(['opening_balance', 'gross_payin', 'gross_payout', 'partner_commission', 'branch_commission', 'platform_margin', 'adjustments_total', 'settlements_total', 'closing_balance', 'net_amount', 'settled_amount'], 'integer');
    }

    public function direction(): string
    {
        return Settlement::directionOf($this->closing_balance);
    }

    public function remaining(): int
    {
        return $this->net_amount - $this->settled_amount;
    }

    /**
     * @return BelongsTo<Settlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
