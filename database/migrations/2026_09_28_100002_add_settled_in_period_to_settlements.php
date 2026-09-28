<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10: a settlement shows how the position moved in its period.
 * Payments recorded during the period (for an earlier settlement) are a
 * movement of their own, so opening + movements = closing adds up.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['settlements', 'settlement_lines'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->bigInteger('settlements_total')->default(0)->after('adjustments_total');
            });
        }
    }

    public function down(): void
    {
        foreach (['settlements', 'settlement_lines'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('settlements_total');
            });
        }
    }
};
