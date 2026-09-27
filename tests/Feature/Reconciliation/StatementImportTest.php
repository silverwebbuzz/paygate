<?php

namespace Tests\Feature\Reconciliation;

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

    private function import(array $preview, ?string $layout = null): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('branch.statement-imports.store'), [
            'token' => $preview['token'],
            'mapping' => $preview['mapping'],
            'layout' => $layout,
        ]);
    }

    public function test_a_bank_csv_is_read_with_guessed_columns_matched_and_duplicates_skipped()
    {
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
        $this->assertSame(3, $import->rows_imported);
        $this->assertSame(2, $import->rows_failed);
        $this->assertSame(1753400, $import->credit_total);
        $this->assertSame(1770, $import->debit_total);
        $this->assertSame('2026-09-25', $import->period_from?->toDateString());
        // File rows 8 (amount "abc") and 9 (31 February) can't be read.
        $this->assertSame([8, 9], array_column($import->errors ?? [], 'row'));
        $this->assertNotNull($import->file_id);
        Storage::disk('local')->assertExists($import->file->path);

        // The zero-padded reference is the customer's 12-digit UTR: matched.
        $upi = StatementEntry::where('utr_normalized', '626812820491')->sole();
        $this->assertSame('matched', $upi->status);
        $this->assertSame($payin->id, $upi->transaction_id);
        $this->assertSame(['utr_not_found', 'manual_review'], ReconciliationCase::orderBy('created_at')->get()->map(fn ($case) => $case->type->value)->all());

        // The same file again is refused…
        $again = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('copy.csv', $csv)));
        $this->assertSame('HDFC Bank', $again['layout']);
        $this->import($again)->assertSessionHasErrors('file');

        // …and an overlapping one adds only the new line.
        $overlap = self::HEADER.implode("\n", [
            '25/09/2026,UPI-RAHUL-626812820491-PAYMENT,0000626812820491,25/09/2026,,"5,000.00","1,05,000.00"',
            '26/09/2026,SMS CHARGES,,26/09/2026,17.70,,"1,17,516.30"',
            '27/09/2026,UPI-626812820499-X,,27/09/2026,,700.00,"1,18,216.30"',
        ]);
        $this->import($this->flashed($this->preview(UploadedFile::fake()->createWithContent('hdfc-late.csv', $overlap))))->assertSessionHasNoErrors();

        $second = StatementImport::whereKeyNot($import->id)->sole();
        $this->assertSame(1, $second->rows_imported);
        $this->assertSame(2, $second->rows_duplicate);
        $this->assertSame(4, StatementEntry::count());
        $this->assertSame(1, StatementTemplate::count());
    }

    public function test_an_excel_file_with_one_amount_column_and_cr_dr()
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([
            ['Txn Date', 'Description', 'Amount', 'Cr/Dr'],
            [ExcelDate::PHPToExcel(new \DateTime('2026-09-25')), 'IMPS/626812820491/RAHUL', 5000, 'CR'],
            [ExcelDate::PHPToExcel(new \DateTime('2026-09-26')), 'ATM WDL 1234', 2000.5, 'DR'],
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
        $this->assertNull($lines[1]->utr_normalized);
    }

    public function test_unreadable_files_and_bad_mappings_are_explained()
    {
        $this->preview(UploadedFile::fake()->createWithContent('statement.pdf', '%PDF-1.4'))->assertSessionHasErrors('file');

        $preview = $this->flashed($this->preview(UploadedFile::fake()->createWithContent('x.csv', "Date,Narration,Deposit\n25/09/2026,UPI 626812820491,100\n")));
        $this->import([...$preview, 'mapping' => [...$preview['mapping'], 'credit' => null, 'debit' => null, 'amount' => null]])
            ->assertSessionHasErrors('mapping.credit');
        $this->import([...$preview, 'token' => str_repeat('x', 32)])->assertSessionHasErrors('file');
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
