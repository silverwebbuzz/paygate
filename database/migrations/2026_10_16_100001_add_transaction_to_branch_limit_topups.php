<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An approved deposit at a top-up branch is now written to the
        // allowance history too (a negative row pointing at the pay-in), so the
        // Top-ups tab reads as a running statement. One row per pay-in at most.
        Schema::table('branch_limit_topups', function (Blueprint $table) {
            $table->foreignUuid('transaction_id')->nullable()->unique()->after('branch_id')->constrained('transactions');
        });
    }

    public function down(): void
    {
        Schema::table('branch_limit_topups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_id');
        });
    }
};
