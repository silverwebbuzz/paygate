<?php

namespace App\Domain\Branch\Models;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Carbon\CarbonInterface;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An operational payment entity that provides bank / UPI accounts, receives
 * deposits and pays withdrawals. Amounts in paise; null limits = no limit.
 *
 * Deposit limit types (D-3): `daily_reset` caps deposits per day (restarts
 * 00:00 IST); `topup` uses deposit_topup_balance, a running allowance reduced
 * by each successful deposit and raised only by Admin top-ups.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property OrganisationStatus $status
 * @property bool $is_deposit_enabled
 * @property bool $is_withdrawal_enabled
 * @property string $deposit_limit_type
 * @property int $deposit_topup_balance
 * @property int|null $deposit_min_amount
 * @property int|null $deposit_max_amount
 * @property int|null $deposit_daily_limit
 * @property int|null $withdrawal_min_amount
 * @property int|null $withdrawal_max_amount
 * @property int|null $withdrawal_daily_limit
 * @property CarbonInterface|null $verified_at
 * @property string|null $verified_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $active_accounts_count withCount alias (lists)
 * @property-read int|null $partners_count withCount alias (lists)
 */
#[UseFactory(BranchFactory::class)]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OrganisationStatus::class,
            'is_deposit_enabled' => 'boolean',
            'is_withdrawal_enabled' => 'boolean',
            'deposit_topup_balance' => 'integer',
            'deposit_min_amount' => 'integer',
            'deposit_max_amount' => 'integer',
            'deposit_daily_limit' => 'integer',
            'withdrawal_min_amount' => 'integer',
            'withdrawal_max_amount' => 'integer',
            'withdrawal_daily_limit' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function usesTopup(): bool
    {
        return $this->deposit_limit_type === 'topup';
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<PaymentAccount, $this>
     */
    public function paymentAccounts(): HasMany
    {
        return $this->hasMany(PaymentAccount::class);
    }

    /**
     * @return HasMany<PartnerBranchMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(PartnerBranchMapping::class);
    }

    /**
     * @return BelongsToMany<Partner, $this>
     */
    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(Partner::class, 'partner_branch_mappings')
            ->withPivot(['id', 'status', 'is_deposit_enabled', 'is_withdrawal_enabled'])
            ->wherePivot('status', 'active');
    }

    /**
     * @return HasMany<BranchLimitTopup, $this>
     */
    public function topups(): HasMany
    {
        return $this->hasMany(BranchLimitTopup::class);
    }
}
