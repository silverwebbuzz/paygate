<?php

namespace App\Domain\PaymentAccount\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
