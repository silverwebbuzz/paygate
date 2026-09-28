<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11: report exports, prepared in the background (queue `reports`).
 * The file is a private `files` row (purpose `export`) that only the person
 * who asked can download; it is deleted after 7 days (decided 2026-09-28,
 * G-49), while the record of who exported what stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->string('report', 40);
            $table->string('format', 10);
            $table->jsonb('parameters');
            $table->string('status', 20)->default('queued');
            $table->integer('rows')->nullable();
            $table->foreignUuid('file_id')->nullable()->constrained('files');
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        Pg::check('report_exports', 'report_exports_format_check', Pg::in('format', ['csv', 'xlsx']));
        Pg::check('report_exports', 'report_exports_status_check', Pg::in('status', ['queued', 'running', 'ready', 'failed', 'expired']));
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
