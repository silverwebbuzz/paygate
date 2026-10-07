<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->where('user_type', 'partner')->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission' => 'accounts.view',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('permission', 'accounts.view')
            ->whereIn('role_id', DB::table('roles')->where('user_type', 'partner')->where('slug', '!=', 'partner.owner')->select('id'))
            ->delete();
    }
};
