<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Outbox: written in the same DB transaction as the state change, then
        // delivered by a queue worker. Nothing is lost if Redis or a worker restarts.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained();
            $table->foreignUuid('transaction_id')->nullable()->constrained();
            $table->string('event_type', 50);
            $table->string('url');
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending');
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['status', 'next_attempt_at']);
            $table->index('transaction_id');
            $table->index(['partner_id', 'created_at']);
        });

        Pg::check('webhook_events', 'webhook_events_status_check', Pg::in('status', ['pending', 'retrying', 'delivered', 'failed']));

        Schema::create('webhook_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_event_id')->constrained();
            $table->smallInteger('attempt_no');
            $table->smallInteger('response_status')->nullable();
            $table->text('response_body')->nullable(); // first 2 KB
            $table->integer('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['webhook_event_id', 'attempt_no']);
        });

        Pg::appendOnly('webhook_attempts');

        // Partner-visible API log (90-day retention; partition monthly when volume requires).
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->nullable()->constrained();
            $table->foreignUuid('api_key_id')->nullable()->constrained('partner_api_keys');
            $table->string('method', 10);
            $table->string('path');
            $table->smallInteger('status_code');
            $table->integer('duration_ms');
            $table->ipAddress('ip')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('partner_transaction_id', 100)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['partner_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('webhook_attempts');
        Schema::dropIfExists('webhook_events');
    }
};
