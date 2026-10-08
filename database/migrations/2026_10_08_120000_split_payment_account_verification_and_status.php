<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_accounts', function ($table) {
            $table->string('verification', 20)->nullable();
        });

        DB::statement('ALTER TABLE payment_accounts DROP CONSTRAINT payment_accounts_status_check');
        DB::statement('ALTER TABLE payment_accounts DROP CONSTRAINT payment_accounts_active_is_verified');

        DB::statement(<<<'SQL'
            UPDATE payment_accounts SET
                verification = CASE
                    WHEN status IN ('active', 'verified', 'paused') THEN 'verified'
                    WHEN status = 'disabled' AND verified_at IS NOT NULL THEN 'verified'
                    WHEN status IN ('rejected', 'disabled') THEN 'unverified'
                    ELSE 'pending'
                END,
                status = CASE WHEN status = 'active' THEN 'active' ELSE 'inactive' END
        SQL);

        DB::statement('ALTER TABLE payment_accounts ALTER COLUMN verification SET NOT NULL');
        DB::statement("ALTER TABLE payment_accounts ALTER COLUMN verification SET DEFAULT 'pending'");
        DB::statement("ALTER TABLE payment_accounts ALTER COLUMN status SET DEFAULT 'inactive'");

        Pg::check('payment_accounts', 'payment_accounts_verification_check', Pg::in('verification', ['pending', 'verified', 'unverified']));
        Pg::check('payment_accounts', 'payment_accounts_status_check', Pg::in('status', ['active', 'inactive']));
        Pg::check('payment_accounts', 'payment_accounts_active_is_verified', "status <> 'active' OR verification = 'verified'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_accounts DROP CONSTRAINT payment_accounts_status_check');
        DB::statement('ALTER TABLE payment_accounts DROP CONSTRAINT payment_accounts_verification_check');
        DB::statement('ALTER TABLE payment_accounts DROP CONSTRAINT payment_accounts_active_is_verified');

        DB::statement(<<<'SQL'
            UPDATE payment_accounts SET status = CASE
                WHEN verification = 'verified' AND status = 'active' THEN 'active'
                WHEN verification = 'verified' THEN 'verified'
                WHEN verification = 'unverified' THEN 'rejected'
                ELSE 'verification_pending'
            END
        SQL);

        DB::statement("ALTER TABLE payment_accounts ALTER COLUMN status SET DEFAULT 'new'");
        Schema::table('payment_accounts', function ($table) {
            $table->dropColumn('verification');
        });

        Pg::check('payment_accounts', 'payment_accounts_status_check', Pg::in('status', ['new', 'verification_pending', 'verified', 'active', 'paused', 'disabled', 'rejected']));
        Pg::check('payment_accounts', 'payment_accounts_active_is_verified', "status NOT IN ('verified', 'active', 'paused') OR verified_at IS NOT NULL");
    }
};
