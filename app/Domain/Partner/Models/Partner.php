<?php

namespace App\Domain\Partner\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Enums\PartnerStatus;
use Carbon\CarbonInterface;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An external merchant website that integrates with the Gateway.
 * Amounts are in paise; null limits mean "no limit".
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $email
 * @property string|null $description
 * @property string $website_url
 * @property string|null $return_url
 * @property string|null $callback_url
 * @property string|null $payin_webhook_url
 * @property string|null $payout_webhook_url
 * @property string $api_version
 * @property PartnerStatus $status
 * @property bool $is_payin_enabled
 * @property bool $is_payout_enabled
 * @property bool $is_h2h_enabled
 * @property bool $allow_upi
 * @property bool $allow_qr
 * @property bool $allow_bank_transfer
 * @property string|null $manual_payment_type
 * @property string|null $withdraw_url
 * @property string|null $payout_group
 * @property bool $is_auto_withdrawal
 * @property bool $is_partial_withdrawal
 * @property string $payout_limit_type
 * @property int|null $deposit_min_amount
 * @property int|null $deposit_max_amount
 * @property int|null $deposit_daily_limit
 * @property int|null $withdrawal_min_amount
 * @property int|null $withdrawal_max_amount
 * @property int|null $withdrawal_daily_limit
 * @property int $session_ttl_minutes
 * @property CarbonInterface|null $verified_at
 * @property string|null $verified_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(PartnerFactory::class)]
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PartnerStatus::class,
            'is_payin_enabled' => 'boolean',
            'is_payout_enabled' => 'boolean',
            'is_h2h_enabled' => 'boolean',
            'allow_upi' => 'boolean',
            'allow_qr' => 'boolean',
            'allow_bank_transfer' => 'boolean',
            'is_auto_withdrawal' => 'boolean',
            'is_partial_withdrawal' => 'boolean',
            'deposit_min_amount' => 'integer',
            'deposit_max_amount' => 'integer',
            'deposit_daily_limit' => 'integer',
            'withdrawal_min_amount' => 'integer',
            'withdrawal_max_amount' => 'integer',
            'withdrawal_daily_limit' => 'integer',
            'session_ttl_minutes' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<PartnerApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(PartnerApiKey::class);
    }

    /**
     * The key partners sign requests with (at most one; see partner_api_keys_one_active).
     *
     * @return HasOne<PartnerApiKey, $this>
     */
    public function activeApiKey(): HasOne
    {
        return $this->hasOne(PartnerApiKey::class)->where('status', 'active');
    }

    /**
     * @return HasMany<PartnerIpRule, $this>
     */
    public function ipRules(): HasMany
    {
        return $this->hasMany(PartnerIpRule::class);
    }

    /**
     * @return HasMany<PartnerBranchMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(PartnerBranchMapping::class);
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'partner_branch_mappings')
            ->withPivot(['id', 'status', 'is_deposit_enabled', 'is_withdrawal_enabled', 'priority'])
            ->wherePivot('status', 'active');
    }
}
