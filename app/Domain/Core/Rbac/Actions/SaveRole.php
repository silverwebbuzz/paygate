<?php

namespace App\Domain\Core\Rbac\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a role and its permissions. A role only holds permissions
 * available to its portal, and never more than the admin editing it holds.
 */
class SaveRole
{
    /**
     * @param  list<Permission>  $permissions
     */
    public function create(User $actor, UserType $type, string $name, ?string $description, array $permissions): Role
    {
        $this->ensureGrantable($actor, $type, $permissions);

        return DB::transaction(function () use ($actor, $type, $name, $description, $permissions) {
            $role = Role::create([
                'user_type' => $type,
                'name' => $name,
                'description' => $description,
                'is_system' => false,
                'status' => 'active',
            ]);
            $role->syncPermissions($permissions);

            AuditLog::record('role.created', $role, [], [
                'user_type' => $type->value,
                'name' => $name,
                'permissions' => $role->permissionValues(),
            ], $actor);

            return $role;
        });
    }

    /**
     * @param  list<Permission>  $permissions
     */
    public function update(User $actor, Role $role, string $name, ?string $description, string $status, array $permissions): Role
    {
        if ($role->isLocked()) {
            throw ValidationException::withMessages(['permissions' => __('The super admin role cannot be changed.')]);
        }

        $this->ensureGrantable($actor, $role->user_type, $permissions);

        $old = [
            'name' => $role->name,
            'description' => $role->description,
            'status' => $role->status,
            'permissions' => $role->permissionValues(),
        ];

        return DB::transaction(function () use ($actor, $role, $name, $description, $status, $permissions, $old) {
            $role->update(['name' => $name, 'description' => $description, 'status' => $status]);
            $role->syncPermissions($permissions);

            $new = [
                'name' => $role->name,
                'description' => $role->description,
                'status' => $role->status,
                'permissions' => $role->permissionValues(),
            ];

            if ($new !== $old) {
                AuditLog::record('role.updated', $role, $old, $new, $actor);
            }

            return $role;
        });
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function ensureGrantable(User $actor, UserType $type, array $permissions): void
    {
        $held = $actor->permissionNames();

        foreach ($permissions as $permission) {
            if (! $permission->allowedFor($type)) {
                throw ValidationException::withMessages([
                    'permissions' => __(':permission is not available to :portal roles.', ['permission' => $permission->value, 'portal' => $type->label()]),
                ]);
            }

            if (! in_array($permission->value, $held, true)) {
                throw ValidationException::withMessages([
                    'permissions' => __('You can only grant permissions you hold yourself (:permission).', ['permission' => $permission->value]),
                ]);
            }
        }
    }
}
