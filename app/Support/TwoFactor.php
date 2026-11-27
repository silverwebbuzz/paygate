<?php

namespace App\Support;

/**
 * Whether two-factor authentication is on for this server (client request
 * 2026-11-27): on live only, off on testbox and sandbox, unless
 * PAYGATE_TWO_FACTOR says otherwise. When it is off, nobody is asked for a
 * 2FA code at login and Admin / Branch users are not made to set it up.
 * Only an Admin can reset a user's 2FA (Users › Reset two-factor).
 */
final class TwoFactor
{
    public static function on(): bool
    {
        $configured = config('paygate.auth.two_factor');

        if ($configured === null || $configured === '') {
            return ApiEnvironment::isLive();
        }

        return filter_var($configured, FILTER_VALIDATE_BOOL);
    }
}
