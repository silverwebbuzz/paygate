<?php

namespace App\Domain\Transaction\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Customer\Models\PartnerCustomer;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\PaymentSession\Models\PaymentSession;
use App\Domain\Payout\Models\PayoutBeneficiary;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Transaction\Enums\PayinStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A pay-in or payout (one table, Database.md D-1). Amounts in paise.
 * Status values depend on the direction; use payinStatus() for pay-ins.
 *
 * @property string $id
 * @property string $reference our id, shown to partners and customers
 * @property string $direction payin / payout
 * @property string $origin api / admin
 * @property string $partner_id
 * @property string $partner_transaction_id the partner's order id
 * @property string|null $request_hash
 * @property string|null $partner_customer_id
 * @property string|null $branch_id
 * @property string|null $payment_account_id
 * @property string|null $method upi / qr / upi_intent / bank_transfer
 * @property int $amount
 * @property int|null $received_amount
 * @property string $currency
 * @property string $status
 * @property string|null $status_reason_code
 * @property string|null $status_note
 * @property string|null $customer_utr
 * @property string|null $customer_utr_normalized
 * @property string|null $bank_utr
 * @property string|null $bank_utr_normalized
 * @property string|null $partner_rate_percent
 * @property string|null $branch_rate_percent
 * @property int|null $partner_commission
 * @property int|null $branch_commission
 * @property int|null $platform_margin
 * @property string|null $decided_by
 * @property string|null $return_url
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $decided_at
 * @property CarbonInterface|null $succeeded_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Partner $partner
 * @property-read PaymentAccount|null $paymentAccount
 * @property-read PaymentSession|null $session
 * @property-read PayoutBeneficiary|null $beneficiary
 * @property-read Branch|null $branch
 * @property-read StatementEntry|null $statementEntry
 * @property-read User|null $decider
 */
class Transaction extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'received_amount' => 'integer',
            'partner_commission' => 'integer',
            'branch_commission' => 'integer',
            'platform_margin' => 'integer',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'succeeded_at' => 'datetime',
        ];
    }

    /**
     * A new pay-in reference: "PI" + India date + 8 random characters from an
     * alphabet without look-alikes (Req G-42), e.g. PI260927K7QX4MZD.
     */
    public static function newPayinReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $random = '';

        for ($i = 0; $i < 8; $i++) {
            $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'PI'.now(config('app.business_timezone'))->format('ymd').$random;
    }

    public function payinStatus(): PayinStatus
    {
        return PayinStatus::from($this->status);
    }

    public function isPayin(): bool
    {
        return $this->direction === 'payin';
    }

    /**
     * UTRs are compared without spaces, dashes or case.
     */
    public static function normaliseUtr(string $utr): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $utr) ?? '');
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
     * @return BelongsTo<PaymentAccount, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    /**
     * @return BelongsTo<PartnerCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(PartnerCustomer::class, 'partner_customer_id');
    }

    /**
     * Where a payout goes.
     *
     * @return HasOne<PayoutBeneficiary, $this>
     */
    public function beneficiary(): HasOne
    {
        return $this->hasOne(PayoutBeneficiary::class);
    }

    /**
     * A new payout reference, e.g. PO260927K7QX4MZD (see newPayinReference).
     */
    public static function newPayoutReference(): string
    {
        return 'PO'.substr(self::newPayinReference(), 2);
    }

    /**
     * @return HasOne<PaymentSession, $this>
     */
    public function session(): HasOne
    {
        return $this->hasOne(PaymentSession::class);
    }

    /**
     * Who approved / declined (pay-ins) or paid / failed (payouts) it.
     *
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * The bank statement line this transaction is linked to (reconciliation).
     *
     * @return HasOne<StatementEntry, $this>
     */
    public function statementEntry(): HasOne
    {
        return $this->hasOne(StatementEntry::class);
    }

    /**
     * @return HasMany<TransactionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TransactionEvent::class)->orderBy('created_at');
    }
}
