<?php

namespace App\Http\Admin\Mappings;

use App\Domain\Commission\RatePercent;
use App\Domain\Core\Identity\Models\User;
use App\Http\Shared\Concerns\ReadsMoney;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit one pair. Rate overrides: a value sets the pair rate, an empty
 * string removes the override (the partner's / branch's own rate applies).
 */
class MappingRequest extends FormRequest
{
    use ReadsMoney;

    private const SIDES = ['partner', 'branch'];

    private const DIRECTIONS = ['deposit', 'withdrawal'];

    public function authorize(): bool
    {
        return $this->actor()->can('mappings.update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'is_deposit_enabled' => ['boolean'],
            'is_withdrawal_enabled' => ['boolean'],
            'deposit_daily_limit' => $this->amountRules(),
            'withdrawal_daily_limit' => $this->amountRules(),
            'overrides' => ['nullable', 'array'],
        ];

        foreach (self::SIDES as $side) {
            foreach (self::DIRECTIONS as $direction) {
                $rules["overrides.{$side}.{$direction}"] = ['nullable', 'string', 'regex:'.RatePercent::PATTERN, 'numeric', 'max:100'];
            }
        }

        return $rules;
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function mappingAttributes(): array
    {
        return [
            'status' => $this->string('status')->value(),
            'is_deposit_enabled' => $this->boolean('is_deposit_enabled'),
            'is_withdrawal_enabled' => $this->boolean('is_withdrawal_enabled'),
            'deposit_daily_limit' => $this->paise('deposit_daily_limit'),
            'withdrawal_daily_limit' => $this->paise('withdrawal_daily_limit'),
        ];
    }

    /**
     * side => direction => rate ('' = remove override); null without permission.
     *
     * @return array<string, array<string, string>>|null
     */
    public function overrides(): ?array
    {
        if (! $this->actor()->can('commissions.update') || ! is_array($this->input('overrides'))) {
            return null;
        }

        $overrides = [];

        foreach (self::SIDES as $side) {
            foreach (self::DIRECTIONS as $direction) {
                $value = $this->input("overrides.{$side}.{$direction}");
                $overrides[$side][$direction] = is_string($value) ? trim($value) : '';
            }
        }

        return $overrides;
    }
}
