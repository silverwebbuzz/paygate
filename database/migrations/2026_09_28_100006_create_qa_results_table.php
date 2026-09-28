<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin › QA Checklist (local and staging only): one row per checklist item
 * (app/Domain/Qa/Checklist.php) with the tester's manual result and the
 * latest automated test run. Rows appear the first time an item is marked
 * or run; the checklist itself lives in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_results', function (Blueprint $table) {
            $table->string('check_key', 80)->primary();
            $table->string('manual_status', 10)->nullable();
            $table->text('manual_note')->nullable();
            $table->foreignUuid('tested_by')->nullable()->constrained('users');
            $table->timestampTz('tested_at')->nullable();
            $table->string('auto_status', 10)->nullable();
            $table->string('auto_summary', 200)->nullable();
            $table->jsonb('auto_tests')->nullable();
            $table->text('auto_output')->nullable();
            $table->foreignUuid('auto_requested_by')->nullable()->constrained('users');
            $table->timestampTz('auto_requested_at')->nullable();
            $table->timestampTz('auto_finished_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        Pg::check('qa_results', 'qa_results_manual_status_check', Pg::in('manual_status', ['pass', 'fail']));
        Pg::check('qa_results', 'qa_results_auto_status_check', Pg::in('auto_status', ['queued', 'running', 'passed', 'failed', 'missing', 'error']));
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_results');
    }
};
