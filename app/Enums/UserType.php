<?php

namespace App\Enums;

/**
 * Which portal a user belongs to. Every user is exactly one type.
 */
enum UserType: string
{
    case Admin = 'admin';
    case Partner = 'partner';
    case Branch = 'branch';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Partner => 'Partner',
            self::Branch => 'Branch',
        };
    }

    /**
     * Admin and Branch users handle money and bank details, so 2FA is mandatory.
     */
    public function requiresTwoFactor(): bool
    {
        return $this !== self::Partner;
    }

    /**
     * Route name of this portal's landing page.
     */
    public function homeRoute(): string
    {
        return $this->value.'.dashboard';
    }
}
