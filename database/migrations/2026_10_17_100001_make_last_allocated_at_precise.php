<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Round robin orders by this; whole seconds tie when several customers
        // are allocated in the same second, and the same branch is picked again.
        DB::statement('ALTER TABLE payment_accounts ALTER COLUMN last_allocated_at TYPE timestamp(6) with time zone');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_accounts ALTER COLUMN last_allocated_at TYPE timestamp(0) with time zone');
    }
};
