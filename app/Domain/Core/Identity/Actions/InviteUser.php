<?php

namespace App\Domain\Core\Identity\Actions;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Creates a user without a password and emails them a link to set one
 * (used for the branch admin named when a branch is created).
 */
class InviteUser
{
    public function __construct(private CreateUser $createUser, private SendInvitation $sendInvitation) {}

    public function handle(User $actor, string $name, string $email, Role $role, ?string $organisationId = null): User
    {
        return DB::transaction(function () use ($actor, $name, $email, $role, $organisationId) {
            $user = $this->createUser->handle($actor, $name, $email, $role, $organisationId);

            $this->sendInvitation->handle($actor, $user);

            return $user;
        });
    }
}
