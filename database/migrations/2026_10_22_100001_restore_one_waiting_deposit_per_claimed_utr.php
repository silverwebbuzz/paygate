<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reverses 2026_10_21_100001 (client decision 2026-10-22): a customer's UTR
 * on a waiting deposit holds it again. Only a declined / expired / cancelled
 * / failed transaction releases a UTR; a second customer entering a pending
 * or successful one is refused (App\Domain\Transaction\Utr).
 */
return new class extends Migration
{
    private const CLAIMING = "('payment_submitted', 'payment_detected', 'under_review')";

    public function up(): void
    {
        // Waiting deposits that shared a UTR while it was allowed must be
        // settled by a person first (decline the wrong one).
        /** @var list<object{utr: string, refs: string}> $rows */
        $rows = DB::select("SELECT customer_utr_normalized AS utr, string_agg(reference, ', ' ORDER BY created_at) AS refs FROM transactions WHERE direction = 'payin' AND customer_utr_normalized IS NOT NULL AND status IN ".self::CLAIMING.' GROUP BY customer_utr_normalized HAVING count(*) > 1');

        if ($rows !== []) {
            throw new RuntimeException("These UTRs are claimed by more than one waiting deposit. Decline the wrong one, then migrate again:\n".implode("\n", array_map(fn (object $row) => "{$row->utr}: {$row->refs}", $rows)));
        }

        DB::statement('DROP INDEX IF EXISTS transactions_customer_utr_index');
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS transactions_unique_claimed_utr ON transactions (customer_utr_normalized) WHERE direction = 'payin' AND customer_utr_normalized IS NOT NULL AND status IN ".self::CLAIMING);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_unique_claimed_utr');
        DB::statement("CREATE INDEX IF NOT EXISTS transactions_customer_utr_index ON transactions (customer_utr_normalized) WHERE direction = 'payin' AND customer_utr_normalized IS NOT NULL");
    }
};
