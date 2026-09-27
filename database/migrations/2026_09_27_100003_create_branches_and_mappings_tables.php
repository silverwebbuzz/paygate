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
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('status', 30)->default('draft')->index();
            $table->boolean('is_deposit_enabled')->default(true);
            $table->boolean('is_withdrawal_enabled')->default(false);
            $table->string('limit_type', 30)->nullable();
            $table->bigInteger('deposit_min_amount')->nullable();
            $table->bigInteger('deposit_max_amount')->nullable();
            $table->bigInteger('deposit_daily_limit')->nullable();
            $table->bigInteger('withdrawal_min_amount')->nullable();
            $table->bigInteger('withdrawal_max_amount')->nullable();
            $table->bigInteger('withdrawal_daily_limit')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Pg::check('branches', 'branches_status_check', Pg::in('status', ['draft', 'pending_verification', 'active', 'suspended', 'offboarded', 'rejected']));
        Pg::check('branches', 'branches_amounts_positive', 'COALESCE(deposit_min_amount, 1) > 0 AND COALESCE(deposit_max_amount, 1) > 0 AND COALESCE(deposit_daily_limit, 1) > 0 AND COALESCE(withdrawal_min_amount, 1) > 0 AND COALESCE(withdrawal_max_amount, 1) > 0 AND COALESCE(withdrawal_daily_limit, 1) > 0');
        Pg::check('branches', 'branches_deposit_range', 'deposit_min_amount IS NULL OR deposit_max_amount IS NULL OR deposit_min_amount <= deposit_max_amount');
        Pg::check('branches', 'branches_withdrawal_range', 'withdrawal_min_amount IS NULL OR withdrawal_max_amount IS NULL OR withdrawal_min_amount <= withdrawal_max_amount');

        Schema::create('partner_branch_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained();
            $table->foreignUuid('branch_id')->constrained();
            $table->string('status', 20)->default('active');
            $table->boolean('is_deposit_enabled')->default(true);
            $table->boolean('is_withdrawal_enabled')->default(true);
            $table->smallInteger('priority')->default(100);
            $table->bigInteger('deposit_daily_limit')->nullable();
            $table->bigInteger('withdrawal_daily_limit')->nullable();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->timestampTz('last_payout_assigned_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['partner_id', 'branch_id']);
            $table->index(['partner_id', 'status']);
            $table->index(['branch_id', 'status']);
        });

        Pg::check('partner_branch_mappings', 'mappings_status_check', Pg::in('status', ['active', 'inactive']));
        Pg::check('partner_branch_mappings', 'mappings_limits_positive', 'COALESCE(deposit_daily_limit, 1) > 0 AND COALESCE(withdrawal_daily_limit, 1) > 0');
        Pg::check('partner_branch_mappings', 'mappings_effective_range', 'effective_from IS NULL OR effective_to IS NULL OR effective_from < effective_to');

        // Effective-dated rates, negotiated offline and entered by Admin. Rows are
        // never edited: a new row (with effective_from) supersedes the old one.
        Schema::create('commission_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_type', 20);
            $table->uuid('subject_id');
            $table->string('side', 20);
            $table->string('direction', 20);
            $table->string('fee_type', 20)->default('percent');
            $table->decimal('rate_percent', 7, 4);
            $table->jsonb('config')->nullable();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'side', 'direction', 'effective_from']);
        });

        Pg::check('commission_rates', 'commission_rates_subject_check', Pg::in('subject_type', ['partner', 'branch', 'mapping']));
        Pg::check('commission_rates', 'commission_rates_side_check', "(subject_type = 'partner' AND side = 'partner') OR (subject_type = 'branch' AND side = 'branch') OR (subject_type = 'mapping' AND side IN ('partner', 'branch'))");
        Pg::check('commission_rates', 'commission_rates_direction_check', Pg::in('direction', ['deposit', 'withdrawal']));
        Pg::check('commission_rates', 'commission_rates_fee_type_check', Pg::in('fee_type', ['percent', 'fixed', 'slab']));
        Pg::check('commission_rates', 'commission_rates_rate_range', 'rate_percent BETWEEN 0 AND 100');
        Pg::check('commission_rates', 'commission_rates_effective_range', 'effective_to IS NULL OR effective_from < effective_to');
        DB::statement('ALTER TABLE commission_rates ADD CONSTRAINT commission_rates_no_overlap EXCLUDE USING gist (subject_type WITH =, subject_id WITH =, side WITH =, direction WITH =, tstzrange(effective_from, effective_to) WITH &&)');
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rates');
        Schema::dropIfExists('partner_branch_mappings');
        Schema::dropIfExists('branches');
    }
};
