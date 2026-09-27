<?php

namespace App\Domain\Core\Identity\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
