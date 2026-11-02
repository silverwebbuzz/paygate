<?php

namespace App\Domain\PaymentAccount\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /** Removed by Admin: kept for history, hidden from every list but "Deleted". */
    case Deleted = 'deleted';

    /** @return list<string> what Admin and branches may switch an account to */
    public static function switchable(): array
    {
        return [self::Active->value, self::Inactive->value];
    }
}
