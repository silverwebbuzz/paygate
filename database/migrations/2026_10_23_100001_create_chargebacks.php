<?php

use App\Domain\Core\Rbac\SystemRoles;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chargebacks as a request and an approval (decided 2026-10-21):
 *
 * - chargebacks: raised by the branch (or Admin) on a successful deposit,
 *   with the bank's notice (image / PDF) and the transaction's UTR; Admin
 *   approves or rejects. Only an approved one is booked (journal type
 *   `chargeback`, on the approval date). A rejected one can be raised again.
 * - transactions.chargeback_status: requested / approved / rejected next to
 *   the status, which stays `success` (the payment did succeed).
 * - settlements / settlement_lines.chargebacks_total: shown on its own line.
 * - files.purpose chargeback_proof: the bank's notice.
 * - permissions: reversals.approve (Admin); branches may view and raise.
 */
return new class extends Migration
{
    private const JOURNAL_TYPES = ['payin_success', 'payout_success', 'reversal', 'adjustment', 'settlement'];

    private const FILE_PURPOSES = ['payment_proof', 'logo', 'statement', 'kyc', 'settlement_proof', 'export'];

    public function up(): void
    {
        DB::statement('ALTER TABLE files DROP CONSTRAINT files_purpose_check');
        Pg::check('files', 'files_purpose_check', Pg::in('purpose', [...self::FILE_PURPOSES, 'chargeback_proof']));

        Schema::create('chargebacks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->foreignUuid('transaction_id')->constrained();
            $table->foreignUuid('partner_id')->constrained();
            $table->foreignUuid('branch_id')->constrained();
            $table->bigInteger('amount');
            $table->string('utr', 50);
            $table->text('reason')->nullable();
            $table->foreignUuid('proof_file_id')->nullable()->constrained('files');
            $table->string('status', 10)->default('requested');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['branch_id', 'status']);
            $table->index(['partner_id', 'status']);
        });

        Pg::check('chargebacks', 'chargebacks_status_check', Pg::in('status', ['requested', 'approved', 'rejected']));
        Pg::check('chargebacks', 'chargebacks_amount_positive', 'amount > 0');
        Pg::check('chargebacks', 'chargebacks_decided_check', "(status = 'requested') = (decided_at IS NULL)");
        // One open or approved chargeback per transaction.
        DB::statement("CREATE UNIQUE INDEX chargebacks_one_per_transaction ON chargebacks (transaction_id) WHERE status IN ('requested', 'approved')");

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('chargeback_status', 10)->nullable();
        });

        Pg::check('transactions', 'transactions_chargeback_status_check', "chargeback_status IS NULL OR (direction = 'payin' AND ".Pg::in('chargeback_status', ['requested', 'approved', 'rejected']).')');
        DB::statement('CREATE INDEX transactions_chargeback ON transactions (chargeback_status) WHERE chargeback_status IS NOT NULL');

        DB::statement('ALTER TABLE ledger_journals DROP CONSTRAINT ledger_journals_type_check');
        Pg::check('ledger_journals', 'ledger_journals_type_check', Pg::in('type', [...self::JOURNAL_TYPES, 'chargeback']));

        foreach (['settlements', 'settlement_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->bigInteger('chargebacks_total')->default(0)->after('adjustments_total');
            });
        }

        $grants = [
            SystemRoles::ADMIN_FULL => ['reversals.approve'],
            SystemRoles::ADMIN_OPS => ['reversals.approve'],
            SystemRoles::ADMIN_FINANCE => ['reversals.approve'],
            SystemRoles::BRANCH_OWNER => ['reversals.view', 'reversals.create'],
            SystemRoles::BRANCH_OPERATOR => ['reversals.view', 'reversals.create'],
        ];

        foreach ($grants as $slug => $permissions) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');

            if ($roleId !== null) {
                DB::table('role_permissions')->insertOrIgnore(array_map(fn (string $permission) => ['role_id' => $roleId, 'permission' => $permission], $permissions));
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'reversals.approve')->delete();
        DB::table('role_permissions')
            ->whereIn('permission', ['reversals.view', 'reversals.create'])
            ->whereIn('role_id', DB::table('roles')->where('user_type', 'branch')->select('id'))
            ->delete();

        foreach (['settlements', 'settlement_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('chargebacks_total');
            });
        }

        DB::statement('ALTER TABLE ledger_journals DROP CONSTRAINT ledger_journals_type_check');
        Pg::check('ledger_journals', 'ledger_journals_type_check', Pg::in('type', self::JOURNAL_TYPES));

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('chargeback_status');
        });

        Schema::dropIfExists('chargebacks');

        DB::table('files')->where('purpose', 'chargeback_proof')->delete();
        DB::statement('ALTER TABLE files DROP CONSTRAINT files_purpose_check');
        Pg::check('files', 'files_purpose_check', Pg::in('purpose', self::FILE_PURPOSES));
    }
};
