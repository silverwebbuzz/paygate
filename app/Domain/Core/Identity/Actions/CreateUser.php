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
 * Creates an active user with the password the creator sets; the creator
 * passes it on. Admin adds admin users on the Users screen and partner /
 * branch users on that partner's or branch's own Users screen; partner and
 * branch owners add users to their own organisation only.
 */
class CreateUser
{
    public function handle(User $actor, UserType $type, ?string $organisationId, string $name, string $email, Role $role, string $password): User
    {
        if (! $actor->isType(UserType::Admin)) {
            $type = $actor->type;
            $organisationId = $actor->organisationId();
        }

        $role->ensureAssignableBy($actor, $type);

        if ($type === UserType::Admin) {
            $organisationId = null;
        } elseif ($organisationId === null) {
            throw ValidationException::withMessages([
                'organisation_id' => __('Choose the :portal this user works for.', ['portal' => strtolower($type->label())]),
            ]);
        }

        return DB::transaction(function () use ($actor, $type, $organisationId, $name, $email, $role, $password) {
            $user = User::create([
                'name' => $name,
                'email' => Str::lower($email),
                'password' => $password,
                'type' => $type,
                'role_id' => $role->id,
                'partner_id' => $type === UserType::Partner ? $organisationId : null,
                'branch_id' => $type === UserType::Branch ? $organisationId : null,
                'status' => UserStatus::Active,
            ]);
            // The creator vouches for the address; there is no email to confirm.
            $user->forceFill(['email_verified_at' => now()])->save();

            AuditLog::record('user.created', $user, [], [
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
