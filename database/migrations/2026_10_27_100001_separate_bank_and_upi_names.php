<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The bank account's holder name and the UPI name can differ (decided
 * 2026-10-27): `account_holder_name` is the bank side's name (needed only
 * when bank transfer is on), `upi_display_name` the UPI side's (needed when
 * UPI is on). UPI accounts without a UPI name took the holder's name until
 * now, so they keep showing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_accounts ALTER COLUMN account_holder_name DROP NOT NULL');
        DB::statement("UPDATE payment_accounts SET upi_display_name = account_holder_name WHERE is_upi_enabled AND (upi_display_name IS NULL OR trim(upi_display_name) = '')");
    }

    public function down(): void
    {
        DB::statement("UPDATE payment_accounts SET account_holder_name = COALESCE(upi_display_name, label) WHERE account_holder_name IS NULL");
        DB::statement('ALTER TABLE payment_accounts ALTER COLUMN account_holder_name SET NOT NULL');
    }
};
