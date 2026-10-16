<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // An approved deposit at a top-up branch is now written to the
        // allowance history too (a negative row pointing at the pay-in), so the
        // Top-ups tab reads as a running statement. One row per pay-in at most.
        Schema::table('branch_limit_topups', function (Blueprint $table) {
            $table->foreignUuid('transaction_id')->nullable()->unique()->after('branch_id')->constrained('transactions');
        });

        $this->backfillApprovedDeposits();
    }

    public function down(): void
    {
        Schema::table('branch_limit_topups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_id');
        });
    }

    /**
     * Writes the deposits approved before this change into the history, in
     * time order between the Admin top-ups, replaying the allowance the way
     * DecidePayin used it (never below zero; nothing used = no row).
     */
    private function backfillApprovedDeposits(): void
    {
        $branches = DB::table('branches')->where('deposit_limit_type', 'topup')->pluck('id');

        foreach ($branches as $branchId) {
            $topups = DB::table('branch_limit_topups')
                ->where('branch_id', $branchId)
                ->get(['amount', 'balance_after', 'created_by', 'created_at'])
                ->map(fn ($row) => ['at' => strtotime($row->created_at), 'topup' => $row]);

            if ($topups->isEmpty()) {
                continue;
            }

            $deposits = DB::table('transactions')
                ->where(['branch_id' => $branchId, 'direction' => 'payin', 'status' => 'success'])
                ->get(['id', 'reference', 'amount', 'decided_by', 'succeeded_at'])
                ->map(fn ($row) => ['at' => strtotime($row->succeeded_at), 'payin' => $row]);

            $fallbackActor = $topups->first()['topup']->created_by;
            $balance = 0;

            foreach ($topups->concat($deposits)->sortBy([['at', 'asc']]) as $event) {
                if (isset($event['topup'])) {
                    $balance = (int) $event['topup']->balance_after;

                    continue;
                }

                $payin = $event['payin'];
                $after = max($balance - (int) $payin->amount, 0);

                if ($after === $balance) {
                    continue;
                }

                DB::table('branch_limit_topups')->insert([
                    'id' => (string) Str::uuid(),
                    'branch_id' => $branchId,
                    'transaction_id' => $payin->id,
                    'amount' => $after - $balance,
                    'balance_after' => $after,
                    'reason' => "Deposit {$payin->reference} approved",
                    'created_by' => $payin->decided_by ?? $fallbackActor,
                    'created_at' => $payin->succeeded_at,
                ]);

                $balance = $after;
            }
        }
    }
};
