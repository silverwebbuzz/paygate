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
        Schema::table('transactions', function (Blueprint $table) {
            $table->bigInteger('order_amount')->nullable()->after('amount');
        });

        Pg::check('transactions', 'transactions_order_amount_positive', 'COALESCE(order_amount, 1) > 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_order_amount_positive');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('order_amount');
        });
    }
};
