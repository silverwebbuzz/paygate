<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12:
 * - transaction_reversals: chargebacks, pay-in refunds and returned payouts
 *   (decided 2026-09-28, G-20 / G-21 / G-22). One per transaction; the
 *   ledger journal (type `reversal`) references the original.
 * - users.notification_preferences: which alerts a person also gets by
 *   email (G-47; in-panel alerts are always on).
 * - permissions reversals.view / reversals.create for the built-in Admin
 *   roles (super admin holds every admin permission anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_reversals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->foreignUuid('transaction_id')->unique()->constrained();
            $table->string('kind', 20);
            $table->string('bearer', 10)->nullable();
            $table->bigInteger('amount');
            $table->text('reason');
            $table->string('external_reference', 100)->nullable();
            $table->uuid('journal_id')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['kind', 'created_at']);
        });

        Pg::check('transaction_reversals', 'transaction_reversals_kind_check', Pg::in('kind', ['chargeback', 'refund', 'return']));
        Pg::check('transaction_reversals', 'transaction_reversals_bearer_check', "bearer IS NULL OR bearer IN ('partner', 'branch')");
        Pg::check('transaction_reversals', 'transaction_reversals_amount_positive', 'amount > 0');
        Pg::appendOnly('transaction_reversals');

        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('notification_preferences')->nullable();
        });

        $grants = [
            'admin.ops' => ['reversals.view', 'reversals.create'],
            'admin.finance' => ['reversals.view', 'reversals.create'],
            'admin.viewer' => ['reversals.view'],
        ];

        foreach ($grants as $slug => $permissions) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');

            if ($roleId !== null) {
                DB::table('role_permissions')->insertOrIgnore(array_map(fn (string $permission) => ['role_id' => $roleId, 'permission' => $permission], $permissions));
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', ['reversals.view', 'reversals.create'])->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });

        Schema::dropIfExists('transaction_reversals');
    }
};
