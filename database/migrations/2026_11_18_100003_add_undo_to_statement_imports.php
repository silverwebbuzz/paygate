<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Undo import (client report 2026-11-18): Admin removes the lines of a wrong
 * import that nothing depends on. The import stays in Statement History,
 * marked as undone, with how many lines were removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statement_imports', function (Blueprint $table) {
            $table->timestampTz('undone_at')->nullable();
            $table->foreignUuid('undone_by')->nullable()->constrained('users');
            $table->integer('rows_removed')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('statement_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('undone_by');
            $table->dropColumn(['undone_at', 'rows_removed']);
        });
    }
};
