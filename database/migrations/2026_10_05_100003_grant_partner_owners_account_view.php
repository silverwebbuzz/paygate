<?php

use App\Domain\Core\Rbac\SystemRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', SystemRoles::PARTNER_OWNER)->value('id');

        if ($roleId !== null) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => 'accounts.view']);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('permission', 'accounts.view')
            ->whereIn('role_id', DB::table('roles')->where('user_type', 'partner')->select('id'))
            ->delete();
    }
};
