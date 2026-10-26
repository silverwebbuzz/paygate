<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * No UTR when raising a chargeback or confirming / recording a payout
 * return (decided 2026-10-26): the bank's notice or proof is the evidence.
 * The column stays for the UTRs already entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE chargebacks ALTER COLUMN utr DROP NOT NULL');
        DB::statement('ALTER TABLE payout_returns DROP CONSTRAINT payout_returns_approved_check');
        Pg::check('payout_returns', 'payout_returns_approved_check', "status <> 'approved' OR (amount > 0 AND returned_at IS NOT NULL AND journal_id IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payout_returns DROP CONSTRAINT payout_returns_approved_check');
        Pg::check('payout_returns', 'payout_returns_approved_check', "status <> 'approved' OR (amount > 0 AND utr IS NOT NULL AND returned_at IS NOT NULL AND journal_id IS NOT NULL)");
        DB::statement("UPDATE chargebacks SET utr = '' WHERE utr IS NULL");
        DB::statement('ALTER TABLE chargebacks ALTER COLUMN utr SET NOT NULL');
    }
};
