<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('email');
            $table->text('description')->nullable();
            $table->string('website_url');
            $table->string('return_url')->nullable();
            $table->string('callback_url')->nullable();
            $table->string('payin_webhook_url')->nullable();
            $table->string('payout_webhook_url')->nullable();
            $table->string('api_version', 10)->default('v1');
            $table->string('status', 30)->default('draft')->index();
            $table->boolean('is_payin_enabled')->default(false);
            $table->boolean('is_payout_enabled')->default(false);
            $table->boolean('is_h2h_enabled')->default(false);
            $table->boolean('allow_upi')->default(true);
            $table->boolean('allow_qr')->default(true);
            $table->boolean('allow_bank_transfer')->default(true);
            $table->string('manual_payment_type', 20)->nullable(); // bank_details / intent / dynamic_qr
            $table->string('withdraw_url')->nullable();
            $table->string('payout_group', 100)->nullable();
            $table->boolean('is_auto_withdrawal')->default(false);
            $table->boolean('is_partial_withdrawal')->default(false);
            $table->string('payout_limit_type', 20)->default('daily_reset');
            $table->bigInteger('deposit_min_amount')->nullable();
            $table->bigInteger('deposit_max_amount')->nullable();
            $table->bigInteger('deposit_daily_limit')->nullable();
            $table->bigInteger('withdrawal_min_amount')->nullable();
            $table->bigInteger('withdrawal_max_amount')->nullable();
            $table->bigInteger('withdrawal_daily_limit')->nullable();
            $table->unsignedSmallInteger('session_ttl_minutes')->default(15);
            $table->string('theme', 30)->nullable();
            $table->uuid('logo_file_id')->nullable(); // FK added with the files table
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Pg::check('partners', 'partners_status_check', Pg::in('status', ['draft', 'pending_verification', 'active', 'suspended', 'offboarded', 'rejected']));
        Pg::check('partners', 'partners_amounts_positive', 'COALESCE(deposit_min_amount, 1) > 0 AND COALESCE(deposit_max_amount, 1) > 0 AND COALESCE(deposit_daily_limit, 1) > 0 AND COALESCE(withdrawal_min_amount, 1) > 0 AND COALESCE(withdrawal_max_amount, 1) > 0 AND COALESCE(withdrawal_daily_limit, 1) > 0');
        Pg::check('partners', 'partners_deposit_range', 'deposit_min_amount IS NULL OR deposit_max_amount IS NULL OR deposit_min_amount <= deposit_max_amount');
        Pg::check('partners', 'partners_withdrawal_range', 'withdrawal_min_amount IS NULL OR withdrawal_max_amount IS NULL OR withdrawal_min_amount <= withdrawal_max_amount');
        Pg::check('partners', 'partners_session_ttl', 'session_ttl_minutes BETWEEN 1 AND 1440');
        Pg::check('partners', 'partners_manual_payment_type_check', 'manual_payment_type IS NULL OR '.Pg::in('manual_payment_type', ['bank_details', 'intent', 'dynamic_qr']));
        Pg::check('partners', 'partners_payout_limit_type_check', Pg::in('payout_limit_type', ['daily_reset', 'topup']));

        Schema::create('partner_api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained();
            $table->string('key_id', 64)->unique();
            $table->text('secret_encrypted');
            $table->string('secret_last4', 4);
            $table->string('status', 20)->default('active');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        Pg::check('partner_api_keys', 'partner_api_keys_status_check', Pg::in('status', ['active', 'rotating', 'revoked']));
        DB::statement("CREATE UNIQUE INDEX partner_api_keys_one_active ON partner_api_keys (partner_id) WHERE status = 'active'");

        Schema::create('partner_ip_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained();
            $table->rawColumn('cidr', 'cidr');
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['partner_id', 'cidr']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_ip_rules');
        Schema::dropIfExists('partner_api_keys');
        Schema::dropIfExists('partners');
    }
};
