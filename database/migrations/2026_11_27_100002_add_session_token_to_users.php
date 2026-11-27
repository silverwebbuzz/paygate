<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One login per user at a time (client request 2026-11-27): each sign-in
 * stores a new random token on the user and in that browser's session. A
 * session whose token no longer matches was replaced by a newer sign-in and
 * is ended (EnsureSingleSession).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('session_token', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_token');
        });
    }
};
