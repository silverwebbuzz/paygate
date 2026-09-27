<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 (statements & reconciliation):
 *
 * - statement_templates: which column of a bank's statement file is the date,
 *   UTR, credit… (decided 2026-09-28, G-27: any CSV / Excel file with column
 *   mapping, remembered per bank). Found again by the file's header row.
 * - reconciliation case type `late_payment`: a bank credit for a deposit that
 *   already expired or was declined (G-25: Admin may approve it late).
 */
return new class extends Migration
{
    private const CASE_TYPES = ['utr_not_found', 'amount_mismatch', 'wrong_branch', 'duplicate_utr', 'entry_without_transaction', 'transaction_without_entry', 'entry_before_transaction', 'manual_review'];

    public function up(): void
    {
        Schema::create('statement_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            // SHA-256 of the normalised header row: the same bank layout finds its mapping.
            $table->string('header_signature', 64)->unique();
            $table->jsonb('mapping');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Schema::table('statement_imports', function (Blueprint $table) {
            $table->foreignUuid('template_id')->nullable()->constrained('statement_templates');
        });

        DB::statement('ALTER TABLE reconciliation_cases DROP CONSTRAINT reconciliation_cases_type_check');
        Pg::check('reconciliation_cases', 'reconciliation_cases_type_check', Pg::in('type', [...self::CASE_TYPES, 'late_payment']));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reconciliation_cases DROP CONSTRAINT reconciliation_cases_type_check');
        Pg::check('reconciliation_cases', 'reconciliation_cases_type_check', Pg::in('type', self::CASE_TYPES));

        Schema::table('statement_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
        });

        Schema::dropIfExists('statement_templates');
    }
};
