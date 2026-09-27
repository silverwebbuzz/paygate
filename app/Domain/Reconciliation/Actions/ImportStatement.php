<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Reconciliation\Import\ColumnMapping;
use App\Domain\Reconciliation\Import\StatementFile;
use App\Domain\Reconciliation\Import\StatementParser;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\Models\StatementImport;
use App\Domain\Reconciliation\Models\StatementTemplate;
use App\Domain\Reconciliation\StatementMatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Statement import (Req 19; decided 2026-09-28, G-27): any CSV / Excel file
 * of one branch account, in two steps.
 *
 * 1. stage + preview: the file is kept privately and its columns shown,
 *    with a mapping guessed from the headers, or the saved mapping of the
 *    same layout (StatementTemplate).
 * 2. handle: every line is read with the confirmed mapping, stored once
 *    (lines already in the statement are counted as duplicates, never
 *    stored twice) and matched. All or nothing, in one database
 *    transaction. The mapping is remembered for the layout.
 */
class ImportStatement
{
    public const MAX_ROWS = 5000;

    private const STAGING = 'statement-uploads/pending';

    public function __construct(private StatementMatcher $matcher) {}

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

    public function handle(User $actor, PaymentAccount $account, string $path, string $originalName, string $mime, ColumnMapping $mapping, ?string $layoutName = null): StatementImport
    {
        $absolute = Storage::disk('local')->path($path);
        $sha = (string) hash_file('sha256', $absolute);

        $previous = StatementImport::query()->where(['payment_account_id' => $account->id, 'file_sha256' => $sha])->first();

        if ($previous !== null) {
            throw ValidationException::withMessages(['file' => __('This file was already imported for this account on :date.', ['date' => $previous->created_at->timezone(config('app.business_timezone'))->format('d M Y, H:i')])]);
        }

        $rows = StatementFile::rows($absolute, $mapping->headerRow + self::MAX_ROWS + 1);

        if (count($rows) > $mapping->headerRow + self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('The file has more than :max lines. Split it by date and import the parts.', ['max' => number_format(self::MAX_ROWS)])]);
        }

        $parsed = StatementParser::parse($rows, $mapping);

        if ($parsed['lines'] === []) {
            throw ValidationException::withMessages(['mapping' => $parsed['errors'] === []
                ? __('No credit or debit lines were found with these columns.')
                : __('No line could be read. First problem, row :row: :message', $parsed['errors'][0])]);
        }

        $headers = StatementParser::headers($rows, $mapping->headerRow);

        $import = DB::transaction(function () use ($actor, $account, $path, $originalName, $mime, $mapping, $layoutName, $sha, $parsed, $headers) {
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

            // The 2nd, 3rd… identical line of the file gets its own key.
            $seen = [];
            $lines = [];

            foreach ($parsed['lines'] as $line) {
                $base = StatementEntry::rowHash($account->id, $line['date'], $line['direction'], $line['amount'], $line['utr_normalized'], $line['description']);
                $seen[$base] = ($seen[$base] ?? 0) + 1;
                $lines[] = [...$line, 'hash' => StatementEntry::rowHash($account->id, $line['date'], $line['direction'], $line['amount'], $line['utr_normalized'], $line['description'], $seen[$base])];
            }

            $existing = StatementEntry::query()
                ->where('payment_account_id', $account->id)
                ->whereIn('row_hash', array_column($lines, 'hash'))
                ->pluck('row_hash')
                ->flip();

            $imported = 0;
            $credit = 0;
            $debit = 0;

            foreach ($lines as $line) {
                if ($existing->has($line['hash'])) {
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

                $imported++;
                $line['direction'] === 'credit' ? $credit += $line['amount'] : $debit += $line['amount'];
            }

            $dates = array_column($lines, 'date');

            $import->forceFill([
                'file_id' => $file->id,
                'status' => 'completed',
                'period_from' => min($dates),
                'period_to' => max($dates),
                'rows_total' => count($lines) + count($parsed['errors']),
                'rows_imported' => $imported,
                'rows_duplicate' => count($lines) - $imported,
                'rows_failed' => count($parsed['errors']),
                'credit_total' => $credit,
                'debit_total' => $debit,
                'errors' => $parsed['errors'] === [] ? null : array_slice($parsed['errors'], 0, 100),
                'completed_at' => now(),
            ])->save();

            AuditLog::record('statement.imported', $import, [], [
                'account' => $account->label,
                'file' => $originalName,
                'imported' => $imported,
                'duplicates' => count($lines) - $imported,
                'failed' => count($parsed['errors']),
            ], $actor);

            return $import;
        });

        Storage::disk('local')->delete($path);

        return $import;
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
