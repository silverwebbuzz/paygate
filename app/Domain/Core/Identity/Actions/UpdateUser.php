<?php

namespace App\Domain\Core\Identity\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Changes a user's name, email or role. The portal and organisation of a
 * user never change: invite a new user instead. A changed email address
 * must be verified again by the user.
 */
class UpdateUser
{
    public function handle(User $actor, User $user, string $name, string $email, Role $role): User
    {
        if ($role->id !== $user->role_id) {
            $role->ensureAssignableBy($actor, $user->type);

            if ($user->isLastActiveSuperAdmin()) {
                throw ValidationException::withMessages(['role_id' => __('This is the only active super admin. Make someone else super admin first.')]);
            }
        }

        $old = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->name];

        $user->fill(['name' => $name, 'email' => Str::lower($email), 'role_id' => $role->id]);
        $emailChanged = $user->isDirty('email');

        if (! $user->isDirty()) {
            return $user;
        }

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        DB::transaction(function () use ($actor, $user, $old, $role) {
            $user->save();

            AuditLog::record('user.updated', $user, $old, ['name' => $user->name, 'email' => $user->email, 'role' => $role->name], $actor);
        });

        if ($emailChanged && ! $user->isInvited()) {
            $user->sendEmailVerificationNotification();
        }

        return $user->setRelation('role', $role);
    }
}
