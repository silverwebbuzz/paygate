<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Double-entry ledger (Database.md §3). Sign convention for position accounts:
 * positive = the platform owes the party, negative = the party owes the platform.
 * Every journal's entries sum to zero, enforced by a deferred constraint trigger.
 */
return new class extends Migration
{
    private const PLATFORM_ACCOUNTS = ['platform_margin', 'platform_adjustments', 'settlement_clearing'];

    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 30);
            $table->foreignUuid('partner_id')->nullable()->constrained();
            $table->foreignUuid('branch_id')->nullable()->constrained();
            $table->timestampTz('created_at')->useCurrent();
        });

        Pg::check('ledger_accounts', 'ledger_accounts_kind_check', Pg::in('kind', ['partner_position', 'branch_position', ...self::PLATFORM_ACCOUNTS]));
        // Position accounts are per partner↔branch pair; platform accounts belong to no one.
        Pg::check('ledger_accounts', 'ledger_accounts_owner_check', "(kind IN ('partner_position', 'branch_position') AND partner_id IS NOT NULL AND branch_id IS NOT NULL) OR (kind NOT IN ('partner_position', 'branch_position') AND partner_id IS NULL AND branch_id IS NULL)");
        DB::statement('CREATE UNIQUE INDEX ledger_accounts_unique ON ledger_accounts (kind, partner_id, branch_id) NULLS NOT DISTINCT');

        Schema::create('ledger_journals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 30);
            $table->foreignUuid('transaction_id')->nullable()->constrained();
            $table->uuid('adjustment_id')->nullable();          // FK added with adjustments
            $table->uuid('settlement_payment_id')->nullable();  // FK added with settlement payments
            $table->uuid('reverses_journal_id')->nullable(); // self-reference, FK added below
            $table->text('description')->nullable();
            $table->timestampTz('posted_at')->useCurrent();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->string('request_id', 64)->nullable();

            $table->index('transaction_id');
            $table->index('posted_at');
        });

        DB::statement('ALTER TABLE ledger_journals ADD CONSTRAINT ledger_journals_reverses_fk FOREIGN KEY (reverses_journal_id) REFERENCES ledger_journals (id)');
        Pg::check('ledger_journals', 'ledger_journals_type_check', Pg::in('type', ['payin_success', 'payout_success', 'reversal', 'adjustment', 'settlement']));
        // A transaction can never be booked twice.
        DB::statement("CREATE UNIQUE INDEX ledger_journals_once_per_transaction ON ledger_journals (transaction_id, type) WHERE transaction_id IS NOT NULL AND type IN ('payin_success', 'payout_success')");

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journal_id')->constrained('ledger_journals');
            $table->foreignUuid('ledger_account_id')->constrained();
            $table->bigInteger('amount');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['ledger_account_id', 'created_at']);
            $table->index('journal_id');
        });

        Pg::check('ledger_entries', 'ledger_entries_amount_non_zero', 'amount <> 0');

        Pg::appendOnly('ledger_journals');
        Pg::appendOnly('ledger_entries');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_ledger_journal_balanced() RETURNS trigger AS $$
            DECLARE
                total numeric;
            BEGIN
                SELECT COALESCE(SUM(amount), 0) INTO total FROM ledger_entries WHERE journal_id = NEW.journal_id;
                IF total <> 0 THEN
                    RAISE EXCEPTION 'ledger journal % is unbalanced (entries sum to %)', NEW.journal_id, total;
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER ledger_entries_balanced
                AFTER INSERT ON ledger_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION assert_ledger_journal_balanced();
        SQL);

        // Current balance per account, updated in the same DB transaction as the entries.
        // `reserved` holds open payouts against a partner position.
        Schema::create('ledger_balances', function (Blueprint $table) {
            $table->foreignUuid('ledger_account_id')->primary()->constrained();
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('reserved')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });

        Pg::check('ledger_balances', 'ledger_balances_reserved_non_negative', 'reserved >= 0');

        foreach (self::PLATFORM_ACCOUNTS as $kind) {
            $id = (string) Str::uuid7();
            DB::table('ledger_accounts')->insert(['id' => $id, 'kind' => $kind]);
            DB::table('ledger_balances')->insert(['ledger_account_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_balances');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_balanced ON ledger_entries');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_journals');
        Schema::dropIfExists('ledger_accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_ledger_journal_balanced()');
    }
};
