<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin › Testing › Security Report (super admins only, every environment):
 *
 * - security_scans: each automated scan the server ran against its own
 *   public addresses (results and performance as JSON).
 * - security_report_entries: the super admin's status and note for each
 *   manual test, finding and client decision (keys from
 *   app/Domain/SecurityReport/Catalogue.php; rows appear when first marked).
 *
 * Testing records only; no business data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status', 10);
            $table->jsonb('target');
            $table->unsignedSmallInteger('passed')->default(0);
            $table->unsignedSmallInteger('failed')->default(0);
            $table->unsignedSmallInteger('warnings')->default(0);
            $table->jsonb('results')->nullable();
            $table->jsonb('performance')->nullable();
            $table->text('error')->nullable();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestampTz('requested_at');
            $table->timestampTz('finished_at')->nullable();
            $table->index('requested_at');
        });

        Pg::check('security_scans', 'security_scans_status_check', Pg::in('status', ['queued', 'running', 'done', 'error']));

        Schema::create('security_report_entries', function (Blueprint $table) {
            $table->string('entry_key', 80)->primary();
            $table->string('status', 12)->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestampTz('updated_at')->nullable();
        });

        Pg::check('security_report_entries', 'security_report_entries_status_check', Pg::in('status', [
            'pass', 'fail', 'na',                         // manual tests
            'open', 'fixed', 'verified', 'accepted',      // findings
            'pending', 'agreed', 'declined',              // client decisions
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('security_report_entries');
        Schema::dropIfExists('security_scans');
    }
};
