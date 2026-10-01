<?php

namespace App\Domain\Core\Identity\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sets another user's password from the Users screen (e.g. they forgot it,
 * or never received their invitation). "Remember me" logins stop working.
 */
class SetUserPassword
{
    public function handle(User $actor, User $user, string $password): void
    {
        DB::transaction(function () use ($actor, $user, $password) {
            $user->forceFill([
                'password' => $password,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'remember_token' => Str::random(60),
            ])->save();

            AuditLog::record('user.password_set', $user, [], [], $actor);
        });
    }
}
