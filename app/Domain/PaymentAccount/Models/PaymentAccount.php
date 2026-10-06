<?php

namespace App\Domain\PaymentAccount\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bank account and/or UPI ID of a branch, shown to customers who pay in.
 * Numbers are encrypted; the *_hash columns (BlindIndex) enforce uniqueness
 * and *_last4 is what lists show. Only active, verified accounts receive
 * customers (Requirements §7.5).
 *
 * @property string $id
 * @property string $branch_id
 * @property bool $is_bank_enabled
 * @property bool $is_upi_enabled
 * @property string $label
 * @property string|null $bank_name
 * @property string|null $ifsc
 * @property string $account_holder_name
 * @property string|null $account_number_encrypted plain value (encrypted cast)
 * @property string|null $account_number_hash
 * @property string|null $account_number_last4
 * @property string|null $upi_id_encrypted plain value (encrypted cast)
 * @property string|null $upi_id_hash
 * @property string|null $upi_id_last4
 * @property string|null $upi_display_name
 * @property string|null $upi_code
 * @property bool $is_qr_enabled
 * @property AccountStatus $status
 * @property CarbonInterface|null $verified_at
 * @property string|null $verified_by
 * @property string|null $rejected_reason
 * @property int|null $min_amount
 * @property int|null $max_amount
 * @property int|null $daily_amount_limit
 * @property int|null $daily_count_limit
 * @property int $max_open_sessions
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
class PaymentAccount extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['account_number_encrypted', 'upi_id_encrypted', 'account_number_hash', 'upi_id_hash'];

    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'account_number_encrypted' => 'encrypted',
            'upi_id_encrypted' => 'encrypted',
            'is_bank_enabled' => 'boolean',
            'is_upi_enabled' => 'boolean',
            'is_qr_enabled' => 'boolean',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'daily_amount_limit' => 'integer',
            'daily_count_limit' => 'integer',
            'max_open_sessions' => 'integer',
            'verified_at' => 'datetime',
            'last_allocated_at' => 'datetime',
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
     * "XXXX 5535" style display of the account number.
     */
    public function maskedAccountNumber(): ?string
    {
        return $this->account_number_last4 === null ? null : 'XXXX '.$this->account_number_last4;
    }

    /**
     * "••••4354@okbizaxis" style display of the UPI ID (the handle is not secret).
     */
    public function maskedUpiId(): ?string
    {
        if ($this->upi_id_last4 === null) {
            return null;
        }

        $handle = $this->is_upi_enabled && is_string($this->upi_id_encrypted) && str_contains($this->upi_id_encrypted, '@')
            ? '@'.explode('@', $this->upi_id_encrypted, 2)[1]
            : '';

        return '••••'.$this->upi_id_last4.$handle;
    }
}
