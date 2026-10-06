<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_accounts DROP CONSTRAINT payment_accounts_upi_features');
        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->dropColumn('is_intent_enabled');
        });
        Pg::check('payment_accounts', 'payment_accounts_upi_features', '(NOT is_qr_enabled) OR is_upi_enabled');

        DB::table('partners')->where('manual_payment_type', 'intent')->update(['manual_payment_type' => 'dynamic_qr']);
        DB::statement('ALTER TABLE partners DROP CONSTRAINT partners_manual_payment_type_check');
        Pg::check('partners', 'partners_manual_payment_type_check', 'manual_payment_type IS NULL OR '.Pg::in('manual_payment_type', ['bank_details', 'dynamic_qr']));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_accounts DROP CONSTRAINT payment_accounts_upi_features');
        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->boolean('is_intent_enabled')->default(false);
        });
        Pg::check('payment_accounts', 'payment_accounts_upi_features', '(NOT is_qr_enabled AND NOT is_intent_enabled) OR is_upi_enabled');

        DB::statement('ALTER TABLE partners DROP CONSTRAINT partners_manual_payment_type_check');
        Pg::check('partners', 'partners_manual_payment_type_check', 'manual_payment_type IS NULL OR '.Pg::in('manual_payment_type', ['bank_details', 'intent', 'dynamic_qr']));
    }
};
