<?php

namespace App\Domain\Allocation\Exceptions;

use RuntimeException;

/**
 * No account can take this payment right now. `reason`:
 * - no_account: nothing eligible with room left (Admin should be alerted)
 * - method_not_offered: the partner doesn't offer this method
 * - closed: the pay-in is no longer waiting for payment
 * - no_other_account: the customer asked for different details and no other
 *   account can take this payment right now
 */
class NoAccountAvailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("No account available ({$reason}).");
    }
}
