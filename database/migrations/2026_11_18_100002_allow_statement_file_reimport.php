<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statement import with line selection (client request 2026-11-18): a branch
 * ticks only the lines that belong in PayGate, and may upload the same file
 * again later to add lines it left out. Duplicates are refused line by line
 * (statement_entries' row hash and UTR), so the file itself no longer has
 * to be unique per account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statement_imports', function (Blueprint $table) {
            $table->dropUnique(['payment_account_id', 'file_sha256']);
            $table->index(['payment_account_id', 'file_sha256']);
        });
    }

    public function down(): void
    {
        Schema::table('statement_imports', function (Blueprint $table) {
            $table->dropIndex(['payment_account_id', 'file_sha256']);
            $table->unique(['payment_account_id', 'file_sha256']);
        });
    }
};
