<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client request 2026-11-23: trace which server and key each Partner API
 * call used. host = the address it was sent to; key_id_sent = the X-Key-Id
 * header as sent (public id, never the secret), also when it was refused;
 * error_code = the API error code of a refused call (e.g. wrong_environment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->string('host')->nullable()->after('path');
            $table->string('key_id_sent', 64)->nullable()->after('api_key_id');
            $table->string('error_code', 50)->nullable()->after('status_code');

            $table->index(['error_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->dropIndex(['error_code', 'created_at']);
            $table->dropColumn(['host', 'key_id_sent', 'error_code']);
        });
    }
};
