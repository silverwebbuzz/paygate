<?php

namespace Tests\Feature\Database;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Models\Partner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The database's own safety rules (Database.md §5). Each test tries to break
 * a rule and expects PostgreSQL to refuse, independent of application code.
 */
class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_data_is_created_by_the_migrations()
    {
        $this->assertSame(9, DB::table('roles')->where('is_system', true)->count());
        $this->assertSame(3, DB::table('ledger_accounts')->whereNull('partner_id')->count());
        $this->assertTrue(DB::table('reason_codes')->where(['context' => 'payin_reject', 'code' => 'invalid_utr'])->exists());
    }

    public function test_partner_and_branch_users_must_belong_to_their_organisation()
    {
        $this->assertRejected(fn () => User::factory()->create([
            'type' => 'partner',
            'role_id' => Role::bySlug(SystemRoles::PARTNER_OWNER)->id,
            'partner_id' => null,
        ]), 'users_organisation_check');
    }

    public function test_a_user_cannot_hold_a_role_from_another_portal()
    {
        $this->assertRejected(fn () => User::factory()->create([
            'type' => 'partner',
            'role_id' => Role::bySlug(SystemRoles::ADMIN_SUPER)->id,
        ]), 'users_role_fk');
    }

    public function test_commission_rate_periods_cannot_overlap()
    {
        $partner = Partner::factory()->create();
        $this->rate($partner->id, '2026-01-01', '2026-07-01', '6.0000');
        $this->rate($partner->id, '2026-07-01', null, '5.5000'); // starts exactly when the first ends: fine

        $this->assertRejected(fn () => $this->rate($partner->id, '2026-06-01', null, '5.0000'), 'commission_rates_no_overlap');
    }

    public function test_partner_transaction_id_is_unique_per_partner_and_direction()
    {
        $partner = Partner::factory()->create();
        $this->transaction($partner->id, ['partner_transaction_id' => 'ORDER-1']);
        $this->transaction($partner->id, ['partner_transaction_id' => 'ORDER-1', 'direction' => 'payout', 'status' => 'created']);

        $this->assertRejected(fn () => $this->transaction($partner->id, ['partner_transaction_id' => 'ORDER-1']), 'transactions_partner_id_direction_partner_transaction_id_unique');
    }

    public function test_status_must_be_valid_for_the_direction()
    {
        $partner = Partner::factory()->create();

        $this->assertRejected(fn () => $this->transaction($partner->id, ['direction' => 'payout', 'status' => 'awaiting_payment']), 'transactions_status_check');
    }

    public function test_a_verified_bank_utr_cannot_be_used_twice_on_one_account_unless_the_first_failed()
    {
        $partner = Partner::factory()->create();
        $account = $this->paymentAccount();

        $first = $this->transaction($partner->id, ['payment_account_id' => $account, 'bank_utr_normalized' => 'UTR123', 'status' => 'under_review']);
        $this->assertRejected(fn () => $this->transaction($partner->id, ['payment_account_id' => $account, 'bank_utr_normalized' => 'UTR123', 'status' => 'under_review']), 'transactions_unique_payin_bank_utr');

        DB::table('transactions')->where('id', $first)->update(['status' => 'rejected']);
        $this->transaction($partner->id, ['payment_account_id' => $account, 'bank_utr_normalized' => 'UTR123', 'status' => 'under_review']);
        $this->assertSame(2, DB::table('transactions')->where('bank_utr_normalized', 'UTR123')->count());
    }

    public function test_customer_utr_claims_are_stored_separately_from_the_bank_utr()
    {
        $partner = Partner::factory()->create();
        $account = $this->paymentAccount();

        // Two customers claiming the same UTR is allowed at database level (the app flags it for review).
        $this->transaction($partner->id, ['payment_account_id' => $account, 'customer_utr_normalized' => 'CLAIM1', 'status' => 'payment_submitted']);
        $this->transaction($partner->id, ['payment_account_id' => $account, 'customer_utr_normalized' => 'CLAIM1', 'status' => 'payment_submitted']);

        $this->assertSame(2, DB::table('transactions')->where('customer_utr_normalized', 'CLAIM1')->count());
    }

    public function test_a_payment_account_needs_at_least_one_method_with_its_details()
    {
        $branchId = Branch::factory()->create()->id;
        $insert = fn (array $attributes) => DB::table('payment_accounts')->insert(array_merge([
            'id' => (string) Str::uuid7(), 'branch_id' => $branchId, 'label' => 'Acc', 'account_holder_name' => 'Demo',
        ], $attributes));

        $this->assertRejected(fn () => $insert([]), 'payment_accounts_has_method');
        $this->assertRejected(fn () => $insert(['is_bank_enabled' => true]), 'payment_accounts_bank_details');
        $this->assertRejected(fn () => $insert(['is_bank_enabled' => true, 'bank_name' => 'SBI', 'ifsc' => 'SBIN0000001', 'account_number_encrypted' => 'x', 'account_number_hash' => 'h1', 'is_qr_enabled' => true]), 'payment_accounts_upi_features');

        // Bank account with a linked UPI ID, QR enabled: valid.
        $insert([
            'is_bank_enabled' => true, 'bank_name' => 'SBI', 'ifsc' => 'SBIN0000001', 'account_number_encrypted' => 'x', 'account_number_hash' => 'h2',
            'is_upi_enabled' => true, 'upi_id_encrypted' => 'y', 'upi_id_hash' => 'u2', 'is_qr_enabled' => true,
        ]);
        $this->assertSame(1, DB::table('payment_accounts')->where('branch_id', $branchId)->count());
    }

    public function test_branch_top_up_allowance_cannot_go_negative_and_its_history_is_append_only()
    {
        $branch = Branch::factory()->create(['deposit_limit_type' => 'topup']);
        $admin = User::factory()->admin()->create();

        $this->assertRejected(fn () => DB::table('branches')->where('id', $branch->id)->update(['deposit_topup_balance' => -1]), 'branches_topup_balance_non_negative');

        $topup = (string) Str::uuid7();
        DB::table('branch_limit_topups')->insert(['id' => $topup, 'branch_id' => $branch->id, 'amount' => 5000000, 'balance_after' => 5000000, 'reason' => 'Weekly top-up', 'created_by' => $admin->id]);

        $this->assertRejected(fn () => DB::table('branch_limit_topups')->where('id', $topup)->update(['amount' => 1]), 'append-only');
    }

    public function test_limit_types_are_restricted_to_daily_reset_or_topup()
    {
        $this->assertRejected(fn () => Branch::factory()->create(['deposit_limit_type' => 'weekly']), 'branches_deposit_limit_type_check');
        $this->assertRejected(fn () => Partner::factory()->create(['payout_limit_type' => 'weekly']), 'partners_payout_limit_type_check');
    }

    public function test_a_successful_transaction_must_carry_its_commission_snapshot()
    {
        $partner = Partner::factory()->create();

        $this->assertRejected(fn () => $this->transaction($partner->id, ['status' => 'success']), 'transactions_success_has_commission');
    }

    public function test_an_unbalanced_ledger_journal_is_rejected()
    {
        [$partnerAccount, $branchAccount, $margin] = $this->pairLedgerAccounts();

        $this->assertRejected(function () use ($partnerAccount, $branchAccount) {
            $journal = $this->journal();
            $this->entry($journal, $partnerAccount, 9400);
            $this->entry($journal, $branchAccount, -9700); // margin line missing: sums to -300
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); // the check normally runs at COMMIT
        }, 'is unbalanced');
    }

    public function test_a_balanced_ledger_journal_is_accepted()
    {
        [$partnerAccount, $branchAccount, $margin] = $this->pairLedgerAccounts();

        $journal = $this->journal();
        $this->entry($journal, $partnerAccount, 9400);   // platform owes partner ₹94.00
        $this->entry($journal, $branchAccount, -9700);   // branch owes platform ₹97.00
        $this->entry($journal, $margin, 300);            // platform margin ₹3.00
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertSame(0, (int) DB::table('ledger_entries')->where('journal_id', $journal)->sum('amount'));
    }

    public function test_ledger_entries_and_journals_cannot_be_changed()
    {
        [$partnerAccount, $branchAccount, $margin] = $this->pairLedgerAccounts();
        $journal = $this->journal();
        $entry = $this->entry($journal, $partnerAccount, 100);
        $this->entry($journal, $margin, -100);

        $this->assertRejected(fn () => DB::table('ledger_entries')->where('id', $entry)->update(['amount' => 999]), 'append-only');
        $this->assertRejected(fn () => DB::table('ledger_journals')->where('id', $journal)->delete(), 'append-only');
    }

    public function test_a_transaction_can_only_be_booked_once()
    {
        $partner = Partner::factory()->create();
        $transaction = $this->transaction($partner->id);
        $this->journal(['type' => 'payin_success', 'transaction_id' => $transaction]);

        $this->assertRejected(fn () => $this->journal(['type' => 'payin_success', 'transaction_id' => $transaction]), 'ledger_journals_once_per_transaction');
    }

    public function test_payout_reservations_can_never_go_negative()
    {
        [$partnerAccount] = $this->pairLedgerAccounts();

        $this->assertRejected(fn () => DB::table('ledger_balances')->where('ledger_account_id', $partnerAccount)->update(['reserved' => -1]), 'ledger_balances_reserved_non_negative');
    }

    public function test_a_statement_line_cannot_be_imported_twice()
    {
        $account = $this->paymentAccount();
        $branchId = DB::table('payment_accounts')->where('id', $account)->value('branch_id');
        $line = fn () => DB::table('statement_entries')->insert([
            'id' => (string) Str::uuid7(), 'branch_id' => $branchId, 'payment_account_id' => $account,
            'value_date' => '2026-09-27', 'entry_direction' => 'credit', 'amount' => 500000, 'row_hash' => 'same-line',
        ]);

        $line();
        $this->assertRejected($line, 'statement_entries_payment_account_id_row_hash_unique');
    }

    public function test_only_one_open_settlement_per_party_and_period()
    {
        $partner = Partner::factory()->create();
        $settlement = fn () => DB::table('settlements')->insert([
            'id' => (string) Str::uuid7(), 'reference' => 'ST-'.Str::random(8), 'party_type' => 'partner',
            'partner_id' => $partner->id, 'run_type' => 'daily', 'direction' => 'none',
            'period_start' => '2026-09-26 18:30:00+00', 'period_end' => '2026-09-27 18:30:00+00',
        ]);

        $settlement();
        $this->assertRejected($settlement, 'settlements_one_per_period');
    }

    // ---------------------------------------------------------------- helpers

    private function assertRejected(callable $callback, string $expected): void
    {
        try {
            DB::transaction(fn () => $callback()); // savepoint keeps the test transaction usable
            $this->fail("Expected the database to reject this ({$expected}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    private function rate(string $partnerId, string $from, ?string $to, string $rate): void
    {
        DB::table('commission_rates')->insert([
            'id' => (string) Str::uuid7(), 'subject_type' => 'partner', 'subject_id' => $partnerId,
            'side' => 'partner', 'direction' => 'deposit', 'rate_percent' => $rate,
            'effective_from' => $from, 'effective_to' => $to,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function transaction(string $partnerId, array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('transactions')->insert(array_merge([
            'id' => $id,
            'reference' => 'PI-'.Str::upper(Str::random(10)),
            'direction' => 'payin',
            'partner_id' => $partnerId,
            'partner_transaction_id' => (string) Str::uuid(),
            'amount' => 10000,
            'status' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function paymentAccount(): string
    {
        $id = (string) Str::uuid7();

        DB::table('payment_accounts')->insert([
            'id' => $id, 'branch_id' => Branch::factory()->create()->id, 'is_upi_enabled' => true, 'label' => 'UPI 1',
            'account_holder_name' => 'Demo', 'upi_id_encrypted' => 'x', 'upi_id_hash' => Str::random(40),
        ]);

        return $id;
    }

    /**
     * @return array{string, string, string} partner position, branch position, platform margin
     */
    private function pairLedgerAccounts(): array
    {
        $partner = Partner::factory()->create();
        $branch = Branch::factory()->create();
        $ids = [];

        foreach (['partner_position', 'branch_position'] as $kind) {
            $id = (string) Str::uuid7();
            DB::table('ledger_accounts')->insert(['id' => $id, 'kind' => $kind, 'partner_id' => $partner->id, 'branch_id' => $branch->id]);
            DB::table('ledger_balances')->insert(['ledger_account_id' => $id]);
            $ids[] = $id;
        }

        $ids[] = (string) DB::table('ledger_accounts')->where('kind', 'platform_margin')->value('id');

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function journal(array $attributes = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('ledger_journals')->insert(array_merge(['id' => $id, 'type' => 'adjustment'], $attributes));

        return $id;
    }

    private function entry(string $journalId, string $accountId, int $amount): string
    {
        $id = (string) Str::uuid7();
        DB::table('ledger_entries')->insert(['id' => $id, 'journal_id' => $journalId, 'ledger_account_id' => $accountId, 'amount' => $amount]);

        return $id;
    }
}
