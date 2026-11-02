<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin can delete a bank / UPI account: status "deleted" keeps the row
 * for history. A deleted account no longer reserves its account number or
 * UPI ID, so the same one can be added again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_accounts DROP CONSTRAINT payment_accounts_status_check');
        Pg::check('payment_accounts', 'payment_accounts_status_check', Pg::in('status', ['active', 'inactive', 'deleted']));

        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->dropUnique(['account_number_hash', 'ifsc']);
            $table->dropUnique(['upi_id_hash']);
        });

        DB::statement("CREATE UNIQUE INDEX payment_accounts_bank_unique ON payment_accounts (account_number_hash, ifsc) WHERE status <> 'deleted'");
        DB::statement("CREATE UNIQUE INDEX payment_accounts_upi_unique ON payment_accounts (upi_id_hash) WHERE status <> 'deleted'");
    }

    public function down(): void
    {
        DB::statement("UPDATE payment_accounts SET status = 'inactive' WHERE status = 'deleted'");

        DB::statement('DROP INDEX payment_accounts_bank_unique');
        DB::statement('DROP INDEX payment_accounts_upi_unique');

        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->unique(['account_number_hash', 'ifsc']);
            $table->unique('upi_id_hash');
        });

        DB::statement('ALTER TABLE payment_accounts DROP CONSTRAINT payment_accounts_status_check');
        Pg::check('payment_accounts', 'payment_accounts_status_check', Pg::in('status', ['active', 'inactive']));
    }
};
