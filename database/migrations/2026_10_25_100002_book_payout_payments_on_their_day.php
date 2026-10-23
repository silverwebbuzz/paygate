<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each transfer of a payout is booked on the day it is recorded (decided
 * 2026-10-23), so each day's settlement matches the branch's bank
 * statement: partner −amount, branch +amount; the transfer that completes
 * the payout also books the fees and the margin.
 *
 * - journal type `payout_part`: a transfer that doesn't complete the
 *   payout. The completing one stays `payout_success` (still once per
 *   payout) and also books the fees;
 * - payout_payments.journal_id: the transfer's journal;
 * - payout_payments.fees_booked: true on the transfer that booked the fees.
 *
 * Earlier payouts were booked in one journal with their one transfer.
 */
return new class extends Migration
{
    private const JOURNAL_TYPES = ['payin_success', 'payout_success', 'reversal', 'adjustment', 'settlement', 'chargeback', 'payout_return'];

    public function up(): void
    {
        DB::statement('ALTER TABLE ledger_journals DROP CONSTRAINT ledger_journals_type_check');
        Pg::check('ledger_journals', 'ledger_journals_type_check', Pg::in('type', [...self::JOURNAL_TYPES, 'payout_part']));

        Schema::table('payout_payments', function (Blueprint $table) {
            $table->uuid('journal_id')->nullable()->unique();
            $table->boolean('fees_booked')->default(false);
        });

        DB::statement(<<<'SQL'
            UPDATE payout_payments p SET journal_id = j.id, fees_booked = true
            FROM ledger_journals j
            WHERE j.transaction_id = p.transaction_id AND j.type = 'payout_success'
              AND (SELECT count(*) FROM payout_payments x WHERE x.transaction_id = p.transaction_id) = 1
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_journals DROP CONSTRAINT ledger_journals_type_check');
        Pg::check('ledger_journals', 'ledger_journals_type_check', Pg::in('type', self::JOURNAL_TYPES));

        Schema::table('payout_payments', function (Blueprint $table) {
            $table->dropColumn(['journal_id', 'fees_booked']);
        });
    }
};
