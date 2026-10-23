<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A payout paid in parts (decided 2026-10-23): the branch may send one
 * withdrawal as several bank transfers, hours or days apart, each with its
 * own amount, UTR, date and proof (image or PDF). The payout becomes
 * `success` (and is booked once, for the whole amount) when the parts add
 * up to it; until then it stays `processing`.
 *
 * - payout_payments: the parts. A UTR is one bank transfer system-wide, so
 *   utr_normalized is unique. Existing paid payouts get one part each.
 * - statement_entries.payout_payment_id: a debit line links to the part
 *   with its UTR (several lines per payout, one per part).
 * - payout_returns.payout_payment_id: the part that came back. A payout
 *   may have several returns (one confirmed per part, one open at a time).
 * - files.purpose payout_proof: the branch's transfer proof.
 */
return new class extends Migration
{
    private const FILE_PURPOSES = ['payment_proof', 'logo', 'statement', 'kyc', 'settlement_proof', 'export', 'chargeback_proof', 'return_proof'];

    public function up(): void
    {
        DB::statement('ALTER TABLE files DROP CONSTRAINT files_purpose_check');
        Pg::check('files', 'files_purpose_check', Pg::in('purpose', [...self::FILE_PURPOSES, 'payout_proof']));

        Schema::create('payout_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained();
            $table->foreignUuid('branch_id')->constrained();
            $table->bigInteger('amount');
            $table->string('utr', 50);
            $table->string('utr_normalized', 50);
            // When the transfer left the branch's bank (as on its statement).
            $table->timestampTz('paid_at');
            // Required for new parts; null on parts made from older payouts.
            $table->foreignUuid('proof_file_id')->nullable()->constrained('files');
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at');

            $table->index(['transaction_id', 'paid_at']);
        });

        Pg::check('payout_payments', 'payout_payments_amount_positive', 'amount > 0');
        DB::statement('CREATE UNIQUE INDEX payout_payments_unique_utr ON payout_payments (utr_normalized)');

        // Every paid payout so far was one transfer: make it its part.
        DB::statement(<<<'SQL'
            INSERT INTO payout_payments (id, transaction_id, branch_id, amount, utr, utr_normalized, paid_at, created_by, created_at)
            SELECT gen_random_uuid(), t.id, t.branch_id, t.amount, COALESCE(t.bank_utr, t.bank_utr_normalized), t.bank_utr_normalized,
                   COALESCE(t.succeeded_at, t.decided_at, t.updated_at), t.decided_by, COALESCE(t.succeeded_at, t.decided_at, t.updated_at)
            FROM transactions t
            WHERE t.direction = 'payout' AND t.bank_utr_normalized IS NOT NULL AND t.branch_id IS NOT NULL
              AND t.status NOT IN ('rejected', 'expired', 'cancelled', 'failed')
            SQL);

        Schema::table('statement_entries', function (Blueprint $table) {
            $table->foreignUuid('payout_payment_id')->nullable()->constrained('payout_payments');
        });

        DB::statement(<<<'SQL'
            UPDATE statement_entries e SET payout_payment_id = p.id
            FROM payout_payments p
            WHERE e.transaction_id = p.transaction_id AND e.entry_direction = 'debit'
            SQL);

        // A deposit has one bank line; a payout one per part.
        DB::statement('DROP INDEX statement_entries_one_per_transaction');
        DB::statement('CREATE UNIQUE INDEX statement_entries_one_per_transaction ON statement_entries (transaction_id) WHERE transaction_id IS NOT NULL AND payout_payment_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX statement_entries_one_per_payout_payment ON statement_entries (payout_payment_id) WHERE payout_payment_id IS NOT NULL');

        Schema::table('payout_returns', function (Blueprint $table) {
            $table->foreignUuid('payout_payment_id')->nullable()->constrained('payout_payments');
        });

        DB::statement(<<<'SQL'
            UPDATE payout_returns r SET payout_payment_id = p.id
            FROM payout_payments p
            WHERE r.transaction_id = p.transaction_id AND r.status = 'approved'
            SQL);

        DB::statement('DROP INDEX payout_returns_one_per_transaction');
        DB::statement("CREATE UNIQUE INDEX payout_returns_one_open_per_transaction ON payout_returns (transaction_id) WHERE status = 'requested'");
        DB::statement("CREATE UNIQUE INDEX payout_returns_one_per_payment ON payout_returns (payout_payment_id) WHERE status = 'approved'");
        Pg::check('payout_returns', 'payout_returns_payment_check', "status <> 'approved' OR payout_payment_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payout_returns DROP CONSTRAINT payout_returns_payment_check');
        DB::statement('DROP INDEX payout_returns_one_per_payment');
        DB::statement('DROP INDEX payout_returns_one_open_per_transaction');
        DB::statement("CREATE UNIQUE INDEX payout_returns_one_per_transaction ON payout_returns (transaction_id) WHERE status IN ('requested', 'approved')");

        Schema::table('payout_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_payment_id');
        });

        DB::statement('DROP INDEX statement_entries_one_per_payout_payment');
        DB::statement('DROP INDEX statement_entries_one_per_transaction');
        DB::statement('CREATE UNIQUE INDEX statement_entries_one_per_transaction ON statement_entries (transaction_id) WHERE transaction_id IS NOT NULL');

        Schema::table('statement_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_payment_id');
        });

        Schema::dropIfExists('payout_payments');

        DB::table('files')->where('purpose', 'payout_proof')->delete();
        DB::statement('ALTER TABLE files DROP CONSTRAINT files_purpose_check');
        Pg::check('files', 'files_purpose_check', Pg::in('purpose', self::FILE_PURPOSES));
    }
};
