<?php

namespace App\Domain\Core\Identity\Policies;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;

/**
 * Who may see and manage which users.
 *
 * - Admin users (with the permission) manage everyone; partner and branch
 *   owners manage the users of their own organisation only.
 * - Nobody manages their own account here (that's Profile & settings).
 * - Nobody manages a user whose role holds more than they do.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::UsersView);
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->hasPermission(Permission::UsersView) && $this->inScope($actor, $target);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::UsersCreate);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->hasPermission(Permission::UsersUpdate)
            && $this->inScope($actor, $target)
            && ! $actor->is($target)
            && $target->role->isWithinPermissionsOf($actor);
    }

    private function inScope(User $actor, User $target): bool
    {
        return match ($actor->type) {
            UserType::Admin => true,
            UserType::Partner => $target->type === UserType::Partner && $target->partner_id === $actor->partner_id,
            UserType::Branch => $target->type === UserType::Branch && $target->branch_id === $actor->branch_id,
        };
    }
}
