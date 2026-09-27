<?php

namespace App\Domain\Payout\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a payout goes: a bank account or a UPI ID, as the partner sent it.
 * Numbers are encrypted; lists show the last 4 digits only.
 *
 * @property string $transaction_id
 * @property string $type bank / upi
 * @property string $account_holder_name
 * @property string|null $account_number_encrypted plain value (encrypted cast)
 * @property string|null $account_number_last4
 * @property string|null $ifsc
 * @property string|null $bank_name
 * @property string|null $upi_id_encrypted plain value (encrypted cast)
 * @property string|null $email
 * @property string|null $phone
 */
class PayoutBeneficiary extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'transaction_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['account_number_encrypted', 'upi_id_encrypted'];

    protected function casts(): array
    {
        return [
            'account_number_encrypted' => 'encrypted',
            'upi_id_encrypted' => 'encrypted',
        ];
    }

    public function masked(): string
    {
        return $this->type === 'upi'
            ? '••••'.substr((string) $this->upi_id_encrypted, max(0, (int) strpos((string) $this->upi_id_encrypted, '@') - 4))
            : ($this->bank_name ?? 'Bank').' · XXXX '.$this->account_number_last4.' · '.$this->ifsc;
    }
}
