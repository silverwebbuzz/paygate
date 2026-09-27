<?php

namespace App\Domain\Commission;

use InvalidArgumentException;

/**
 * Commission rates are percentages with up to 4 decimals (numeric(7,4)),
 * handled as strings so no float rounding ever touches them.
 */
final class RatePercent
{
    public const PATTERN = '/^\d{1,3}(\.\d{1,4})?$/';

    /**
     * "2.5" → "2.5000".
     */
    public static function normalize(string $rate): string
    {
        return number_format(self::units($rate) / 10000, 4, '.', '');
    }

    /**
     * The rate in units of 0.0001 % ("2.5" → 25000), for exact comparison.
     */
    public static function units(string $rate): int
    {
        $rate = trim($rate);

        if (preg_match(self::PATTERN, $rate) !== 1) {
            throw new InvalidArgumentException("Invalid rate: {$rate}");
        }

        [$whole, $fraction] = array_pad(explode('.', $rate), 2, '');
        $units = (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');

        if ($units > 100 * 10000) {
            throw new InvalidArgumentException("Rate above 100%: {$rate}");
        }

        return $units;
    }

    /**
     * -15000 → "-1.5000" (for margins, which can be negative).
     */
    public static function fromUnits(int $units): string
    {
        return ($units < 0 ? '-' : '').number_format(abs($units) / 10000, 4, '.', '');
    }

    public static function compare(string $a, string $b): int
    {
        return self::units($a) <=> self::units($b);
    }
}
