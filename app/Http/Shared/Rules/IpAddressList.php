<?php

namespace App\Http\Shared\Rules;

use App\Domain\Partner\Actions\SyncIpRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * A list of IP addresses or ranges, one per line or comma separated
 * ("52.66.45.184, 10.0.0.0/24"). Ranges wider than /8 (IPv4) are refused
 * so nobody whitelists the whole internet by accident.
 */
class IpAddressList implements ValidationRule
{
    public const MAX = 50;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $addresses = self::split(is_string($value) ? $value : '');

        if (count($addresses) > self::MAX) {
            $fail(__('At most :max addresses.', ['max' => self::MAX]));

            return;
        }

        foreach ($addresses as $address) {
            try {
                SyncIpRules::toCidr($address);
            } catch (InvalidArgumentException) {
                $fail(__('“:address” is not a valid IP address or range.', ['address' => $address]));

                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function split(?string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $value) ?: [])));
    }
}
