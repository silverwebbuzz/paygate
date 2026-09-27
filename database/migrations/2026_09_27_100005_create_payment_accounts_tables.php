<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bank accounts and UPI IDs owned by a branch. Numbers are stored
        // encrypted; *_hash (HMAC) gives uniqueness, *_last4 is for display.
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained();
            $table->boolean('is_bank_enabled')->default(false);
            $table->boolean('is_upi_enabled')->default(false);
            $table->string('label');
            $table->string('bank_name')->nullable();
            $table->string('ifsc', 11)->nullable();
            $table->string('account_holder_name');
            $table->text('account_number_encrypted')->nullable();
            $table->string('account_number_hash', 64)->nullable();
            $table->string('account_number_last4', 4)->nullable();
            $table->text('upi_id_encrypted')->nullable();
            $table->string('upi_id_hash', 64)->nullable()->unique();
            $table->string('upi_id_last4', 4)->nullable();
            $table->string('upi_display_name')->nullable();
            $table->string('upi_code')->nullable();
            $table->boolean('is_qr_enabled')->default(false);
            $table->boolean('is_intent_enabled')->default(false);
            $table->string('status', 30)->default('new');
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->text('rejected_reason')->nullable();
            $table->bigInteger('min_amount')->nullable();
            $table->bigInteger('max_amount')->nullable();
            $table->bigInteger('daily_amount_limit')->nullable();
            $table->integer('daily_count_limit')->nullable();
            $table->smallInteger('max_open_sessions')->default(5);
            $table->timestampTz('last_allocated_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['account_number_hash', 'ifsc']);
            $table->index(['branch_id', 'status', 'last_allocated_at']);
        });

        Pg::check('payment_accounts', 'payment_accounts_status_check', Pg::in('status', ['new', 'verification_pending', 'verified', 'active', 'paused', 'disabled', 'rejected']));
        // An account is a bank account and/or a UPI ID; each enabled method needs its details.
        Pg::check('payment_accounts', 'payment_accounts_has_method', 'is_bank_enabled OR is_upi_enabled');
        Pg::check('payment_accounts', 'payment_accounts_bank_details', 'NOT is_bank_enabled OR (bank_name IS NOT NULL AND ifsc IS NOT NULL AND account_number_encrypted IS NOT NULL AND account_number_hash IS NOT NULL)');
        Pg::check('payment_accounts', 'payment_accounts_upi_details', 'NOT is_upi_enabled OR (upi_id_encrypted IS NOT NULL AND upi_id_hash IS NOT NULL)');
        Pg::check('payment_accounts', 'payment_accounts_upi_features', '(NOT is_qr_enabled AND NOT is_intent_enabled) OR is_upi_enabled');
        Pg::check('payment_accounts', 'payment_accounts_limits_positive', 'COALESCE(min_amount, 1) > 0 AND COALESCE(max_amount, 1) > 0 AND COALESCE(daily_amount_limit, 1) > 0 AND COALESCE(daily_count_limit, 1) > 0 AND max_open_sessions > 0');
        Pg::check('payment_accounts', 'payment_accounts_range', 'min_amount IS NULL OR max_amount IS NULL OR min_amount <= max_amount');
        Pg::check('payment_accounts', 'payment_accounts_active_is_verified', "status NOT IN ('verified', 'active', 'paused') OR verified_at IS NOT NULL");

        // Daily capacity per account / branch / partner / mapping. Updated with
        // conditional UPDATEs so limits can never be exceeded under concurrency.
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->string('scope_type', 20);
            $table->uuid('scope_id');
            $table->date('business_date');
            $table->string('direction', 20);
            $table->bigInteger('reserved_amount')->default(0);
            $table->integer('reserved_count')->default(0);
            $table->bigInteger('confirmed_amount')->default(0);
            $table->integer('confirmed_count')->default(0);
            $table->integer('open_sessions')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['scope_type', 'scope_id', 'business_date', 'direction']);
        });

        Pg::check('usage_counters', 'usage_counters_scope_check', Pg::in('scope_type', ['account', 'branch', 'partner', 'mapping']));
        Pg::check('usage_counters', 'usage_counters_direction_check', Pg::in('direction', ['deposit', 'withdrawal']));
        Pg::check('usage_counters', 'usage_counters_non_negative', 'reserved_amount >= 0 AND reserved_count >= 0 AND confirmed_amount >= 0 AND confirmed_count >= 0 AND open_sessions >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('payment_accounts');
    }
};
