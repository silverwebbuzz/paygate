<?php

namespace App\Domain\Core\Rbac\Policies;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\Models\Role;

/**
 * Roles are managed by Admin only (the roles.* permissions exist only for
 * admin roles). The super admin role is locked, and nobody may edit a role
 * that holds more than they do.
 */
class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::RolesView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::RolesCreate);
    }

    public function update(User $actor, Role $role): bool
    {
        return $actor->hasPermission(Permission::RolesUpdate)
            && ! $role->isLocked()
            && $role->isWithinPermissionsOf($actor);
    }

    public function delete(User $actor, Role $role): bool
    {
        return $actor->hasPermission(Permission::RolesDelete)
            && ! $role->is_system
            && ! $role->users()->exists();
    }
}
