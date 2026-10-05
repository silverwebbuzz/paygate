<?php

namespace App\Http\Shared\Concerns;

use App\Support\Money;
use Illuminate\Validation\Validator;

/**
 * For form requests with rupee amount fields: validation rules and
 * conversion to paise (empty or -1 = null = no limit).
 */
trait ReadsMoney
{
    /**
     * @return list<string>
     */
    protected function amountRules(): array
    {
        return ['nullable', 'string', Money::RUPEES_RULE];
    }

    /**
     * @return list<string>
     */
    protected function limitRules(): array
    {
        return ['required', 'string', Money::LIMIT_RULE];
    }

    protected function paise(string $field): ?int
    {
        $value = $this->input($field);

        return is_string($value) && preg_match('/^\d{1,11}(\.\d{1,2})?$/', trim($value)) === 1 ? Money::toPaise($value) : null;
    }

    /**
     * Adds "max ≥ min" and "no zero limits" errors for the given prefixes
     * (e.g. `deposit` checks deposit_min_amount / deposit_max_amount / deposit_daily_limit).
     *
     * @param  list<string>  $prefixes
     * @param  list<string>  $fields
     */
    protected function checkRanges(Validator $validator, array $prefixes, array $fields = ['min_amount', 'max_amount', 'daily_limit'], bool $minusOneIsUnlimited = false): void
    {
        $zeroMessage = $minusOneIsUnlimited
            ? __('Enter -1 for unlimited, or an amount above zero.')
            : __('Leave empty for no limit, or enter an amount above zero.');

        foreach ($prefixes as $prefix) {
            $name = fn (string $field) => $prefix === '' ? $field : "{$prefix}_{$field}";
            $min = $this->paise($name('min_amount'));
            $max = $this->paise($name('max_amount'));

            if ($min !== null && $max !== null && $min > $max) {
                $validator->errors()->add($name('max_amount'), __('The maximum must be at least the minimum.'));
            }

            foreach ($fields as $field) {
                if ($this->paise($name($field)) === 0) {
                    $validator->errors()->add($name($field), $zeroMessage);
                }
            }
        }
    }
}
