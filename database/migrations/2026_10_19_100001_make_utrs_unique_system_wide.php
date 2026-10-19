<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const RELEASED = "('rejected', 'expired', 'cancelled', 'failed')";

    private const CLAIMING = "('payment_submitted', 'payment_detected', 'under_review')";

    /**
     * A UTR is one bank transfer (App\Domain\Transaction\Utr): unique across
     * pay-ins and payouts, not per account. The app checks first, with a
     * clear message; these indexes catch two people saving at the same moment.
     */
    public function up(): void
    {
        $this->refuseExistingDuplicates();

        DB::statement('DROP INDEX IF EXISTS transactions_unique_payin_bank_utr');
        DB::statement('CREATE UNIQUE INDEX transactions_unique_bank_utr ON transactions (bank_utr_normalized) WHERE bank_utr_normalized IS NOT NULL AND status NOT IN '.self::RELEASED);
        DB::statement("CREATE UNIQUE INDEX transactions_unique_claimed_utr ON transactions (customer_utr_normalized) WHERE direction = 'payin' AND customer_utr_normalized IS NOT NULL AND status IN ".self::CLAIMING);
        DB::statement('CREATE INDEX statement_entries_utr_normalized_index ON statement_entries (utr_normalized) WHERE utr_normalized IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS statement_entries_utr_normalized_index');
        DB::statement('DROP INDEX IF EXISTS transactions_unique_claimed_utr');
        DB::statement('DROP INDEX IF EXISTS transactions_unique_bank_utr');
        DB::statement("CREATE UNIQUE INDEX transactions_unique_payin_bank_utr ON transactions (payment_account_id, bank_utr_normalized) WHERE direction = 'payin' AND bank_utr_normalized IS NOT NULL AND status NOT IN ('rejected', 'expired', 'cancelled')");
    }

    /**
     * Existing data that breaks the rule must be fixed by a person first
     * (e.g. reverse one of the two), so stop with the references.
     */
    private function refuseExistingDuplicates(): void
    {
        $bank = DB::select('SELECT bank_utr_normalized AS utr, string_agg(reference, \', \' ORDER BY created_at) AS refs FROM transactions WHERE bank_utr_normalized IS NOT NULL AND status NOT IN '.self::RELEASED.' GROUP BY bank_utr_normalized HAVING count(*) > 1');
        $claimed = DB::select("SELECT customer_utr_normalized AS utr, string_agg(reference, ', ' ORDER BY created_at) AS refs FROM transactions WHERE direction = 'payin' AND customer_utr_normalized IS NOT NULL AND status IN ".self::CLAIMING.' GROUP BY customer_utr_normalized HAVING count(*) > 1');

        /** @var list<object{utr: string, refs: string}> $rows */
        $rows = [...$bank, ...$claimed];
        $problems = array_map(fn (object $row) => "{$row->utr}: {$row->refs}", $rows);

        if ($problems !== []) {
            throw new RuntimeException("These UTRs are on more than one transaction. Fix them (decline or reverse the wrong one), then migrate again:\n".implode("\n", $problems));
        }
    }
};
