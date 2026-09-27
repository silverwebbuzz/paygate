<?php

namespace App\Domain\Allocation\Exceptions;

use RuntimeException;

/**
 * A daily limit (account, branch, pair or partner) has no room for this
 * payment; allocation moves on to the next account.
 */
class LimitReached extends RuntimeException
{
    public function __construct(public readonly string $scope)
    {
        parent::__construct("The {$scope} limit is reached.");
    }
}
