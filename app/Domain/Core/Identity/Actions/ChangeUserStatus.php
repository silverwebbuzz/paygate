<?php

namespace App\Domain\Core\Identity\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserStatus;
use App\Domain\Core\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suspends or reactivates a user. Users are never deleted. A suspended user
 * is logged out on their next request (EnsureUserIsActive middleware).
 */
class ChangeUserStatus
{
    public function handle(User $actor, User $user, UserStatus $status, string $reason): User
    {
        if ($user->status === $status) {
            return $user;
        }

        if ($status === UserStatus::Suspended && $user->isLastActiveSuperAdmin()) {
            throw ValidationException::withMessages(['reason' => __('This is the only active super admin and cannot be suspended.')]);
        }

        $old = $user->status;

        DB::transaction(function () use ($actor, $user, $status, $reason, $old) {
            $user->forceFill(['status' => $status])->save();

            AuditLog::record(
                $status === UserStatus::Suspended ? 'user.suspended' : 'user.reactivated',
                $user,
                ['status' => $old->value],
                ['status' => $status->value, 'reason' => $reason],
                $actor,
            );
        });

        return $user;
    }
}
