<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Reconciliation\Import\ColumnMapping;
use App\Domain\Reconciliation\Import\StatementFile;
use App\Domain\Reconciliation\Import\StatementParser;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\Models\StatementImport;
use App\Domain\Reconciliation\Models\StatementTemplate;
use App\Domain\Reconciliation\StatementMatcher;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Statement import (Req 19; decided 2026-09-28, G-27; line selection
 * 2026-11-18): any CSV / Excel file of one branch account, in three steps.
 *
 * 1. stage + preview: the file is kept privately and its columns shown,
 *    with a mapping guessed from the headers, or the saved mapping of the
 *    same layout (StatementTemplate).
 * 2. lines: every line is read with the confirmed mapping and shown with a
 *    checkbox, unticked; lines already in PayGate can't be ticked.
 * 3. handle: only the ticked lines are stored (never twice) and matched.
 *    All or nothing, in one database transaction. The mapping is
 *    remembered for the layout. The same file may be uploaded again later
 *    to add lines left out the first time.
 */
class ImportStatement
{
    public const MAX_ROWS = 5000;

    private const STAGING = 'statement-uploads/pending';

    public function __construct(private StatementMatcher $matcher, private AutoApproveDeposit $autoApprove) {}

    /**
     * Keeps the upload privately until the import is confirmed.
     */
    public function stage(UploadedFile $file): string
    {
        $extension = in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt', 'xls', 'xlsx'], true) ? strtolower($file->getClientOriginalExtension()) : 'csv';
        $path = $file->storeAs(self::STAGING, Str::uuid()->toString().'.'.$extension, 'local');

        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('The file could not be stored. Try again.')]);
        }

        return $path;
    }

    /**
     * What the mapping screen shows: the header, the first rows of the file,
     * and the mapping to start from.
     *
     * @return array{headers: list<string>, rows: list<list<string>>, mapping: array<string, int|string|null>, layout: ?string, date_formats: list<string>}
     */
    public function preview(string $path): array
    {
        $rows = StatementFile::rows(Storage::disk('local')->path($path), 60);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('The file is empty.')]);
        }

        $mapping = StatementParser::guess($rows);
        $template = StatementTemplate::query()
            ->where('header_signature', StatementParser::signature(StatementParser::headers($rows, (int) $mapping['header_row'])))
            ->first();

        if ($template !== null) {
            $mapping = $template->mapping;

            // A layout saved with a wrong date format is corrected here.
            if (isset($mapping['date']) && is_numeric($mapping['date'])) {
                $mapping['date_format'] = StatementParser::dateFormatFor($rows, (int) $mapping['header_row'], (int) $mapping['date'], is_string($mapping['date_format'] ?? null) ? $mapping['date_format'] : null);
            }
        }

        $headerRow = (int) $mapping['header_row'];

        return [
            'headers' => StatementParser::headers($rows, $headerRow),
            // From the top, so the header row can be changed on screen.
            'rows' => array_map(
                fn (array $row) => array_map(fn ($cell) => StatementParser::text($cell), $row),
                array_slice($rows, 0, max($headerRow + 8, 15)),
            ),
            'mapping' => $mapping,
            'layout' => $template?->name,
            'date_formats' => ColumnMapping::DATE_FORMATS,
        ];
    }

    /**
     * Step 2 (client request 2026-11-18): every line of the file, read with
     * the confirmed mapping, for the branch to tick the ones that belong in
     * PayGate (a statement also carries the owner's personal transfers,
     * which are never stored). Lines already in PayGate are flagged and
     * can't be ticked:
     *
     * - the same UTR, direction and amount on this account (a line from an
     *   earlier file, or another export of the same account);
     * - otherwise the same date, direction, amount and narration (lines
     *   without a UTR);
     * - a line repeated inside this file.
     *
     * Each line also says which deposit / payout carries its UTR, so the
     * branch can see at a glance which lines are PayGate's.
     *
     * @return array{lines: list<array{row: int, date: string, direction: string, amount: int, utr_normalized: ?string, description: ?string, balance: ?string, hash: string, duplicate: ?string, transaction: ?array{reference: string, status: string}}>, errors: list<array{row: int, message: string}>, headers: list<string>}
     */
    public function lines(PaymentAccount $account, string $path, ColumnMapping $mapping): array
    {
        $rows = StatementFile::rows(Storage::disk('local')->path($path), $mapping->headerRow + self::MAX_ROWS + 1);

        if (count($rows) > $mapping->headerRow + self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('The file has more than :max lines. Split it by date and import the parts.', ['max' => number_format(self::MAX_ROWS)])]);
        }

        $parsed = StatementParser::parse($rows, $mapping);

        if ($parsed['lines'] === []) {
            throw ValidationException::withMessages(['mapping' => $parsed['errors'] === []
                ? __('No credit or debit lines were found with these columns.')
                : __('No line could be read. First problem, row :row: :message', $parsed['errors'][0])]);
        }

        // The 2nd, 3rd… identical line of the file gets its own key.
        $seen = [];
        $lines = [];

        foreach ($parsed['lines'] as $line) {
            $base = StatementEntry::rowHash($account->id, $line['date'], $line['direction'], $line['amount'], $line['utr_normalized'], $line['description']);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $lines[] = [...$line, 'hash' => StatementEntry::rowHash($account->id, $line['date'], $line['direction'], $line['amount'], $line['utr_normalized'], $line['description'], $seen[$base])];
        }

        $utrs = array_values(array_unique(array_filter(array_column($lines, 'utr_normalized'))));
        $stored = collect();
        $hashes = collect();
        $transactions = collect();

        foreach (array_chunk($utrs, 1000) as $chunk) {
            $stored = $stored->merge(StatementEntry::query()
                ->where('payment_account_id', $account->id)
                ->whereIn('utr_normalized', $chunk)
                ->get(['utr_normalized', 'entry_direction', 'amount'])
                ->map(fn (StatementEntry $entry) => self::utrKey((string) $entry->utr_normalized, $entry->entry_direction, $entry->amount)));

            $transactions = $transactions->merge(Transaction::query()
                ->where(fn ($query) => $query
                    ->where('payment_account_id', $account->id)
                    ->orWhere(fn ($query) => $query->where('direction', 'payout')->where('branch_id', $account->branch_id)))
                ->where(fn ($query) => $query->whereIn('bank_utr_normalized', $chunk)->orWhereIn('customer_utr_normalized', $chunk))
                ->orderBy('created_at')
                ->get(['reference', 'status', 'direction', 'bank_utr_normalized', 'customer_utr_normalized'])
                ->flatMap(fn (Transaction $txn) => collect([$txn->bank_utr_normalized, $txn->customer_utr_normalized])
                    ->filter()
                    ->unique()
                    ->mapWithKeys(fn ($utr) => [$utr.'|'.($txn->direction === 'payout' ? 'debit' : 'credit') => ['reference' => $txn->reference, 'status' => $txn->status]])));
        }

        foreach (array_chunk(array_column($lines, 'hash'), 1000) as $chunk) {
            $hashes = $hashes->merge(StatementEntry::query()->where('payment_account_id', $account->id)->whereIn('row_hash', $chunk)->pluck('row_hash'));
        }

        $stored = $stored->flip();
        $hashes = $hashes->flip();
        $inFile = [];

        foreach ($lines as &$line) {
            $key = $line['utr_normalized'] === null ? null : self::utrKey($line['utr_normalized'], $line['direction'], $line['amount']);

            $line['duplicate'] = match (true) {
                $hashes->has($line['hash']) || ($key !== null && $stored->has($key)) => 'stored',
                $key !== null && isset($inFile[$key]) => 'repeated',
                default => null,
            };
            $line['transaction'] = $line['utr_normalized'] === null ? null : $transactions->get($line['utr_normalized'].'|'.$line['direction']);

            if ($key !== null) {
                $inFile[$key] = true;
            }
        }

        unset($line);

        return ['lines' => $lines, 'errors' => $parsed['errors'], 'headers' => StatementParser::headers($rows, $mapping->headerRow)];
    }

    /**
     * Step 3: stores the ticked lines (by file row number) and matches them.
     * Lines already in PayGate are skipped even if ticked; the rest of the
     * file is never stored. All or nothing, in one database transaction.
     *
     * @param  list<int>  $selectedRows
     */
    public function handle(User $actor, PaymentAccount $account, string $path, string $originalName, string $mime, ColumnMapping $mapping, array $selectedRows, ?string $layoutName = null): StatementImport
    {
        $sha = (string) hash_file('sha256', Storage::disk('local')->path($path));
        $read = $this->lines($account, $path, $mapping);
        $selected = array_flip($selectedRows);
        $chosen = array_values(array_filter($read['lines'], fn (array $line) => isset($selected[$line['row']])));

        if ($chosen === []) {
            throw ValidationException::withMessages(['rows' => __('Tick the lines to import.')]);
        }

        if (array_filter($chosen, fn (array $line) => $line['duplicate'] === null) === []) {
            throw ValidationException::withMessages(['rows' => __('Every ticked line is already in PayGate.')]);
        }

        $parsed = ['lines' => $chosen, 'errors' => $read['errors']];
        $fileLines = count($read['lines']);
        $headers = $read['headers'];

        $import = DB::transaction(function () use ($actor, $account, $path, $originalName, $mime, $mapping, $layoutName, $sha, $parsed, $headers, $fileLines) {
            $template = StatementTemplate::query()->updateOrCreate(
                ['header_signature' => StatementParser::signature($headers)],
                ['name' => mb_substr(trim((string) $layoutName) ?: ($account->bank_name ?? __('Statement layout')), 0, 100), 'mapping' => $mapping->toArray(), 'updated_by' => $actor->id],
            );

            if ($template->wasRecentlyCreated) {
                $template->forceFill(['created_by' => $actor->id])->save();
            }

            $import = StatementImport::create([
                'branch_id' => $account->branch_id,
                'payment_account_id' => $account->id,
                'source' => 'upload',
                'file_sha256' => $sha,
                'template_id' => $template->id,
                'status' => 'processing',
                'imported_by' => $actor->id,
            ]);

            $file = StoredFile::adopt($path, $originalName, $mime, 'statement', $import, 'user', $actor->id);

            $lines = $parsed['lines'];

            // Checked again under the import (another person may have
            // imported the same lines since they were shown).
            $existing = StatementEntry::query()
                ->where('payment_account_id', $account->id)
                ->whereIn('row_hash', array_column($lines, 'hash'))
                ->pluck('row_hash')
                ->flip();

            $imported = 0;
            $credit = 0;
            $debit = 0;

            foreach ($lines as $line) {
                if ($line['duplicate'] !== null || $existing->has($line['hash'])) {
                    continue;
                }

                $entry = StatementEntry::create([
                    'import_id' => $import->id,
                    'branch_id' => $account->branch_id,
                    'payment_account_id' => $account->id,
                    'value_date' => $line['date'],
                    'entry_direction' => $line['direction'],
                    'amount' => $line['amount'],
                    'utr' => $line['utr_normalized'],
                    'utr_normalized' => $line['utr_normalized'],
                    'description' => $line['description'],
                    'row_hash' => $line['hash'],
                    'status' => 'imported',
                    'raw' => array_filter(['row' => $line['row'], 'balance' => $line['balance']]),
                ]);

                $this->matcher->match($entry);
                $this->autoApprove->forLine($entry);

                $imported++;
                $line['direction'] === 'credit' ? $credit += $line['amount'] : $debit += $line['amount'];
            }

            $dates = array_column($lines, 'date');

            $import->forceFill([
                'file_id' => $file->id,
                'status' => 'completed',
                'period_from' => min($dates),
                'period_to' => max($dates),
                // The whole file; the lines not ticked are total − imported
                // − duplicate − failed.
                'rows_total' => $fileLines + count($parsed['errors']),
                'rows_imported' => $imported,
                'rows_duplicate' => count($lines) - $imported,
                'rows_failed' => count($parsed['errors']),
                'credit_total' => $credit,
                'debit_total' => $debit,
                'errors' => $parsed['errors'] === [] ? null : array_slice($parsed['errors'], 0, 100),
                'completed_at' => now(),
            ])->save();

            app(AlertDispatcher::class)->unsettledLines($account->branch_id, $import->entries()->whereIn('status', ['unmatched', 'duplicate'])->count(), $actor);

            AuditLog::record('statement.imported', $import, [], [
                'account' => $account->label,
                'file' => $originalName,
                'imported' => $imported,
                'duplicates' => count($lines) - $imported,
                'not_selected' => $fileLines - count($lines),
                'failed' => count($parsed['errors']),
            ], $actor);

            return $import;
        });

        Storage::disk('local')->delete($path);

        return $import;
    }

    /** One bank transfer on an account: its UTR, direction and amount. */
    private static function utrKey(string $utr, string $direction, int $amount): string
    {
        return $utr.'|'.$direction.'|'.$amount;
    }

    /**
     * An upload that was staged but never imported.
     */
    public function discard(string $path): void
    {
        if (str_starts_with($path, self::STAGING.'/')) {
            Storage::disk('local')->delete($path);
        }
    }
}
