<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Since 2026-11-27 (client request) the QR code is no longer a separate
 * switch on an account: every account with UPI also offers the QR code
 * (the QR carries the UPI ID and the exact amount). Existing accounts are
 * brought in line.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_accounts')
            ->whereColumn('is_qr_enabled', '<>', 'is_upi_enabled')
            ->update(['is_qr_enabled' => DB::raw('is_upi_enabled')]);
    }

    public function down(): void
    {
        // The old per-account choices are not kept; nothing to restore.
    }
};
