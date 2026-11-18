<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Client request 2026-11-18: deposits and withdrawals have no "cancelled"
 * status any more (the partner API's cancel calls are removed). Existing
 * ones become what they amount to: a cancelled deposit was never paid
 * (expired); a cancelled withdrawal was never paid out (failed, its hold
 * already released). status_reason_code keeps "cancelled", so down() can
 * restore them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE transactions SET status = 'expired', status_reason_code = 'cancelled' WHERE direction = 'payin' AND status = 'cancelled'");
        DB::statement("UPDATE transactions SET status = 'failed', status_reason_code = 'cancelled' WHERE direction = 'payout' AND status = 'cancelled'");
    }

    public function down(): void
    {
        DB::statement("UPDATE transactions SET status = 'cancelled' WHERE direction = 'payin' AND status = 'expired' AND status_reason_code = 'cancelled'");
        DB::statement("UPDATE transactions SET status = 'cancelled' WHERE direction = 'payout' AND status = 'failed' AND status_reason_code = 'cancelled'");
    }
};
