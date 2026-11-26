<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * No email anywhere in the system (client request 2026-11-25/26): the
 * platform talks to partners and branches on other channels, so it neither
 * stores nor sends email. This drops every email column that is left:
 * users (and the reset-link / verification columns that went with it),
 * partners, the partner's customers, payout beneficiaries and the payment
 * page support contact. security_logs kept the name typed at sign-in under
 * "email"; it is a username now, so the column becomes "login" (the table is
 * append-only, so old rows keep what they had).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['email', 'email_verified_at']);
        });

        Schema::dropIfExists('password_reset_tokens');

        foreach (['partners', 'partner_customers', 'payout_beneficiaries'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('email'));
        }

        Schema::table('security_logs', fn (Blueprint $table) => $table->renameColumn('email', 'login'));

        DB::table('settings')->where('key', 'checkout.support')->update(['value' => DB::raw("value - 'email'")]);
    }

    public function down(): void
    {
        // The addresses themselves are gone; this only brings the columns back.
        Schema::table('security_logs', fn (Blueprint $table) => $table->renameColumn('login', 'email'));

        foreach (['partners', 'partner_customers', 'payout_beneficiaries'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->string('email')->nullable());
        }

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
        });
    }
};
