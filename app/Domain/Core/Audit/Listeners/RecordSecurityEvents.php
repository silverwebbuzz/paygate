<?php

namespace App\Domain\Core\Audit\Listeners;

use App\Domain\Core\Audit\Enums\SecurityEvent;
use App\Domain\Core\Audit\Models\SecurityLog;
use App\Domain\Core\Identity\Models\User;
use App\Support\SingleSession;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

/**
 * Writes authentication events to the append-only security log.
 * Registered automatically through Laravel's event discovery.
 */
class RecordSecurityEvents
{
    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        // One login per user at a time: this sign-in gets a new token, so
        // every other browser's session ends on its next request
        // (EnsureSingleSession). An old "remember me" cookie can't take the
        // place of a real sign-in: the remember token cycles here, and a
        // login through such a cookie gets no session token.
        if (Auth::guard('web')->viaRemember()) {
            SecurityLog::record(SecurityEvent::LoginSucceeded, $event->user, context: ['remember' => true]);

            return;
        }

        $token = Str::random(64);

        $event->user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
            'session_token' => $token,
            'remember_token' => Str::random(60),
        ])->saveQuietly();

        if (request()->hasSession()) {
            request()->session()->put(SingleSession::SESSION_KEY, $token);
        }

        SecurityLog::record(SecurityEvent::LoginSucceeded, $event->user, context: ['remember' => $event->remember]);
    }

    public function handleFailed(Failed $event): void
    {
        SecurityLog::record(
            SecurityEvent::LoginFailed,
            $event->user instanceof User ? $event->user : null,
            login: isset($event->credentials[Fortify::username()]) ? (string) $event->credentials[Fortify::username()] : null,
        );
    }

    public function handleLockout(Lockout $event): void
    {
        SecurityLog::record(SecurityEvent::LoginLockedOut, login: (string) $event->request->input(Fortify::username()));
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            SecurityLog::record(SecurityEvent::Logout, $event->user);
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            SecurityLog::record(SecurityEvent::PasswordReset, $event->user);
        }
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        if ($event->user instanceof User) {
            SecurityLog::record(SecurityEvent::TwoFactorFailed, $event->user);
        }
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        if ($event->user instanceof User) {
            SecurityLog::record(SecurityEvent::TwoFactorEnabled, $event->user);
        }
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        if ($event->user instanceof User) {
            SecurityLog::record(SecurityEvent::TwoFactorDisabled, $event->user);
        }
    }
}
