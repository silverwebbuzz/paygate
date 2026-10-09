<?php

namespace App\Domain\Core\Audit\Models;

use App\Domain\Core\Identity\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

/**
 * Append-only record of who changed what (enforced by a database trigger).
 *
 * @property string $id
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * Record an action performed by the current user (or the system when there is none).
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function record(string $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null): self
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $actor ??= $request?->user();

        return self::create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => Context::get('request_id'),
        ]);
    }

    public function summary(): string
    {
        $new = $this->new_values ?? [];
        $old = $this->old_values ?? [];

        return match ($this->action) {
            'partner.direction_changed' => match ($new['direction'] ?? null) {
                'payin' => 'Pay-in '.(($new['enabled'] ?? false) ? 'enabled' : 'disabled'),
                'payout' => 'Pay-out '.(($new['enabled'] ?? false) ? 'enabled' : 'disabled'),
                default => $this->action,
            },
            'api_key.issued' => 'API key generated',
            'api_key.rotated' => 'API secret rotated',
            'api_key.revoked' => isset($old['key_id']) ? 'API key revoked · '.$old['key_id'] : 'API key revoked',
            'partner.status_changed' => isset($new['status']) ? 'Partner status set to '.$new['status'] : $this->action,
            'partner.created' => 'Partner added',
            'partner.updated' => 'Partner updated',
            'branch.created' => 'Branch added',
            'branch.updated' => 'Branch updated',
            'branch.status_changed' => isset($new['status']) ? 'Branch status set to '.$new['status'] : $this->action,
            'branch.limit_topped_up' => 'Deposit allowance changed',
            'payment_account.created' => 'Bank / UPI account added',
            'payment_account.updated' => 'Bank / UPI account edited',
            'payment_account.verified' => 'Bank / UPI account verified',
            'payment_account.rejected' => 'Bank / UPI account marked unverified',
            'payment_account.verification_changed' => 'Bank / UPI account marked pending',
            'payment_account.status_changed' => isset($new['status']) ? 'Account switched '.str_replace('_', ' ', (string) $new['status']) : 'Account status changed',
            'commission_rate.set' => 'Commission rate set',
            'commission_rate.cleared' => 'Commission rate removed',
            'mapping.updated' => 'Branch mapping updated',
            'mapping.activated' => 'Branch mapping turned on',
            'partner.branches_updated' => 'Mapped branches updated',
            'branch.partners_updated' => 'Mapped partners updated',
            default => ucfirst(str_replace(['.', '_'], ' ', $this->action)),
        };
    }

    /**
     * @param  list<string>  $skip
     * @return list<array{field: string, before: string|null, now: string}>
     */
    public function changePairs(array $skip = []): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $pairs = [];

        foreach ($new as $key => $value) {
            if (in_array($key, $skip, true) || $this->hiddenChange($key)) {
                continue;
            }

            $after = $this->formatChange($key, $value);
            $before = array_key_exists($key, $old) ? $this->formatChange($key, $old[$key]) : null;

            if ($before === $after || ($before === null && $after === '—')) {
                continue;
            }

            $pairs[] = [
                'field' => self::CHANGE_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'before' => $before,
                'now' => $after,
            ];

            if (count($pairs) === 40) {
                break;
            }
        }

        return $pairs;
    }

    /**
     * @param  list<string>  $skip
     * @return list<string>
     */
    public function changeLines(array $skip = []): array
    {
        return array_map(function (array $pair) {
            return $pair['before'] === null
                ? "{$pair['field']}: {$pair['now']}"
                : "Before {$pair['field']}: {$pair['before']}. Now: {$pair['now']}";
        }, $this->changePairs($skip));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    private const CHANGE_LABELS = [
        'deposit_min_amount' => 'Deposit minimum',
        'deposit_max_amount' => 'Deposit maximum',
        'deposit_daily_limit' => 'Daily deposit limit',
        'withdrawal_min_amount' => 'Withdrawal minimum',
        'withdrawal_max_amount' => 'Withdrawal maximum',
        'withdrawal_daily_limit' => 'Daily withdrawal limit',
        'deposit_topup_balance' => 'Deposit allowance',
        'min_amount' => 'Minimum per payment',
        'max_amount' => 'Maximum per payment',
        'daily_amount_limit' => 'Daily amount limit',
        'daily_count_limit' => 'Daily count limit',
        'max_open_sessions' => 'Open payments at once',
        'is_payin_enabled' => 'Pay-in',
        'is_payout_enabled' => 'Pay-out',
        'is_deposit_enabled' => 'Deposits',
        'is_withdrawal_enabled' => 'Withdrawals',
        'is_bank_enabled' => 'Bank transfer',
        'is_upi_enabled' => 'UPI',
        'is_qr_enabled' => 'QR',
        'account_holder_name' => 'Account holder',
        'bank_name' => 'Bank',
        'ifsc' => 'IFSC',
        'account_number' => 'Account number',
        'account_number_last4' => 'Account number',
        'upi_id' => 'UPI ID',
        'upi_id_last4' => 'UPI ID',
        'rate_percent' => 'Commission rate',
        'rejected_reason' => 'Rejection reason',
    ];

    private const MONEY_FIELDS = [
        'deposit_min_amount', 'deposit_max_amount', 'deposit_daily_limit',
        'withdrawal_min_amount', 'withdrawal_max_amount', 'withdrawal_daily_limit',
        'deposit_topup_balance', 'min_amount', 'max_amount', 'daily_amount_limit', 'amount', 'balance_after',
    ];

    private function hiddenChange(string $key): bool
    {
        return in_array($key, ['reason', 'purpose', 'effective_from', 'created_by', 'verified_by', 'verified_at', 'negative_margin_pairs'], true)
            || str_ends_with($key, '_id')
            || str_contains($key, 'encrypted')
            || str_contains($key, 'hash')
            || str_contains($key, 'password')
            || str_contains($key, 'secret')
            || str_contains($key, 'token');
    }

    private function formatChange(string $key, mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if ($value === null) {
            return in_array($key, self::MONEY_FIELDS, true) || str_ends_with($key, '_limit') ? 'Unlimited' : '—';
        }

        if (is_bool($value)) {
            return $value ? 'On' : 'Off';
        }

        if (in_array($key, self::MONEY_FIELDS, true) && is_numeric($value)) {
            $rupees = Money::toRupees((int) $value);

            return '₹'.number_format((float) $rupees, 2);
        }

        if ($key === 'rate_percent') {
            return rtrim(rtrim((string) $value, '0'), '.').'%';
        }

        if (is_array($value)) {
            $flat = array_is_list($value) ? implode(', ', array_map(strval(...), array_filter($value, fn ($item) => is_scalar($item)))) : '';

            return $flat !== '' ? $flat : 'Updated';
        }

        if (in_array($key, ['account_number', 'upi_id', 'ifsc'], true)) {
            return (string) $value;
        }

        $text = str_replace('_', ' ', (string) $value);

        return strlen($text) > 120 ? substr($text, 0, 117).'…' : $text;
    }
}
