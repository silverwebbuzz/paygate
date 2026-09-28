<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10: the checker's note when approving or rejecting an adjustment
 * (maker–checker, G-44), shown next to the maker's reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            $table->text('decision_note')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            $table->dropColumn('decision_note');
        });
    }
};
