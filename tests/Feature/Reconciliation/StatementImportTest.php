<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\Models\StatementImport;
use App\Domain\Reconciliation\Models\StatementTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class StatementImportTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private const HEADER = "HDFC BANK LTD\nAccount Statement,Account No 50100482710001\nDate,Narration,Chq./Ref.No.,Value Dt,Withdrawal Amt.,Deposit Amt.,Closing Balance\n";

    private User $owner;

    private PaymentAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        $this->buildNetwork();
        $this->account = $this->activeAccount();
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
    }

    private function preview(UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('branch.statement-imports.preview'), [
            'payment_account_id' => $this->account->id,
            'file' => $file,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function flashed(TestResponse $response): array
    {
        return $response->getSession()->get('inertia.flash_data')['import_preview'];
    }

    /**
     * Step 2: the lines of the file, read with the preview's mapping.
     *
     * @return array<string, mixed>
     */
    private function lines(array $preview): array
    {
        $response = $this->actingAs($this->owner)->post(route('branch.statement-imports.lines'), [
            'token' => $preview['token'],
            'mapping' => $preview['mapping'],
        ])->assertSessionHasNoErrors();

        return $response->getSession()->get('inertia.flash_data')['import_lines'];
    }

    /**
     * Step 3. Without $rows, every line that can be ticked is ticked.
     *
     * @param  list<int>|null  $rows
     */
    private function import(array $preview, ?string $layout = null, ?array $rows = null): TestResponse
    {
        if ($rows === null) {
            $lines = rescue(fn () => $this->lines($preview)['lines'], [], false);
            $rows = array_column(array_filter($lines, fn (array $line) => $line['duplicate'] === null), 'row');
        }

        return $this->actingAs($this->owner)->post(route('branch.statement-imports.store'), [
            'token' => $preview['token'],
            'mapping' => $preview['mapping'],
            'layout' => $layout,
            'rows' => $rows,
        ]);
    }

    public function test_a_bank_csv_is_read_with_guessed_columns_matched_and_duplicates_skipped()
    {
        $this->manualApproval();
        $payin = $this->submittedPayin(['amount' => 500000]);
        $csv = self::HEADER.implode("\n", [
            '25/09/2026,OPENING BALANCE,,,,,"1,00,000.00"',
            '25/09/2026,UPI-RAHUL-626812820491-PAYMENT,0000626812820491,25/09/2026,,"5,000.00","1,05,000.00"',
            '25/09/2026,NEFT-HDFCN52026092812345678-ACME,HDFCN52026092812345678,25/09/2026,,"12,534.00","1,17,534.00"',
            '26/09/2026,SMS CHARGES,,26/09/2026,17.70,,"1,17,516.30"',
            '26/09/2026,IMPS-RAJ-626899990001,,26/09/2026,,abc,',
            '31/02/2026,BAD DATE 626800000001,,,,100.00,',
        ]);

        $preview = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('hdfc-sep.csv', $csv))->assertSessionHasNoErrors());

        // The preamble is skipped and the columns found from their names.
        $this->assertSame(3, $preview['mapping']['header_row']);
        $this->assertSame(['date' => 0, 'date_format' => 'd/m/Y', 'description' => 1, 'utr' => 2, 'credit' => 5, 'debit' => 4, 'balance' => 6], array_intersect_key($preview['mapping'], array_flip(['date', 'description', 'utr', 'debit', 'credit', 'balance', 'date_format'])));
        $this->assertSame('Narration', $preview['headers'][1]);
        $this->assertNull($preview['layout']);

        $this->import($preview, 'HDFC Bank')->assertSessionHasNoErrors();

        $import = StatementImport::sole();
        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->rows_imported);
        $this->assertSame(3, $import->rows_failed);
        $this->assertSame(1753400, $import->credit_total);
        $this->assertSame(0, $import->debit_total);
        $this->assertSame('2026-09-25', $import->period_from?->toDateString());
        // Never stored: row 7 (no UTR, client request 2026-11-18), 8 (amount
        // "abc") and 9 (31 February).
        $this->assertSame([7, 8, 9], array_column($import->errors ?? [], 'row'));
        $this->assertSame(['utr', 'amount', 'date'], array_column($import->errors ?? [], 'field'));
        $this->assertNotNull($import->file_id);
        Storage::disk('local')->assertExists($import->file->path);

        // The zero-padded reference is the customer's 12-digit UTR: matched.
        $upi = StatementEntry::where('utr_normalized', '626812820491')->sole();
        $this->assertSame('matched', $upi->status);
        $this->assertSame($payin->id, $upi->transaction_id);
        $this->assertSame(['utr_not_found'], ReconciliationCase::orderBy('created_at')->get()->map(fn ($case) => $case->type->value)->all());

        // The same file again: every line is already in PayGate…
        $again = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('copy.csv', $csv)));
        $this->assertSame('HDFC Bank', $again['layout']);
        $this->assertSame(['stored', 'stored'], array_column($this->lines($again)['lines'], 'duplicate'));
        $this->import($again, null, [5, 6])->assertSessionHasErrors('rows');

        // …and an overlapping one adds only the new line.
        $overlap = self::HEADER.implode("\n", [
            '25/09/2026,UPI-RAHUL-626812820491-PAYMENT,0000626812820491,25/09/2026,,"5,000.00","1,05,000.00"',
            '26/09/2026,SMS CHARGES,,26/09/2026,17.70,,"1,17,516.30"',
            '27/09/2026,UPI-626812820499-X,,27/09/2026,,700.00,"1,18,216.30"',
        ]);
        // Lines already in PayGate are skipped even when ticked.
        $this->import($this->flashed($this->preview(UploadedFile::fake()->createWithContent('hdfc-late.csv', $overlap))), null, [4, 5, 6])->assertSessionHasNoErrors();

        $second = StatementImport::whereKeyNot($import->id)->sole();
        $this->assertSame(1, $second->rows_imported);
        $this->assertSame(1, $second->rows_duplicate);
        $this->assertSame(3, StatementEntry::count());
        $this->assertSame(1, StatementTemplate::count());
    }

    public function test_an_excel_file_with_one_amount_column_and_cr_dr()
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([
            ['Txn Date', 'Description', 'Amount', 'Cr/Dr'],
            [ExcelDate::PHPToExcel(new \DateTime('2026-09-25')), 'IMPS/626812820491/RAHUL', 5000, 'CR'],
            [ExcelDate::PHPToExcel(new \DateTime('2026-09-26')), 'IMPS-OUT/626812820777/RENT', 2000.5, 'DR'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'stm').'.xlsx';
        (new Xlsx($sheet))->save($path);

        $preview = $this->flashed($this->preview(new UploadedFile($path, 'sbi.xlsx', null, null, true))->assertSessionHasNoErrors());

        $this->assertSame(['credit' => null, 'debit' => null, 'amount' => 2, 'type' => 3], array_intersect_key($preview['mapping'], array_flip(['amount', 'type', 'credit', 'debit'])));

        $this->import($preview)->assertSessionHasNoErrors();

        $lines = StatementEntry::orderBy('value_date')->get();
        $this->assertSame(['2026-09-25', '2026-09-26'], $lines->map(fn ($line) => $line->value_date->toDateString())->all());
        $this->assertSame(['credit', 'debit'], $lines->pluck('entry_direction')->all());
        $this->assertSame([500000, 200050], $lines->pluck('amount')->all());
        $this->assertSame('626812820491', $lines[0]->utr_normalized);
        $this->assertSame('626812820777', $lines[1]->utr_normalized);
    }

    public function test_unreadable_files_and_bad_mappings_are_explained()
    {
        $this->preview(UploadedFile::fake()->createWithContent('statement.pdf', '%PDF-1.4'))->assertSessionHasErrors('file');

        $preview = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('x.csv', "Date,Narration,Deposit\n25/09/2026,UPI 626812820491,100\n")));
        $this->import([...$preview, 'mapping' => [...$preview['mapping'], 'credit' => null, 'debit' => null, 'amount' => null]], null, [2])
            ->assertSessionHasErrors('mapping.credit');
        $this->import([...$preview, 'token' => str_repeat('x', 32)], null, [2])->assertSessionHasErrors('file');
        $this->import($preview, null, [])->assertSessionHasErrors('rows');
    }

    public function test_only_the_ticked_lines_are_stored_and_the_file_can_be_reused_for_the_rest()
    {
        $this->manualApproval();
        $payin = $this->submittedPayin(['amount' => 349100]);
        $utr = $payin->customer_utr_normalized;
        // Google Pay for Business export: "Creation time" with a comma and a
        // narrow no-break space before PM; no narration column.
        $csv = "Payer,Paid via,Type (UPI / UPI CC),Creation time,Transaction ID,Amount,Processing Fee,Net Amount,Status,Update time,Notes\n"
            ."N R,PhonePe,UPI,\"Nov 2, 2026, 1:23\u{202F}PM\",{$utr},3491,0,3491,Scheduled to Settle,\"Nov 2, 2026, 1:23\u{202F}PM\",TRNS1\n"
            ."OWN TRANSFER,Others,UPI,\"Nov 2, 2026, 1:21\u{202F}PM\",630677879973,2500,0,2500,Scheduled to Settle,\"Nov 2, 2026, 1:21\u{202F}PM\",TRNS2\n";

        $preview = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('gpay.csv', $csv))->assertSessionHasNoErrors());
        $this->assertSame(['date' => 3, 'date_format' => 'M d, Y', 'description' => 0, 'utr' => 4, 'amount' => 5], array_intersect_key($preview['mapping'], array_flip(['date', 'date_format', 'description', 'utr', 'amount'])));

        $lines = $this->lines($preview);
        $this->assertSame([2, 3], array_column($lines['lines'], 'row'));
        $this->assertSame('2026-11-02', $lines['lines'][0]['date']);
        $this->assertSame($payin->reference, $lines['lines'][0]['transaction']['reference']);
        $this->assertNull($lines['lines'][1]['transaction']);

        // Only row 2 is ticked: the personal transfer is never stored.
        $this->import($preview, 'Google Pay', [2])->assertSessionHasNoErrors();
        $this->assertSame([$utr], StatementEntry::pluck('utr_normalized')->all());
        $this->assertSame('matched', StatementEntry::sole()->status);
        $this->assertSame([2, 1, 0], [StatementImport::sole()->rows_total, StatementImport::sole()->rows_imported, StatementImport::sole()->rows_duplicate]);

        // The same file later: row 2 is flagged, row 3 can still be added.
        $again = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('gpay.csv', $csv)));
        $this->assertSame([2 => 'stored', 3 => null], array_column($this->lines($again)['lines'], 'duplicate', 'row'));
        $this->import($again, null, [2, 3])->assertSessionHasNoErrors();
        $this->assertSame(2, StatementEntry::count());
        $this->assertSame([0, 1], StatementImport::pluck('rows_duplicate')->sort()->values()->all());
    }

    public function test_each_line_says_before_the_import_what_will_happen_and_why()
    {
        $this->manualApproval();
        $match = $this->submittedPayin(['amount' => 500000], '626812820491');
        $short = $this->submittedPayin(['amount' => 200000], '626812820492');
        $csv = "Date,Narration,Deposit,Withdrawal\n".implode("\n", [
            '25/09/2026,UPI 626812820491 RAHUL,5000,',      // row 2: matches
            '25/09/2026,UPI 626812820492 AMIT,1500,',       // row 3: amount differs
            '25/09/2026,UPI 626812820499 NOBODY,700,',      // row 4: no deposit
            '25/09/2026,CASH DEPOSIT BY SELF,900,',         // row 5: no UTR
            '25/09/2026,IMPS-OUT/630714700316,,14803',      // row 6: no payout
            '25/09/2026,IMPS-CHG/630714700316,,6',          // row 7: same UTR as row 6
            '31/02/2026,UPI 626812820493 BAD DATE,100,',    // row 8: can't be read
        ]);

        $preview = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('mixed.csv', $csv)));
        $lines = $this->lines($preview);
        $check = collect($lines['lines'])->mapWithKeys(fn (array $line) => [$line['row'] => $line['check']]);

        $this->assertSame('ok', $check[2]['level']);
        $this->assertStringContainsString($match->reference, $check[2]['message']);
        $this->assertSame('warn', $check[3]['level']);
        $this->assertStringContainsString($short->reference.' is for ₹2,000.00; the bank shows ₹1,500.00', $check[3]['message']);
        $this->assertStringContainsString('No deposit has this UTR yet', $check[4]['message']);
        $this->assertStringContainsString('No payout of this branch', $check[6]['message']);
        $this->assertStringContainsString('earlier line of this file has the same UTR', $check[7]['message']);

        // Rows breaking a rule are listed, never stored: no UTR (row 5),
        // impossible date (row 8), with the field and their cells.
        $this->assertArrayNotHasKey(5, $check->all());
        $this->assertSame([[5, 'utr'], [8, 'date']], array_map(fn (array $error) => [$error['row'], $error['field']], $lines['errors']));
        $this->assertStringContainsString('No UTR in this row', $lines['errors'][0]['message']);
        $this->assertStringContainsString('31/02/2026', $lines['errors'][1]['text']);
        $this->assertSame(['utr' => 1, 'date' => 1], $this->lines($preview)['failed_by']);

        // The result says how many matched and how many wait in Unsettled.
        $this->import($preview, null, [2, 3, 4])->assertSessionHasNoErrors();
        $toast = session('inertia.flash_data')['toast'] ?? null;
        $this->assertSame('warning', $toast['type']);
        $this->assertStringContainsString('3 lines imported: 1 matched, 2 in Unsettled UTR', $toast['message']);
    }

    public function test_a_column_that_does_not_fit_our_rules_is_refused_with_what_it_should_hold()
    {
        // Google Pay's Excel export: "Transaction ID" is Google's own code.
        $csv = "Payer,Creation time,Transaction ID,Amount\n"
            ."A,\"1 Nov 2026, 21:22\",CICAgPi5uMjEdw,3491\n"
            ."B,\"1 Nov 2026, 21:20\",CICAgPi5mKbKfQ,3500\n"
            ."C,\"1 Nov 2026, 21:20\",CICAgPi56ILqCA,600\n";
        $preview = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('gpay.csv', $csv)));

        $response = $this->actingAs($this->owner)->post(route('branch.statement-imports.lines'), ['token' => $preview['token'], 'mapping' => $preview['mapping']]);

        $response->assertSessionHasErrors(['mapping', 'mapping.utr']);
        $message = session('errors')->first('mapping');
        $this->assertStringContainsString('The UTR column C “Transaction ID” doesn’t hold UTRs (3 of 3 rows fail, e.g. “CICAgPi5uMjEdw”', $message);
        $this->assertStringContainsString('12 digits for UPI / IMPS', $message);

        // The wrong date format for most rows: the date column is named.
        $dates = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('dates.csv', "Date,Narration,Deposit\n2026-09-25,UPI 626812820491,100\n2026-09-26,UPI 626812820492,100\n2026-09-27,UPI 626812820493,100\n")));
        $this->actingAs($this->owner)->post(route('branch.statement-imports.lines'), ['token' => $dates['token'], 'mapping' => [...$dates['mapping'], 'date_format' => 'd/m/Y']])
            ->assertSessionHasErrors(['mapping', 'mapping.date']);
        $this->assertStringContainsString('The date column A “Date” doesn’t hold dates in the format d/m/Y', session('errors')->first('mapping'));
        $this->assertSame(0, StatementEntry::count());
    }

    public function test_the_same_file_on_another_branchs_account_adds_nothing()
    {
        // Client report 2026-11-18: the same Google Pay file imported on a
        // second branch's account filled Unsettled UTR with duplicates.
        $csv = "Date,Narration,Deposit\n25/09/2026,UPI 630739524977 BHUSHAN,800\n25/09/2026,UPI 499683632003 BHARAT,1425\n";
        $this->import($this->flashed($this->preview(UploadedFile::fake()->createWithContent('gpay.csv', $csv))))->assertSessionHasNoErrors();
        $this->assertSame(2, StatementEntry::count());

        $other = Branch::factory()->create();
        $otherAccount = $this->activeAccount($other);
        $otherOwner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $other)->withTwoFactor()->create();
        $preview = $this->actingAs($otherOwner)->post(route('branch.statement-imports.preview'), ['payment_account_id' => $otherAccount->id, 'file' => UploadedFile::fake()->createWithContent('gpay.csv', $csv)])
            ->getSession()->get('inertia.flash_data')['import_preview'];
        $lines = $this->actingAs($otherOwner)->post(route('branch.statement-imports.lines'), ['token' => $preview['token'], 'mapping' => $preview['mapping']])
            ->getSession()->get('inertia.flash_data')['import_lines'];

        $this->assertSame(['elsewhere', 'elsewhere'], array_column($lines['lines'], 'duplicate'));
        $this->actingAs($otherOwner)->post(route('branch.statement-imports.store'), ['token' => $preview['token'], 'mapping' => $preview['mapping'], 'rows' => [2, 3]])
            ->assertSessionHasErrors('rows');
        $this->assertSame(2, StatementEntry::count());
        $this->assertSame(0, ReconciliationCase::where('type', 'duplicate_utr')->count());
    }

    public function test_admin_undoes_a_wrong_import_keeping_lines_that_matched()
    {
        $this->manualApproval();
        $payin = $this->submittedPayin(['amount' => 80000], '630739524977');
        $csv = "Date,Narration,Deposit\n25/09/2026,UPI 630739524977 BHUSHAN,800\n25/09/2026,UPI 499683632003 BHARAT,1425\n25/09/2026,UPI 630716517978 KOMAL,10000\n";
        $this->import($this->flashed($this->preview(UploadedFile::fake()->createWithContent('wrong.csv', $csv))))->assertSessionHasNoErrors();
        $import = StatementImport::sole();
        $this->assertSame(2, ReconciliationCase::count());

        // Branches can't undo; Admin can.
        $this->actingAs($this->owner)->post('/admin/statements/imports/'.$import->id.'/undo')->assertForbidden();
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $this->actingAs($admin)->post(route('admin.statement-imports.undo', $import))->assertSessionHasNoErrors();

        // The matched line stays; the two unmatched ones and their cases go.
        $this->assertSame([$payin->id], StatementEntry::pluck('transaction_id')->all());
        $this->assertSame(0, ReconciliationCase::count());
        $import->refresh();
        $this->assertNotNull($import->undone_at);
        $this->assertSame($admin->id, $import->undone_by);
        $this->assertSame(2, $import->rows_removed);
        $this->assertTrue(AuditLog::where('action', 'statement.import_undone')->exists());

        // Shown in Statement History; can't be undone twice.
        $this->actingAs($admin)->get(route('admin.statement-imports.index'))
            ->assertInertia(fn ($page) => $page->where('imports.data.0.rows_removed', 2)->where('can.undo', true));
        $this->actingAs($admin)->post(route('admin.statement-imports.undo', $import))->assertSessionHasErrors('import');

        // The removed lines can be imported again (e.g. on the right account).
        $again = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('wrong.csv', $csv)));
        $this->assertSame([null, null], array_column(array_slice($this->lines($again)['lines'], 1), 'duplicate'));
    }

    public function test_a_line_with_a_utr_already_stored_is_a_duplicate_even_with_another_date_or_narration()
    {
        $this->import($this->flashed($this->preview(UploadedFile::fake()->createWithContent('a.csv', "Date,Narration,Deposit\n25/09/2026,UPI 626812820491 RAHUL,100\n"))))->assertSessionHasNoErrors();

        $other = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('b.csv', "Date,Narration,Deposit\n26/09/2026,UPI/CR/626812820491/RAHUL K,100\n26/09/2026,UPI/CR/626812820491/RAHUL K,100\n26/09/2026,IMPS-CHG/626812820491,5\n")));

        // Same UTR + direction + amount: stored. Under the same UTR with
        // another amount (e.g. a charge): also never added, the UTR is
        // already on a bank line (client report 2026-11-18).
        $this->assertSame(['stored', 'stored', 'elsewhere'], array_column($this->lines($other)['lines'], 'duplicate'));
    }

    public function test_a_saved_layout_with_a_wrong_date_format_is_corrected_on_the_next_upload()
    {
        $csv = "Payer,Creation time,Transaction ID,Amount\nA,\"1 Nov 2026, 21:22\",856385642796,3491\n";
        $preview = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('g.csv', $csv)));
        $this->import([...$preview, 'mapping' => [...$preview['mapping'], 'date_format' => 'd M Y']], 'Wallet')->assertSessionHasNoErrors();
        StatementTemplate::query()->update(['mapping->date_format' => 'd/m/Y']);

        $next = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('g2.csv', $csv)));

        $this->assertSame('Wallet', $next['layout']);
        $this->assertSame('d M Y', $next['mapping']['date_format']);
    }

    public function test_statement_history_lists_imports_of_the_branch_and_the_file_downloads()
    {
        $preview = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('x.csv', "Date,Narration,Deposit\n25/09/2026,UPI 626812820491,100\n")));
        $this->import($preview)->assertSessionHasNoErrors();
        $import = StatementImport::sole();

        $this->actingAs($this->owner)->get(route('branch.statement-imports.index'))
            ->assertInertia(fn ($page) => $page->component('statements/imports')->where('imports.data.0.file', 'x.csv')->where('imports.data.0.rows_imported', 1));
        $this->actingAs($this->owner)->get(route('files.show', $import->file_id))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="x.csv"');

        $stranger = User::factory()->branch(SystemRoles::BRANCH_OWNER)->withTwoFactor()->create();
        $this->actingAs($stranger)->get(route('files.show', $import->file_id))->assertNotFound();
    }
}
