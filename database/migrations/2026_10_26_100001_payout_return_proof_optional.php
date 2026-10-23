<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The bank's proof of a payout return is optional when the branch confirms
 * it (decided 2026-10-23): the branch confirms money came back to its own
 * account, against its own balance, so its word is enough. An admin
 * confirming for the branch must still attach it (ManagePayoutReturn). The
 * UTR and date stay required.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payout_returns DROP CONSTRAINT payout_returns_approved_check');
        Pg::check('payout_returns', 'payout_returns_approved_check', "status <> 'approved' OR (amount > 0 AND utr IS NOT NULL AND returned_at IS NOT NULL AND journal_id IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payout_returns DROP CONSTRAINT payout_returns_approved_check');
        Pg::check('payout_returns', 'payout_returns_approved_check', "status <> 'approved' OR (amount > 0 AND utr IS NOT NULL AND returned_at IS NOT NULL AND proof_file_id IS NOT NULL AND journal_id IS NOT NULL)");
    }
};
