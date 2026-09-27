<?php

namespace App\Domain\Core\Identity\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a user's two-factor authentication, e.g. after they lost their
 * phone. Admin and branch users must set it up again at their next login.
 */
class ResetUserTwoFactor
{
    public function handle(User $actor, User $user, string $reason): User
    {
        DB::transaction(function () use ($actor, $user, $reason) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            AuditLog::record('user.two_factor_reset', $user, [], ['reason' => $reason], $actor);
        });

        return $user;
    }
}
