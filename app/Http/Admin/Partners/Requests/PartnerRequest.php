<?php

namespace App\Http\Admin\Partners\Requests;

use App\Domain\Commission\RatePercent;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Http\Shared\Rules\IpAddressList;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The partner wizard (create and edit). Amounts arrive in rupees and are
 * stored in paise; a limit of -1 means "no limit". Sections the admin has no
 * permission for (commission, branch mapping, IPs) are ignored.
 */
class PartnerRequest extends FormRequest
{
    private const NO_LIMIT = '-1';

    public function authorize(): bool
    {
        $partner = $this->partner();

        return $partner === null
            ? $this->actor()->can('partners.create')
            : $this->actor()->can('partners.update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $url = ['nullable', 'string', 'max:255', app()->isLocal() ? 'url:http,https' : 'url:https'];
        $requiredOnCreate = [$this->partner() === null ? 'required' : 'nullable', ...array_slice($url, 1)];
        $amount = ['required', 'string', 'regex:/^('.self::NO_LIMIT.'|\d{1,11}(\.\d{1,2})?)$/'];
        $rate = ['nullable', 'string', 'regex:'.RatePercent::PATTERN, 'numeric', 'max:100'];

        return [
            // 1. Basic information
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('partners', 'code')->ignore($this->partner()?->id)],
            'email' => ['required', 'email', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'website_url' => $url,

            // 2. API & security
            'return_url' => $requiredOnCreate,
            'callback_url' => $requiredOnCreate,
            'payin_webhook_url' => $requiredOnCreate,
            'payout_webhook_url' => $url,
            'ip_addresses' => ['nullable', 'string', new IpAddressList],

            // 3. Payment configuration
            'is_payin_enabled' => ['boolean'],
            'manual_payment_type' => ['nullable', Rule::in(['bank_details', 'intent', 'dynamic_qr'])],
            'allow_qr' => ['boolean'],
            'allow_upi' => ['boolean'],
            'allow_bank_transfer' => ['boolean'],
            'is_h2h_enabled' => ['boolean'],
            'session_ttl_minutes' => ['required', 'integer', 'between:1,1440'],
            'deposit_min_amount' => $amount,
            'deposit_max_amount' => $amount,
            'deposit_daily_limit' => $amount,

            // 4. Commission
            'deposit_rate' => $rate,
            'withdrawal_rate' => $rate,
            'payout_limit_type' => ['required', Rule::in(['daily_reset', 'topup'])],

            // 5. Withdrawal
            'is_payout_enabled' => ['boolean'],
            'withdraw_url' => $url,
            'payout_group' => ['nullable', 'string', 'max:100'],
            'withdrawal_min_amount' => $amount,
            'withdrawal_max_amount' => $amount,
            'withdrawal_daily_limit' => $amount,
            'is_auto_withdrawal' => ['boolean'],
            'is_partial_withdrawal' => ['boolean'],

            // 6. Branch mapping
            'branch_ids' => ['array'],
            'branch_ids.*' => ['uuid', Rule::exists('branches', 'id')],

            'activate' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (['deposit', 'withdrawal'] as $kind) {
                $min = $this->paise("{$kind}_min_amount");
                $max = $this->paise("{$kind}_max_amount");

                if ($min !== null && $max !== null && $min > $max) {
                    $validator->errors()->add("{$kind}_max_amount", __('The maximum must be at least the minimum.'));
                }

                foreach (["{$kind}_min_amount", "{$kind}_max_amount", "{$kind}_daily_limit"] as $field) {
                    if ($this->paise($field) === 0) {
                        $validator->errors()->add($field, __('Enter -1 for unlimited, or an amount above zero.'));
                    }
                }
            }
        });
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }

    public function partner(): ?Partner
    {
        $partner = $this->route('partner');

        return $partner instanceof Partner ? $partner : null;
    }

    /**
     * Partner columns, amounts converted to paise.
     *
     * @return array<string, mixed>
     */
    public function partnerAttributes(): array
    {
        $data = $this->safe()->only([
            'name', 'description', 'return_url', 'callback_url', 'payin_webhook_url', 'payout_webhook_url',
            'manual_payment_type', 'session_ttl_minutes', 'payout_limit_type', 'withdraw_url', 'payout_group',
        ]);

        foreach (['is_payin_enabled', 'allow_qr', 'allow_upi', 'allow_bank_transfer', 'is_h2h_enabled', 'is_payout_enabled', 'is_auto_withdrawal', 'is_partial_withdrawal'] as $flag) {
            $data[$flag] = $this->boolean($flag);
        }

        foreach (['deposit_min_amount', 'deposit_max_amount', 'deposit_daily_limit', 'withdrawal_min_amount', 'withdrawal_max_amount', 'withdrawal_daily_limit'] as $field) {
            $data[$field] = $this->paise($field);
        }

        return [
            ...$data,
            'code' => $this->string('code')->value(),
            'email' => $this->string('email')->lower()->value(),
            'website_url' => $this->filled('website_url') ? $this->string('website_url')->value() : null,
        ];
    }

    /**
     * @return list<string>|null
     */
    public function ipAddresses(): ?array
    {
        return $this->actor()->can('ip_rules.create') ? IpAddressList::split($this->input('ip_addresses')) : null;
    }

    /**
     * @return array<string, string>|null
     */
    public function rates(): ?array
    {
        if (! $this->actor()->can('commissions.update')) {
            return null;
        }

        return array_filter([
            'deposit' => $this->input('deposit_rate'),
            'withdrawal' => $this->input('withdrawal_rate'),
        ], fn ($rate) => is_string($rate) && $rate !== '');
    }

    /**
     * @return list<string>|null
     */
    public function branchIds(): ?array
    {
        return $this->actor()->can('mappings.update') ? array_values((array) $this->input('branch_ids', [])) : null;
    }

    private function paise(string $field): ?int
    {
        $value = $this->input($field);

        return is_string($value) && preg_match('/^\d{1,11}(\.\d{1,2})?$/', trim($value)) === 1 ? Money::toPaise($value) : null;
    }
}
