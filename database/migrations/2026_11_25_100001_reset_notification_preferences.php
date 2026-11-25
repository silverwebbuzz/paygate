<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * users.notification_preferences used to say which alerts also came by
 * email; since 2026-11-25 (client request: no email in the system) it says
 * which alerts show in the bell. The old email choices are cleared, so
 * everyone starts with every alert on instead of losing bell alerts they
 * had only unticked for email.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotNull('notification_preferences')->update(['notification_preferences' => null]);
    }

    public function down(): void
    {
        // The old email choices are gone; nothing to restore.
    }
};
