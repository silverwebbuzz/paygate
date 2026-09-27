<?php

namespace App\Domain\Core\Audit\Enums;

enum SecurityEvent: string
{
    case LoginSucceeded = 'login.succeeded';
    case LoginFailed = 'login.failed';
    case LoginLockedOut = 'login.locked_out';
    case LoginBlockedSuspended = 'login.blocked_suspended';
    case Logout = 'logout';
    case SessionTerminatedSuspended = 'session.terminated_suspended';
    case TwoFactorFailed = 'two_factor.failed';
    case TwoFactorEnabled = 'two_factor.enabled';
    case TwoFactorDisabled = 'two_factor.disabled';
    case PasswordReset = 'password.reset';
    case PasswordChanged = 'password.changed';
    case AccessDenied = 'access.denied';
}
