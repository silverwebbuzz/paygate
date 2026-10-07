<?php

namespace App\Http\Shared\Accounts;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Http\Shared\Concerns\ReadsMoney;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add or edit a bank / UPI account (branch portal and Admin). On edit, an
 * empty account number or UPI ID means "unchanged" (they are never sent
 * back to the browser).
 */
class PaymentAccountRequest extends FormRequest
{
    use ReadsMoney;

    public function authorize(): bool
    {
        return $this->actor()->can($this->account() === null ? 'accounts.create' : 'accounts.update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isAdmin = $this->actor()->isType(UserType::Admin);

        return [
            'branch_id' => $isAdmin && $this->account() === null ? ['required', 'uuid', Rule::exists('branches', 'id')] : ['prohibited'],
            'label' => ['required', 'string', 'max:255'],
            'account_holder_name' => ['required', 'string', 'max:255'],
            'is_bank_enabled' => ['boolean'],
            'bank_name' => ['nullable', 'required_if_accepted:is_bank_enabled', 'string', 'max:255'],
            'ifsc' => ['nullable', 'required_if_accepted:is_bank_enabled', 'string', 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
            'account_number' => ['nullable', 'string', 'regex:/^[0-9][0-9 -]{7,24}$/'],
            'is_upi_enabled' => ['boolean'],
            'upi_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]{2,256}@[A-Za-z][A-Za-z0-9.-]{1,63}$/'],
            'upi_display_name' => ['nullable', 'string', 'max:255'],
            'is_qr_enabled' => ['boolean'],
            'min_amount' => $this->limitRules(),
            'max_amount' => $this->limitRules(),
            'daily_amount_limit' => $this->limitRules(),
            'daily_count_limit' => ['required', 'integer', 'between:-1,100000', 'not_in:0'],
            'max_open_sessions' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'ifsc.regex' => __('An IFSC has 11 characters, like HDFC0001203.'),
            'account_number.regex' => __('Digits only, 8 to 25 of them.'),
            'upi_id.regex' => __('A UPI ID looks like name@bank.'),
            'daily_count_limit.not_in' => __('Enter -1 for unlimited, or a number above zero.'),
            'daily_count_limit.between' => __('Enter -1 for unlimited, or a number above zero.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->checkRanges(
                $validator,
                [''],
                ['min_amount', 'max_amount', 'daily_amount_limit'],
                minusOneIsUnlimited: true,
            );

            // A new account needs the numbers for each enabled method.
            if ($this->account() === null) {
                if ($this->boolean('is_bank_enabled') && ! $this->filled('account_number')) {
                    $validator->errors()->add('account_number', __('Enter the account number.'));
                }

                if ($this->boolean('is_upi_enabled') && ! $this->filled('upi_id')) {
                    $validator->errors()->add('upi_id', __('Enter the UPI ID.'));
                }
            }
        });
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }

    public function account(): ?PaymentAccount
    {
        $account = $this->route('account');

        return $account instanceof PaymentAccount ? $account : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function accountData(): array
    {
        $bank = $this->boolean('is_bank_enabled');

        return [
            'label' => $this->string('label')->value(),
            'account_holder_name' => $this->string('account_holder_name')->value(),
            'is_bank_enabled' => $bank,
            'bank_name' => $bank ? $this->input('bank_name') : $this->account()?->bank_name,
            'ifsc' => $bank ? $this->input('ifsc') : $this->account()?->ifsc,
            'account_number' => $this->input('account_number'),
            'is_upi_enabled' => $this->boolean('is_upi_enabled'),
            'upi_id' => $this->input('upi_id'),
            'upi_display_name' => $this->input('upi_display_name'),
            'is_qr_enabled' => $this->boolean('is_qr_enabled'),
            'min_amount' => $this->paise('min_amount'),
            'max_amount' => $this->paise('max_amount'),
            'daily_amount_limit' => $this->paise('daily_amount_limit'),
            'daily_count_limit' => (int) $this->input('daily_count_limit') === -1
                ? null
                : $this->integer('daily_count_limit'),
            'max_open_sessions' => $this->integer('max_open_sessions'),
        ];
    }
}
