<?php

namespace App\Domain\Settlement\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Transaction\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one party (a partner or a branch) and the platform owe each other
 * for a period (Requirements F14): opening position + movements = closing
 * position, split into one line per partner↔branch pair. Money moves
 * outside PayGate; Admin records it (SettlementPayment).
 *
 * `closing_balance` is signed (positive = the platform owes the party);
 * `net_amount` is its size and `direction` who pays whom.
 *
 * @property string $id
 * @property string $reference e.g. ST260928K7QX4MZD
 * @property string $party_type partner / branch
 * @property string|null $partner_id
 * @property string|null $branch_id
 * @property string $run_type daily / on_demand
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
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
 * @property string $direction party_to_platform / platform_to_party / none
 * @property int $settled_amount
 * @property string $status calculated / partially_settled / settled
 * @property string|null $calculated_by
 * @property CarbonInterface $calculated_at
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Partner|null $partner
 * @property-read Branch|null $branch
 * @property-read User|null $calculator
 * @property-read Collection<int, SettlementLine> $lines
 * @property-read Collection<int, SettlementPayment> $payments
 */
class Settlement extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'calculated_at' => 'datetime',
            ...array_fill_keys(['opening_balance', 'gross_payin', 'gross_payout', 'partner_commission', 'branch_commission', 'platform_margin', 'adjustments_total', 'settlements_total', 'closing_balance', 'net_amount', 'settled_amount'], 'integer'),
        ];
    }

    public static function newReference(): string
    {
        return 'ST'.substr(Transaction::newPayinReference(), 2);
    }

    public static function directionOf(int $closing): string
    {
        return match (true) {
            $closing > 0 => 'platform_to_party',
            $closing < 0 => 'party_to_platform',
            default => 'none',
        };
    }

    public function partyId(): string
    {
        return (string) ($this->party_type === 'partner' ? $this->partner_id : $this->branch_id);
    }

    /**
     * Each party's newest settlement: the one that carries the unpaid
     * remainder of the older ones and takes payments.
     *
     * @param  Builder<Settlement>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('settlements.status', '!=', 'cancelled')->whereNotExists(fn ($newer) => $newer
            ->selectRaw('1')
            ->from('settlements as newer')
            ->whereColumn('newer.party_type', 'settlements.party_type')
            ->whereRaw('newer.partner_id IS NOT DISTINCT FROM settlements.partner_id')
            ->whereRaw('newer.branch_id IS NOT DISTINCT FROM settlements.branch_id')
            ->whereColumn('newer.period_end', '>', 'settlements.period_end')
            ->where('newer.status', '!=', 'cancelled'));
    }

    public function remaining(): int
    {
        return $this->net_amount - $this->settled_amount;
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
    public function calculator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    /**
     * @return HasMany<SettlementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SettlementLine::class);
    }

    /**
     * @return HasMany<SettlementPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SettlementPayment::class)->orderBy('created_at');
    }
}
