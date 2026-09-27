<?php

namespace App\Support\Crypto;

use RuntimeException;

/**
 * Keyed hash of a sensitive value (bank account number, UPI ID), stored next
 * to the encrypted value so the database can enforce uniqueness and look it
 * up without ever holding it in clear. The key is PAYGATE_HASH_KEY.
 */
final class BlindIndex
{
    public static function of(string $purpose, string $value): string
    {
        $key = config('paygate.hash_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('PAYGATE_HASH_KEY is not set (see Document/Deployment.md §2.1).');
        }

        // The purpose keeps hashes of different kinds of values unrelated.
        return hash_hmac('sha256', $purpose.':'.$value, $key);
    }
}
