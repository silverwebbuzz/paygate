<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settlement = calculation + recording. Money moves outside the platform;
 * Admin ticks it as settled, which posts a `settlement` journal per pair.
 */
return new class extends Migration
{
    private const MONEY_COLUMNS = ['opening_balance', 'gross_payin', 'gross_payout', 'partner_commission', 'branch_commission', 'platform_margin', 'adjustments_total', 'closing_balance'];

    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->string('party_type', 10);
            $table->foreignUuid('partner_id')->nullable()->constrained();
            $table->foreignUuid('branch_id')->nullable()->constrained();
            $table->string('run_type', 20);
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            foreach (self::MONEY_COLUMNS as $column) {
                $table->bigInteger($column)->default(0);
            }
            $table->bigInteger('net_amount')->default(0);
            $table->string('direction', 20);
            $table->bigInteger('settled_amount')->default(0);
            $table->string('status', 20)->default('calculated');
            $table->foreignUuid('calculated_by')->nullable()->constrained('users');
            $table->timestampTz('calculated_at')->useCurrent();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'period_end']);
        });

        Pg::check('settlements', 'settlements_party_check', "(party_type = 'partner' AND partner_id IS NOT NULL AND branch_id IS NULL) OR (party_type = 'branch' AND branch_id IS NOT NULL AND partner_id IS NULL)");
        Pg::check('settlements', 'settlements_run_type_check', Pg::in('run_type', ['daily', 'on_demand']));
        Pg::check('settlements', 'settlements_direction_check', Pg::in('direction', ['party_to_platform', 'platform_to_party', 'none']));
        Pg::check('settlements', 'settlements_status_check', Pg::in('status', ['calculated', 'pending_review', 'approved', 'partially_settled', 'settled', 'disputed', 'cancelled']));
        Pg::check('settlements', 'settlements_period_check', 'period_start < period_end');
        Pg::check('settlements', 'settlements_amounts_check', 'net_amount >= 0 AND settled_amount >= 0 AND settled_amount <= net_amount');
        DB::statement("CREATE UNIQUE INDEX settlements_one_per_period ON settlements (party_type, partner_id, branch_id, period_start, period_end) NULLS NOT DISTINCT WHERE status <> 'cancelled'");

        // One line per partner↔branch pair inside a party's settlement.
        Schema::create('settlement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('settlement_id')->constrained();
            $table->foreignUuid('ledger_account_id')->constrained();
            foreach (self::MONEY_COLUMNS as $column) {
                $table->bigInteger($column)->default(0);
            }
            $table->bigInteger('net_amount')->default(0);
            $table->bigInteger('settled_amount')->default(0);

            $table->unique(['settlement_id', 'ledger_account_id']);
        });

        Pg::check('settlement_lines', 'settlement_lines_amounts_check', 'net_amount >= 0 AND settled_amount >= 0 AND settled_amount <= net_amount');

        // Admin's "tick as settled" (may be partial).
        Schema::create('settlement_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('settlement_id')->constrained();
            $table->foreignUuid('settlement_line_id')->nullable()->constrained();
            $table->bigInteger('amount');
            $table->string('direction', 20);
            $table->string('method', 20)->default('external');
            $table->string('external_reference', 100)->nullable();
            $table->date('paid_at');
            $table->foreignUuid('proof_file_id')->nullable()->constrained('files');
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        Pg::check('settlement_payments', 'settlement_payments_amount_positive', 'amount > 0');
        Pg::check('settlement_payments', 'settlement_payments_direction_check', Pg::in('direction', ['party_to_platform', 'platform_to_party']));

        Schema::create('adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->string('type', 20);
            $table->foreignUuid('partner_id')->constrained();
            $table->foreignUuid('branch_id')->constrained();
            $table->string('side', 10);
            $table->bigInteger('amount'); // signed, from the party's point of view
            $table->foreignUuid('transaction_id')->nullable()->constrained();
            $table->foreignUuid('case_id')->nullable()->constrained('reconciliation_cases');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });

        Pg::check('adjustments', 'adjustments_type_check', Pg::in('type', ['topup', 'correction', 'chargeback', 'refund', 'goodwill']));
        Pg::check('adjustments', 'adjustments_side_check', Pg::in('side', ['partner', 'branch']));
        Pg::check('adjustments', 'adjustments_status_check', Pg::in('status', ['pending', 'approved', 'rejected']));
        Pg::check('adjustments', 'adjustments_amount_non_zero', 'amount <> 0');

        // Links created now that all tables exist.
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_settled_line_fk FOREIGN KEY (settled_line_id) REFERENCES settlement_lines (id)');
        DB::statement('ALTER TABLE ledger_journals ADD CONSTRAINT ledger_journals_adjustment_fk FOREIGN KEY (adjustment_id) REFERENCES adjustments (id)');
        DB::statement('ALTER TABLE ledger_journals ADD CONSTRAINT ledger_journals_settlement_payment_fk FOREIGN KEY (settlement_payment_id) REFERENCES settlement_payments (id)');
        DB::statement('CREATE INDEX transactions_settled_line ON transactions (settled_line_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_journals DROP CONSTRAINT IF EXISTS ledger_journals_settlement_payment_fk');
        DB::statement('ALTER TABLE ledger_journals DROP CONSTRAINT IF EXISTS ledger_journals_adjustment_fk');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_settled_line_fk');
        DB::statement('DROP INDEX IF EXISTS transactions_settled_line');
        Schema::dropIfExists('adjustments');
        Schema::dropIfExists('settlement_payments');
        Schema::dropIfExists('settlement_lines');
        Schema::dropIfExists('settlements');
    }
};
