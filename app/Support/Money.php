<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Converts between what people type (rupees, "1,500.50") and what we store
 * (integer paise). Never uses floats.
 */
final class Money
{
    /** Validation rule for a rupee amount typed in a form ("1500" or "1500.50"). */
    public const RUPEES_RULE = 'regex:/^\d{1,11}(\.\d{1,2})?$/';

    public static function toPaise(string $rupees): int
    {
        $rupees = str_replace([',', ' ', '₹'], '', trim($rupees));

        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $rupees) !== 1) {
            throw new InvalidArgumentException("Invalid amount: {$rupees}");
        }

        [$whole, $fraction] = array_pad(explode('.', $rupees), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /**
     * Null-safe variant for optional limits: '' or null → null (no limit).
     */
    public static function toPaiseOrNull(?string $rupees): ?int
    {
        return $rupees === null || trim($rupees) === '' ? null : self::toPaise($rupees);
    }

    /**
     * 150050 → "1500.50" (plain, for form fields).
     */
    public static function toRupees(?int $paise): ?string
    {
        return $paise === null ? null : intdiv($paise, 100).'.'.str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }
}
