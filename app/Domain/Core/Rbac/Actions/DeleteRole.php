<?php

namespace App\Domain\Core\Rbac\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a custom role nobody holds. Built-in roles are never deleted.
 */
class DeleteRole
{
    public function handle(User $actor, Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => __('Built-in roles cannot be deleted.')]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => __('Move the users of this role to another role first.')]);
        }

        DB::transaction(function () use ($actor, $role) {
            AuditLog::record('role.deleted', $role, [
                'user_type' => $role->user_type->value,
                'name' => $role->name,
                'permissions' => $role->permissionValues(),
            ], [], $actor);

            $role->delete();
        });
    }
}
