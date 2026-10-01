<?php

namespace App\Domain\Core\Identity\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserStatus;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a user. The user's portal follows from the role. Partner and
 * branch owners can only add users to their own organisation; Admin picks
 * the partner or branch.
 *
 * With a password (the Users screen) the account works at once and the
 * creator passes the password on. Without one (InviteUser) the password is
 * unusable until the user sets their own through the invitation link.
 */
class CreateUser
{
    public function handle(User $actor, string $name, string $email, Role $role, ?string $organisationId = null, ?string $password = null): User
    {
        $type = $role->user_type;

        if (! $actor->isType(UserType::Admin)) {
            $role->ensureAssignableBy($actor, $actor->type);
            $organisationId = $actor->organisationId();
        } else {
            $role->ensureAssignableBy($actor, $type);
        }

        if ($type !== UserType::Admin && $organisationId === null) {
            throw ValidationException::withMessages([
                'organisation_id' => __('Choose the :portal this user works for.', ['portal' => strtolower($type->label())]),
            ]);
        }

        return DB::transaction(function () use ($actor, $name, $email, $role, $type, $organisationId, $password) {
            $user = User::create([
                'name' => $name,
                'email' => Str::lower($email),
                'password' => $password ?? Str::random(64),
                'type' => $type,
                'role_id' => $role->id,
                'partner_id' => $type === UserType::Partner ? $organisationId : null,
                'branch_id' => $type === UserType::Branch ? $organisationId : null,
                'status' => UserStatus::Active,
            ]);

            if ($password !== null) {
                // The creator vouches for the address; there is no link to click.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            AuditLog::record($password === null ? 'user.invited' : 'user.created', $user, [], [
                'name' => $user->name,
                'email' => $user->email,
                'type' => $type->value,
                'role' => $role->name,
                'organisation_id' => $organisationId,
            ], $actor);

            return $user;
        });
    }
}
