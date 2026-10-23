<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payout returns as a request and a confirmation (decided 2026-10-22), the
 * payout side of chargebacks:
 *
 * - payout_returns: the partner (or Admin for it) says the customer didn't
 *   receive a paid payout; the branch (or Admin) confirms how much came
 *   back to its account (full or part) with the UTR of that credit and the
 *   bank's proof and the date it came back, or rejects it. A branch that sees the money come back
 *   first records it directly. Only a confirmed one is booked (journal type
 *   `payout_return`, on the confirmation date). A rejected one can be
 *   raised again.
 * - transactions.return_status: requested / approved / rejected next to the
 *   status, which stays `success` (the payout was paid).
 * - settlements / settlement_lines.returns_total: shown on its own line.
 * - files.purpose return_proof: the bank's proof of the credit.
 * - permissions: none new. Partners who may request payouts
 *   (payouts.create) raise; branches with reversals.create record, confirm
 *   or reject; Admin raises with reversals.create, confirms with
 *   reversals.approve.
 */
return new class extends Migration
{
    private const JOURNAL_TYPES = ['payin_success', 'payout_success', 'reversal', 'adjustment', 'settlement', 'chargeback'];

    private const FILE_PURPOSES = ['payment_proof', 'logo', 'statement', 'kyc', 'settlement_proof', 'export', 'chargeback_proof'];

    public function up(): void
    {
        DB::statement('ALTER TABLE files DROP CONSTRAINT files_purpose_check');
        Pg::check('files', 'files_purpose_check', Pg::in('purpose', [...self::FILE_PURPOSES, 'return_proof']));

        Schema::create('payout_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->foreignUuid('transaction_id')->constrained();
            $table->foreignUuid('partner_id')->constrained();
            $table->foreignUuid('branch_id')->constrained();
            // Set by the branch when it confirms (what actually came back).
            $table->bigInteger('amount')->nullable();
            $table->string('utr', 50)->nullable();
            // When the money came back per the bank (the booking is on decided_at).
            $table->timestampTz('returned_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignUuid('proof_file_id')->nullable()->constrained('files');
            $table->string('status', 10)->default('requested');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['branch_id', 'status']);
            $table->index(['partner_id', 'status']);
        });

        Pg::check('payout_returns', 'payout_returns_status_check', Pg::in('status', ['requested', 'approved', 'rejected']));
        Pg::check('payout_returns', 'payout_returns_decided_check', "(status = 'requested') = (decided_at IS NULL)");
        Pg::check('payout_returns', 'payout_returns_approved_check', "status <> 'approved' OR (amount > 0 AND utr IS NOT NULL AND returned_at IS NOT NULL AND proof_file_id IS NOT NULL AND journal_id IS NOT NULL)");
        // One open or confirmed return per payout.
        DB::statement("CREATE UNIQUE INDEX payout_returns_one_per_transaction ON payout_returns (transaction_id) WHERE status IN ('requested', 'approved')");

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('return_status', 10)->nullable();
        });

        Pg::check('transactions', 'transactions_return_status_check', "return_status IS NULL OR (direction = 'payout' AND ".Pg::in('return_status', ['requested', 'approved', 'rejected']).')');
        DB::statement('CREATE INDEX transactions_return ON transactions (return_status) WHERE return_status IS NOT NULL');

        DB::statement('ALTER TABLE ledger_journals DROP CONSTRAINT ledger_journals_type_check');
        Pg::check('ledger_journals', 'ledger_journals_type_check', Pg::in('type', [...self::JOURNAL_TYPES, 'payout_return']));

        foreach (['settlements', 'settlement_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->bigInteger('returns_total')->default(0)->after('chargebacks_total');
            });
        }
    }

    public function down(): void
    {
        foreach (['settlements', 'settlement_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('returns_total');
            });
        }

        DB::statement('ALTER TABLE ledger_journals DROP CONSTRAINT ledger_journals_type_check');
        Pg::check('ledger_journals', 'ledger_journals_type_check', Pg::in('type', self::JOURNAL_TYPES));

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('return_status');
        });

        Schema::dropIfExists('payout_returns');

        DB::table('files')->where('purpose', 'return_proof')->delete();
        DB::statement('ALTER TABLE files DROP CONSTRAINT files_purpose_check');
        Pg::check('files', 'files_purpose_check', Pg::in('purpose', self::FILE_PURPOSES));
    }
};
