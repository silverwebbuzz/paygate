<?php

namespace App\Http\Shared\Requests;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Http\Shared\Concerns\ReadsMoney;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Deposit and withdrawal limits only (partner list or branch list). Amounts
 * arrive in rupees and are stored in paise; -1 means no limit.
 */
class UpdateLimitsRequest extends FormRequest
{
    use ReadsMoney;

    /** @var list<string> */
    public const FIELDS = [
        'deposit_min_amount',
        'deposit_max_amount',
        'deposit_daily_limit',
        'withdrawal_min_amount',
        'withdrawal_max_amount',
        'withdrawal_daily_limit',
    ];

    public function authorize(): bool
    {
        $actor = $this->user();

        if (! $actor instanceof User) {
            return false;
        }

        if ($this->route('partner') instanceof Partner) {
            return $actor->can('partners.update');
        }

        if ($this->route('branch') instanceof Branch) {
            return $actor->can('branches.update');
        }

        return false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_fill_keys(self::FIELDS, $this->limitRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->checkRanges($validator, ['deposit', 'withdrawal'], minusOneIsUnlimited: true));
    }

    /**
     * Limit columns, amounts converted to paise (null = unlimited).
     *
     * @return array<string, int|null>
     */
    public function limitAttributes(): array
    {
        $data = [];

        foreach (self::FIELDS as $field) {
            $data[$field] = $this->paise($field);
        }

        return $data;
    }

    /**
     * Current limits in the form's rupee format (-1 = unlimited).
     *
     * @return array<string, string>
     */
    public static function formValues(Partner|Branch $model): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $amount = $model->{$field};
            $values[$field] = Money::toRupees(is_int($amount) ? $amount : null) ?? Money::NO_LIMIT;
        }

        return $values;
    }
}
