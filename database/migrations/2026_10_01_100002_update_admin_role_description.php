<?php

use App\Domain\Core\Rbac\SystemRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Admin role's description is shown to the client, so it no longer
 * mentions the super admin role (which only super admins see).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('slug', SystemRoles::ADMIN_FULL)->update([
            'description' => SystemRoles::definitions()[SystemRoles::ADMIN_FULL]['description'],
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Nothing to undo: the old wording isn't needed back.
    }
};
