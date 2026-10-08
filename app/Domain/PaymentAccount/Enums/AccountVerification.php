<?php

namespace App\Domain\PaymentAccount\Enums;

enum AccountVerification: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Unverified = 'unverified';
}
