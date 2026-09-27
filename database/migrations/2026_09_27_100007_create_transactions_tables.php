<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PAYIN_STATUSES = ['created', 'awaiting_payment', 'payment_submitted', 'payment_detected', 'under_review', 'success', 'rejected', 'expired', 'cancelled', 'chargeback', 'refunded'];

    private const PAYOUT_STATUSES = ['created', 'validated', 'assigned', 'processing', 'success', 'failed', 'rejected', 'cancelled', 'returned'];

    public function up(): void
    {
        // Pay-ins and payouts share one table (Database.md D-1).
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->string('direction', 10);
            $table->string('origin', 10)->default('api');
            $table->foreignUuid('partner_id')->constrained();
            $table->string('partner_transaction_id', 100);
            $table->string('request_hash', 64)->nullable();
            $table->foreignUuid('partner_customer_id')->nullable()->constrained();
            $table->foreignUuid('branch_id')->nullable()->constrained();
            $table->foreignUuid('payment_account_id')->nullable()->constrained();
            $table->string('method', 20)->nullable();
            $table->bigInteger('amount');
            $table->bigInteger('received_amount')->nullable();
            $table->char('currency', 3)->default('INR');
            $table->string('status', 30);
            $table->string('status_reason_code', 50)->nullable();
            $table->text('status_note')->nullable();
            $table->string('utr', 50)->nullable();
            $table->string('utr_normalized', 50)->nullable()->index();
            $table->decimal('partner_rate_percent', 7, 4)->nullable();
            $table->decimal('branch_rate_percent', 7, 4)->nullable();
            $table->bigInteger('partner_commission')->nullable();
            $table->bigInteger('branch_commission')->nullable();
            $table->bigInteger('platform_margin')->nullable();
            $table->foreignUuid('partner_rate_id')->nullable()->constrained('commission_rates');
            $table->foreignUuid('branch_rate_id')->nullable()->constrained('commission_rates');
            $table->string('return_url')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestampTz('succeeded_at')->nullable();
            $table->uuid('settled_line_id')->nullable(); // FK added with settlement tables
            $table->timestampsTz();

            // Idempotency: the partner's own transaction ID is unique per partner and direction.
            $table->unique(['partner_id', 'direction', 'partner_transaction_id']);
            $table->index(['partner_id', 'direction', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });

        Pg::check('transactions', 'transactions_direction_check', Pg::in('direction', ['payin', 'payout']));
        Pg::check('transactions', 'transactions_origin_check', Pg::in('origin', ['api', 'admin']));
        Pg::check('transactions', 'transactions_status_check', '(direction = \'payin\' AND '.Pg::in('status', self::PAYIN_STATUSES).') OR (direction = \'payout\' AND '.Pg::in('status', self::PAYOUT_STATUSES).')');
        Pg::check('transactions', 'transactions_method_check', 'method IS NULL OR '.Pg::in('method', ['upi', 'qr', 'upi_intent', 'bank_transfer']));
        Pg::check('transactions', 'transactions_amount_positive', 'amount > 0 AND COALESCE(received_amount, 1) > 0');
        Pg::check('transactions', 'transactions_commission_non_negative', 'COALESCE(partner_commission, 0) >= 0 AND COALESCE(branch_commission, 0) >= 0');
        Pg::check('transactions', 'transactions_success_has_commission', "status <> 'success' OR (partner_commission IS NOT NULL AND branch_commission IS NOT NULL AND platform_margin IS NOT NULL AND branch_id IS NOT NULL AND succeeded_at IS NOT NULL)");

        // Branch approval queue.
        DB::statement("CREATE INDEX transactions_branch_queue ON transactions (branch_id, status, created_at) WHERE status IN ('payment_submitted', 'under_review', 'assigned', 'processing')");
        // Expiry sweep.
        DB::statement("CREATE INDEX transactions_expiry ON transactions (expires_at) WHERE status IN ('created', 'awaiting_payment')");
        // The same UTR can't be claimed twice on one receiving account (unless the earlier claim failed).
        DB::statement("CREATE UNIQUE INDEX transactions_unique_payin_utr ON transactions (payment_account_id, utr_normalized) WHERE direction = 'payin' AND utr_normalized IS NOT NULL AND status NOT IN ('rejected', 'expired', 'cancelled')");

        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->unique()->constrained();
            $table->string('token_hash', 64)->unique(); // SHA-256 of the URL token; the token itself is never stored
            $table->string('status', 20)->default('issued');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('method_selected_at')->nullable();
            $table->ipAddress('client_ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Pg::check('payment_sessions', 'payment_sessions_status_check', Pg::in('status', ['issued', 'opened', 'completed', 'expired']));

        Schema::create('payout_beneficiaries', function (Blueprint $table) {
            $table->foreignUuid('transaction_id')->primary()->constrained();
            $table->string('type', 10)->default('bank');
            $table->string('account_holder_name');
            $table->text('account_number_encrypted')->nullable();
            $table->string('account_number_last4', 4)->nullable();
            $table->string('ifsc', 11)->nullable();
            $table->string('bank_name')->nullable();
            $table->text('upi_id_encrypted')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
        });

        Pg::check('payout_beneficiaries', 'payout_beneficiaries_type_check', Pg::in('type', ['bank', 'upi']));
        Pg::check('payout_beneficiaries', 'payout_beneficiaries_details_check', "(type = 'bank' AND account_number_encrypted IS NOT NULL AND ifsc IS NOT NULL) OR (type = 'upi' AND upi_id_encrypted IS NOT NULL)");

        // The transaction timeline: every state change, who did it and why. Append-only.
        Schema::create('transaction_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('event', 50);
            $table->string('actor_type', 20);
            $table->uuid('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('data')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['transaction_id', 'created_at']);
        });

        Pg::check('transaction_events', 'transaction_events_actor_check', Pg::in('actor_type', ['system', 'user', 'partner_api', 'customer']));
        Pg::appendOnly('transaction_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_events');
        Schema::dropIfExists('payout_beneficiaries');
        Schema::dropIfExists('payment_sessions');
        Schema::dropIfExists('transactions');
    }
};
