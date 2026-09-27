<?php

use App\Domain\Core\Rbac\SystemRoles;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Roles move from code into the database (editable by Admin). The permission
 * catalogue stays in code (App\Domain\Core\Rbac\Enums\Permission). Users gain partner_id /
 * branch_id, and the database guarantees a user's role belongs to their portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_type', 20);
            $table->string('slug', 50)->nullable()->unique(); // set for built-in roles only
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestampsTz();

            $table->unique(['user_type', 'name']);
            $table->unique(['id', 'user_type']); // target of the users composite FK
        });

        Pg::check('roles', 'roles_user_type_check', Pg::in('user_type', ['admin', 'partner', 'branch']));
        Pg::check('roles', 'roles_status_check', Pg::in('status', ['active', 'inactive']));

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 60);

            $table->primary(['role_id', 'permission']);
        });

        $now = now();

        foreach (SystemRoles::definitions() as $slug => $definition) {
            $roleId = (string) Str::uuid7();

            DB::table('roles')->insert([
                'id' => $roleId,
                'user_type' => $definition['type']->value,
                'slug' => $slug,
                'name' => $definition['name'],
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

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('role_id')->nullable()->after('type');
            $table->foreignUuid('partner_id')->nullable()->after('role_id')->constrained();
            $table->foreignUuid('branch_id')->nullable()->after('partner_id')->constrained();

            $table->index('partner_id');
            $table->index('branch_id');
        });

        // Phase 1 stored the role slug in users.role; map it to the new roles.
        DB::statement('UPDATE users SET role_id = roles.id FROM roles WHERE roles.slug = users.role');

        $this->attachOrphanedUsersToPlaceholders($now);

        DB::statement('ALTER TABLE users ALTER COLUMN role_id SET NOT NULL');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_matches_type');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_fk FOREIGN KEY (role_id, type) REFERENCES roles (id, user_type)');
        Pg::check('users', 'users_organisation_check', "(type = 'admin' AND partner_id IS NULL AND branch_id IS NULL) OR (type = 'partner' AND partner_id IS NOT NULL AND branch_id IS NULL) OR (type = 'branch' AND branch_id IS NOT NULL AND partner_id IS NULL)");
    }

    /**
     * Only matters on development databases created during Phase 1, where
     * demo partner/branch users existed before partners and branches did.
     * Fresh installs (staging, production) have no such users.
     */
    private function attachOrphanedUsersToPlaceholders(DateTimeInterface $now): void
    {
        if (DB::table('users')->where('type', 'partner')->exists()) {
            $partnerId = (string) Str::uuid7();
            DB::table('partners')->insert([
                'id' => $partnerId, 'code' => 'MIGRATED-P', 'name' => 'Migrated partner users',
                'email' => 'migrated@paygate.invalid', 'website_url' => 'https://paygate.invalid',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('users')->where('type', 'partner')->update(['partner_id' => $partnerId]);
        }

        if (DB::table('users')->where('type', 'branch')->exists()) {
            $branchId = (string) Str::uuid7();
            DB::table('branches')->insert([
                'id' => $branchId, 'code' => 'MIGRATED-B', 'name' => 'Migrated branch users',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('users')->where('type', 'branch')->update(['branch_id' => $branchId]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_organisation_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_fk');

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 40)->nullable();
        });

        DB::statement('UPDATE users SET role = roles.slug FROM roles WHERE roles.id = users.role_id');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('partner_id');
            $table->dropColumn('role_id');
        });

        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
