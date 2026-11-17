<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Client request 2026-11-17: a wrong bank account (the deposit is on another
 * account of the same branch) is a different thing from a wrong branch.
 * Adds the case type `wrong_account` and moves the existing same-branch
 * "wrong branch" cases to it.
 */
return new class extends Migration
{
    private const CASE_TYPES = ['utr_not_found', 'amount_mismatch', 'wrong_branch', 'duplicate_utr', 'entry_without_transaction', 'transaction_without_entry', 'entry_before_transaction', 'manual_review', 'late_payment'];

    public function up(): void
    {
        DB::statement('ALTER TABLE reconciliation_cases DROP CONSTRAINT reconciliation_cases_type_check');
        Pg::check('reconciliation_cases', 'reconciliation_cases_type_check', Pg::in('type', [...self::CASE_TYPES, 'wrong_account']));

        DB::statement("UPDATE reconciliation_cases AS c SET type = 'wrong_account'
            FROM transactions AS t
            WHERE c.type = 'wrong_branch' AND c.transaction_id = t.id AND t.branch_id = c.branch_id");
    }

    public function down(): void
    {
        DB::statement("UPDATE reconciliation_cases SET type = 'wrong_branch' WHERE type = 'wrong_account'");
        DB::statement('ALTER TABLE reconciliation_cases DROP CONSTRAINT reconciliation_cases_type_check');
        Pg::check('reconciliation_cases', 'reconciliation_cases_type_check', Pg::in('type', self::CASE_TYPES));
    }
};
