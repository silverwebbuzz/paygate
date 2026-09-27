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
        Schema::create('statement_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained();
            $table->foreignUuid('payment_account_id')->constrained();
            $table->string('source', 20);
            $table->foreignUuid('file_id')->nullable()->constrained('files');
            $table->string('file_sha256', 64)->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('status', 20)->default('processing');
            $table->integer('rows_total')->default(0);
            $table->integer('rows_imported')->default(0);
            $table->integer('rows_duplicate')->default(0);
            $table->integer('rows_failed')->default(0);
            $table->bigInteger('credit_total')->default(0);
            $table->bigInteger('debit_total')->default(0);
            $table->jsonb('errors')->nullable();
            $table->foreignUuid('imported_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();

            // The same statement file can't be imported twice for an account.
            $table->unique(['payment_account_id', 'file_sha256']);
            $table->index(['branch_id', 'created_at']);
        });

        Pg::check('statement_imports', 'statement_imports_source_check', Pg::in('source', ['upload', 'manual_entry', 'auto']));
        Pg::check('statement_imports', 'statement_imports_status_check', Pg::in('status', ['processing', 'completed', 'failed']));

        // One bank statement line. Separate from transactions; linked on match.
        Schema::create('statement_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_id')->nullable()->constrained('statement_imports');
            $table->foreignUuid('branch_id')->constrained();
            $table->foreignUuid('payment_account_id')->constrained();
            $table->date('value_date');
            $table->timestampTz('posted_at')->nullable();
            $table->string('entry_direction', 10);
            $table->bigInteger('amount');
            $table->string('utr', 50)->nullable();
            $table->string('utr_normalized', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('row_hash', 64);
            $table->string('status', 20)->default('imported');
            $table->foreignUuid('transaction_id')->nullable()->constrained();
            $table->timestampTz('matched_at')->nullable();
            $table->foreignUuid('matched_by')->nullable()->constrained('users');
            $table->jsonb('raw')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // Stops the same bank line being stored twice (re-imports, overlapping statements).
            $table->unique(['payment_account_id', 'row_hash']);
            $table->index(['payment_account_id', 'utr_normalized']);
            $table->index(['branch_id', 'status', 'value_date']);
        });

        Pg::check('statement_entries', 'statement_entries_direction_check', Pg::in('entry_direction', ['credit', 'debit']));
        Pg::check('statement_entries', 'statement_entries_status_check', Pg::in('status', ['imported', 'matched', 'unmatched', 'duplicate', 'ignored', 'reconciled']));
        Pg::check('statement_entries', 'statement_entries_amount_positive', 'amount > 0');
        // A transaction is linked to at most one statement line.
        DB::statement('CREATE UNIQUE INDEX statement_entries_one_per_transaction ON statement_entries (transaction_id) WHERE transaction_id IS NOT NULL');

        // The unsettled-UTR queue: every mismatch becomes a case someone resolves.
        Schema::create('reconciliation_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->string('type', 40);
            $table->string('status', 20)->default('open');
            $table->string('resolution', 20)->nullable();
            $table->foreignUuid('branch_id')->constrained();
            $table->foreignUuid('transaction_id')->nullable()->constrained();
            $table->foreignUuid('statement_entry_id')->nullable()->constrained();
            $table->foreignUuid('assigned_to')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['status', 'type']);
        });

        Pg::check('reconciliation_cases', 'reconciliation_cases_type_check', Pg::in('type', ['utr_not_found', 'amount_mismatch', 'wrong_branch', 'duplicate_utr', 'entry_without_transaction', 'transaction_without_entry', 'entry_before_transaction', 'manual_review']));
        Pg::check('reconciliation_cases', 'reconciliation_cases_status_check', Pg::in('status', ['open', 'in_review', 'resolved', 'reopened']));
        Pg::check('reconciliation_cases', 'reconciliation_cases_resolution_check', 'resolution IS NULL OR '.Pg::in('resolution', ['linked', 'rejected', 'refunded', 'written_off', 'adjusted']));
        Pg::check('reconciliation_cases', 'reconciliation_cases_resolved_check', "(status = 'resolved') = (resolution IS NOT NULL AND resolved_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_cases');
        Schema::dropIfExists('statement_entries');
        Schema::dropIfExists('statement_imports');
    }
};
