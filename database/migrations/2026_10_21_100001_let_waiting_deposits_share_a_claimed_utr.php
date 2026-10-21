<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A customer's UTR on a waiting deposit no longer locks it (decided
 * 2026-10-21): two waiting deposits may claim the same UTR, are flagged, and
 * the bank line decides. A bank UTR (approved / paid) stays unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_unique_claimed_utr');
        DB::statement("CREATE INDEX IF NOT EXISTS transactions_customer_utr_index ON transactions (customer_utr_normalized) WHERE direction = 'payin' AND customer_utr_normalized IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_customer_utr_index');
        DB::statement("CREATE UNIQUE INDEX transactions_unique_claimed_utr ON transactions (customer_utr_normalized) WHERE direction = 'payin' AND customer_utr_normalized IS NOT NULL AND status IN ('payment_submitted', 'payment_detected', 'under_review')");
    }
};
