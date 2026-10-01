<?php

use App\Domain\Core\Rbac\SystemRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the built-in "Admin" role (SystemRoles::ADMIN_FULL): every admin
 * permission, but not the super-admin tools. If someone already made a
 * custom admin role called "Admin", the new one is called "Admin (full)".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('roles')->where('slug', SystemRoles::ADMIN_FULL)->exists()) {
            return;
        }

        $definition = SystemRoles::definitions()[SystemRoles::ADMIN_FULL];
        $nameTaken = DB::table('roles')->where('user_type', 'admin')->where('name', $definition['name'])->exists();
        $roleId = (string) Str::uuid7();
        $now = now();

        DB::table('roles')->insert([
            'id' => $roleId,
            'user_type' => $definition['type']->value,
            'slug' => SystemRoles::ADMIN_FULL,
            'name' => $nameTaken ? $definition['name'].' (full)' : $definition['name'],
            'description' => $definition['description'],
            'is_system' => true,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('role_permissions')->insert(array_map(
            fn ($permission) => ['role_id' => $roleId, 'permission' => $permission->value],
            $definition['permissions'],
        ));
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('slug', SystemRoles::ADMIN_FULL)->first();

        if ($role !== null && ! DB::table('users')->where('role_id', $role->id)->exists()) {
            DB::table('roles')->where('id', $role->id)->delete();
        }
    }
};
