<?php

namespace App\Http\Admin\Branches\Requests;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\RatePercent;
use App\Domain\Core\Identity\Models\User;
use App\Http\Shared\Concerns\ReadsMoney;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * The branch form (create and edit). Amounts in rupees → paise; sections the
 * admin has no permission for (commission, partner mapping) are ignored.
 */
class BranchRequest extends FormRequest
{
    use ReadsMoney;

    public function authorize(): bool
    {
        return $this->actor()->can($this->branch() === null ? 'branches.create' : 'branches.update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rate = ['nullable', 'string', 'regex:'.RatePercent::PATTERN, 'numeric', 'max:100'];
        $creating = $this->branch() === null;

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('branches', 'code')->ignore($this->branch()?->id)],
            'name' => ['required', 'string', 'max:255'],
            'is_deposit_enabled' => ['boolean'],
            'is_withdrawal_enabled' => ['boolean'],
            'deposit_limit_type' => ['required', Rule::in(['daily_reset', 'topup'])],
            'deposit_min_amount' => $this->limitRules(),
            'deposit_max_amount' => $this->limitRules(),
            'deposit_daily_limit' => $this->limitRules(),
            'withdrawal_min_amount' => $this->limitRules(),
            'withdrawal_max_amount' => $this->limitRules(),
            'withdrawal_daily_limit' => $this->limitRules(),
            'deposit_rate' => $rate,
            'withdrawal_rate' => $rate,
            'partner_ids' => ['array'],
            'partner_ids.*' => ['uuid', Rule::exists('partners', 'id')],
            // Optional branch admin, created (active, with this password) with the branch.
            'admin_name' => $creating ? ['nullable', 'required_with:admin_email', 'string', 'max:255'] : ['prohibited'],
            'admin_email' => $creating ? ['nullable', 'required_with:admin_name', 'email', 'max:255', Rule::unique('users', 'email')] : ['prohibited'],
            'admin_password' => $creating ? ['nullable', 'required_with:admin_email', 'string', Password::default(), 'confirmed'] : ['prohibited'],
            'activate' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->checkRanges($validator, ['deposit', 'withdrawal'], minusOneIsUnlimited: true));
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }

    public function branch(): ?Branch
    {
        $branch = $this->route('branch');

        return $branch instanceof Branch ? $branch : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function branchAttributes(): array
    {
        $data = [
            'code' => $this->string('code')->value(),
            'name' => $this->string('name')->value(),
            'is_deposit_enabled' => $this->boolean('is_deposit_enabled'),
            'is_withdrawal_enabled' => $this->boolean('is_withdrawal_enabled'),
            'deposit_limit_type' => $this->string('deposit_limit_type')->value(),
        ];

        foreach (['deposit_min_amount', 'deposit_max_amount', 'deposit_daily_limit', 'withdrawal_min_amount', 'withdrawal_max_amount', 'withdrawal_daily_limit'] as $field) {
            $data[$field] = $this->paise($field);
        }

        return $data;
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
    public function partnerIds(): ?array
    {
        return $this->actor()->can('mappings.update') ? array_values((array) $this->input('partner_ids', [])) : null;
    }

    /**
     * @return array{name: string, email: string, password: string}|null
     */
    public function admin(): ?array
    {
        return $this->filled('admin_email') && $this->actor()->can('users.create')
            ? [
                'name' => $this->string('admin_name')->value(),
                'email' => $this->string('admin_email')->lower()->value(),
                'password' => $this->string('admin_password')->value(),
            ]
            : null;
    }
}
