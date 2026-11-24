<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the customer's bank took the deposit back, per the bank's notice
 * (client request 2026-11-24: filter chargebacks by bank date). Asked for
 * when raising; empty on chargebacks raised before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chargebacks', function (Blueprint $table) {
            $table->timestampTz('debited_at')->nullable()->after('utr');
        });
    }

    public function down(): void
    {
        Schema::table('chargebacks', function (Blueprint $table) {
            $table->dropColumn('debited_at');
        });
    }
};
