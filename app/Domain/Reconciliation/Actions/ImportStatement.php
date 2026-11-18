<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Payout\Models\PayoutPayment;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Reconciliation\Enums\Resolution;
use App\Domain\Reconciliation\Import\ColumnMapping;
use App\Domain\Reconciliation\Import\StatementFile;
use App\Domain\Reconciliation\Import\StatementParser;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\Models\StatementImport;
use App\Domain\Reconciliation\Models\StatementTemplate;
use App\Domain\Reconciliation\StatementMatcher;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Utr;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
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
     * @return array{lines: list<array{row: int, date: string, direction: string, amount: int, utr_normalized: ?string, description: ?string, balance: ?string, hash: string, duplicate: ?string, check: ?array{level: string, message: string}, transaction: ?array{reference: string, status: string}}>, errors: list<array{row: int, message: string, text: string}>, headers: list<string>}
     */
    public function lines(PaymentAccount $account, string $path, ColumnMapping $mapping): array
    {
        $rows = StatementFile::rows(Storage::disk('local')->path($path), $mapping->headerRow + self::MAX_ROWS + 1);

        if (count($rows) > $mapping->headerRow + self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('The file has more than :max lines. Split it by date and import the parts.', ['max' => number_format(self::MAX_ROWS)])]);
        }

        $parsed = StatementParser::parse($rows, $mapping);
        $this->ensureColumnsFit($parsed, $mapping, StatementParser::headers($rows, $mapping->headerRow));

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

        $utrs = array_values(array_unique(array_column($lines, 'utr_normalized')));
        $stored = collect();
        $hashes = collect();
        /** @var Collection<int, Transaction> $transactions */
        $transactions = collect();
        /** @var Collection<int, PayoutPayment> $parts */
        $parts = collect();
        /** @var Collection<int, array{0: string, 1: string, 2: string}> $elsewhere UTR, utrKey, account of lines already stored */
        $elsewhere = collect();

        foreach (array_chunk($utrs, 1000) as $chunk) {
            $stored = $stored->merge(StatementEntry::query()
                ->where('payment_account_id', $account->id)
                ->whereIn('utr_normalized', $chunk)
                ->get(['utr_normalized', 'entry_direction', 'amount'])
                ->map(fn (StatementEntry $entry) => self::utrKey((string) $entry->utr_normalized, $entry->entry_direction, $entry->amount)));

            // Every deposit / payout carrying these UTRs, any account, to say
            // before the import what will happen to each line.
            $transactions = $transactions->merge(Transaction::query()
                ->where(fn ($query) => $query->whereIn('bank_utr_normalized', $chunk)->orWhereIn('customer_utr_normalized', $chunk))
                ->orderBy('created_at')
                ->get(['id', 'reference', 'status', 'direction', 'amount', 'branch_id', 'payment_account_id', 'bank_utr_normalized', 'customer_utr_normalized']));
            $parts = $parts->merge(PayoutPayment::query()->whereIn('utr_normalized', $chunk)->with('transaction:id,reference,branch_id')->get());
            // As StatementMatcher::lineWithUtr counts them: a line closed as
            // "not a customer payment" doesn't hold its UTR.
            $elsewhere = $elsewhere->merge(StatementEntry::query()
                ->whereIn('utr_normalized', $chunk)
                ->where(fn ($query) => $query
                    ->where('status', '!=', 'ignored')
                    ->orWhereDoesntHave('cases', fn ($cases) => $cases->where('resolution', Resolution::Rejected->value)))
                ->get(['id', 'utr_normalized', 'payment_account_id', 'entry_direction', 'amount', 'status'])
                ->map(fn (StatementEntry $entry) => [(string) $entry->utr_normalized, self::utrKey((string) $entry->utr_normalized, $entry->entry_direction, $entry->amount), $entry->payment_account_id]));
        }

        foreach (array_chunk(array_column($lines, 'hash'), 1000) as $chunk) {
            $hashes = $hashes->merge(StatementEntry::query()->where('payment_account_id', $account->id)->whereIn('row_hash', $chunk)->pluck('row_hash'));
        }

        $stored = $stored->flip();
        $hashes = $hashes->flip();
        $inFile = [];
        $inFileUtr = [];

        foreach ($lines as &$line) {
            // Every line has a UTR (StatementParser reports those without).
            $key = self::utrKey($line['utr_normalized'], $line['direction'], $line['amount']);

            // Never stored twice (client report 2026-11-18): this account's
            // own line ("stored"), the UTR on any other bank line in PayGate,
            // another account or branch or amount ("elsewhere": one UTR is
            // one bank transfer), or twice in this file ("repeated").
            $line['duplicate'] = match (true) {
                $hashes->has($line['hash']) || $stored->has($key) => 'stored',
                $elsewhere->contains(fn (array $other) => $other[0] === $line['utr_normalized']) => 'elsewhere',
                isset($inFile[$key]) => 'repeated',
                default => null,
            };
            // An earlier line of this file with the same UTR but another
            // amount (e.g. a bank charge): a "Duplicate UTR" case if both
            // are imported (StatementMatcher::twin).
            $twin = $line['duplicate'] === null && isset($inFileUtr[$line['utr_normalized']]) ? 'file' : null;
            [$line['check'], $line['transaction']] = $line['duplicate'] !== null
                ? [null, null]
                : $this->check($line, $account, $transactions, $parts, $twin);

            $inFileUtr[$line['utr_normalized']] = true;
            $inFile[$key] = true;
        }

        unset($line);

        // The cells of each unreadable row, so the branch sees which it is.
        $errors = array_map(fn (array $error) => [
            ...$error,
            'text' => mb_substr(implode(' · ', array_filter(array_map(fn ($cell) => StatementParser::text($cell), $rows[$error['row'] - 1] ?? []), fn (string $cell) => $cell !== '')), 0, 200),
        ], $parsed['errors']);

        return ['lines' => $lines, 'errors' => $errors, 'headers' => StatementParser::headers($rows, $mapping->headerRow)];
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
            $taken = StatementEntry::query()
                ->whereIn('utr_normalized', array_column($lines, 'utr_normalized'))
                ->where(fn ($query) => $query
                    ->where('status', '!=', 'ignored')
                    ->orWhereDoesntHave('cases', fn ($cases) => $cases->where('resolution', Resolution::Rejected->value)))
                ->pluck('utr_normalized')
                ->flip();

            $imported = 0;
            $credit = 0;
            $debit = 0;

            foreach ($lines as $line) {
                if ($line['duplicate'] !== null || $existing->has($line['hash']) || $taken->has($line['utr_normalized'])) {
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

    /**
     * What the import will do with a line, as StatementMatcher decides it,
     * said before the branch ticks it (client request 2026-11-18): ok (it
     * will match) or warn (it will go to Unsettled UTR, and why). Also the
     * deposit / payout the line points at, if any.
     *
     * @param  array{direction: string, amount: int, utr_normalized: string}  $line
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, PayoutPayment>  $parts
     * @param  'file'|null  $twin  an earlier line of the file with this UTR
     * @return array{0: array{level: string, message: string}, 1: ?array{reference: string, status: string}}
     */
    private function check(array $line, PaymentAccount $account, Collection $transactions, Collection $parts, ?string $twin): array
    {
        $utr = $line['utr_normalized'];
        $rupees = fn (int $paise) => '₹'.number_format($paise / 100, 2);
        $warn = fn (string $message) => ['level' => 'warn', 'message' => $message];
        $ref = fn (Transaction $txn) => ['reference' => $txn->reference, 'status' => $txn->status];

        if ($twin === 'file') {
            return [$warn(__('An earlier line of this file has the same UTR (e.g. a bank charge): if both are imported, this one goes to Unsettled UTR as a duplicate UTR.')), null];
        }

        if ($line['direction'] === 'debit') {
            $part = $parts->first(fn (PayoutPayment $part) => $part->utr_normalized === $utr && $part->branch_id === $account->branch_id);
            $payout = $part !== null ? $part->transaction : $transactions->first(fn (Transaction $txn) => $txn->direction === 'payout' && $txn->branch_id === $account->branch_id && $txn->bank_utr_normalized === $utr);

            if ($payout === null) {
                return [$warn(__('No payout of this branch has this UTR: it will go to Unsettled UTR. Untick it if it is not a PayGate payout.')), null];
            }

            $amount = $part !== null ? $part->amount : $payout->amount;

            return $amount === $line['amount']
                ? [['level' => 'ok', 'message' => __('Matches payout :reference.', ['reference' => $payout->reference])], $ref($payout)]
                : [$warn(__('Payout :reference was paid as :txn; the bank shows :bank: it will go to Unsettled UTR (amount mismatch).', ['reference' => $payout->reference, 'txn' => $rupees($amount), 'bank' => $rupees($line['amount'])])), $ref($payout)];
        }

        $payins = $transactions->filter(fn (Transaction $txn) => $txn->direction === 'payin' && ($txn->bank_utr_normalized === $utr || $txn->customer_utr_normalized === $utr));
        $own = $payins->where('payment_account_id', $account->id);
        $live = $own->filter(fn (Transaction $txn) => in_array($txn->status, ['payment_submitted', 'payment_detected', 'under_review'], true)
            || ($txn->status === 'success' && $txn->bank_utr_normalized === $utr));

        if ($live->count() > 1) {
            return [$warn(__('Several deposits claim this UTR (:references): it will go to Unsettled UTR.', ['references' => $live->pluck('reference')->join(', ')])), $ref($live->firstOrFail())];
        }

        if ($live->count() === 1) {
            $txn = $live->firstOrFail();

            return $txn->amount === $line['amount']
                ? [['level' => 'ok', 'message' => $txn->status === 'success'
                    ? __('Matches :reference (already approved): it will be reconciled.', ['reference' => $txn->reference])
                    : __('Matches :reference (:amount): it will be matched to the deposit.', ['reference' => $txn->reference, 'amount' => $rupees($txn->amount)])], $ref($txn)]
                : [$warn(__(':reference is for :txn; the bank shows :bank: it will go to Unsettled UTR (amount mismatch).', ['reference' => $txn->reference, 'txn' => $rupees($txn->amount), 'bank' => $rupees($line['amount'])])), $ref($txn)];
        }

        $closed = $own->first(fn (Transaction $txn) => in_array($txn->status, ['rejected', 'expired', 'cancelled'], true));

        if ($closed !== null) {
            return [$warn(__(':reference is :status: it will go to Unsettled UTR as a late payment.', ['reference' => $closed->reference, 'status' => str_replace('_', ' ', $closed->status)])), $ref($closed)];
        }

        if ($own->isNotEmpty()) {
            $txn = $own->firstOrFail();

            return [$warn(__(':reference has this UTR but is :status: it will go to Unsettled UTR.', ['reference' => $txn->reference, 'status' => str_replace('_', ' ', $txn->status)])), $ref($txn)];
        }

        if ($payins->isNotEmpty()) {
            return [$warn($payins->contains('branch_id', $account->branch_id)
                ? __('A deposit with this UTR is on another account of this branch: it may be moved here automatically, otherwise it goes to Unsettled UTR. Check the account you are importing for.')
                : __('A deposit with this UTR belongs to another branch: it goes to Unsettled UTR, where Admin can move it.')), null];
        }

        return [$warn(__('No deposit has this UTR yet: it will go to Unsettled UTR, and links itself if the customer submits the UTR later.')), null];
    }

    /**
     * When one field fails on most rows, the column chosen for it is wrong
     * (client request 2026-11-18): say so once, with examples and what the
     * column must hold, instead of listing every row.
     *
     * @param  array{lines: list<array<string, mixed>>, errors: list<array{row: int, field: string, value: string, message: string}>}  $parsed
     * @param  list<string>  $headers
     */
    private function ensureColumnsFit(array $parsed, ColumnMapping $mapping, array $headers): void
    {
        $rows = count($parsed['lines']) + count($parsed['errors']);
        $name = function (?int $column) use ($headers): string {
            if ($column === null) {
                return '';
            }

            $letter = '';

            for ($n = $column + 1; $n > 0; $n = intdiv($n - 1, 26)) {
                $letter = chr(65 + ($n - 1) % 26).$letter;
            }

            return trim($letter.' “'.($headers[$column] ?? '').'”');
        };

        foreach (['date', 'amount', 'utr'] as $field) {
            $failed = array_values(array_filter($parsed['errors'], fn (array $error) => $error['field'] === $field));

            if (count($failed) < 3 || count($failed) * 2 < $rows) {
                continue;
            }

            $examples = collect($failed)->pluck('value')->filter()->unique()->take(3)->map(fn (string $value) => '“'.mb_substr($value, 0, 30).'”')->join(', ');
            $share = __(':failed of :rows rows', ['failed' => count($failed), 'rows' => $rows]);

            $message = match ($field) {
                'date' => __('The date column :column doesn’t hold dates in the format :format (:share fail, e.g. :examples). Choose the date column, and the date format exactly as written in the file.', [
                    'column' => $name($mapping->date), 'format' => $mapping->dateFormat, 'share' => $share, 'examples' => $examples ?: '—',
                ]),
                'amount' => __('The amount column(s) :column don’t hold amounts (:share fail, e.g. :examples). Amounts look like 1,234.50, ₹500 or 500.00 Cr. Choose the credit / debit columns, or one amount column.', [
                    'column' => collect([$mapping->amount, $mapping->credit, $mapping->debit])->filter(fn (?int $column) => $column !== null)->map($name)->join(', '), 'share' => $share, 'examples' => $examples ?: '—',
                ]),
                default => $mapping->utr === null
                    ? __('No UTR was found in :share: no UTR column is chosen and the narration has none. Choose the column with the UTR. :rule', ['share' => $share, 'rule' => Utr::RULE])
                    : __('The UTR column :column doesn’t hold UTRs (:share fail, e.g. :examples), and the narration has none either. :rule Choose the column with the UTR, or the narration. Some exports have no UTR at all (e.g. Google Pay’s Excel file): download the CSV instead.', [
                        'column' => $name($mapping->utr), 'share' => $share, 'examples' => $examples ?: '—', 'rule' => Utr::RULE,
                    ]),
            };

            $key = match ($field) {
                'amount' => $mapping->amount !== null ? 'mapping.amount' : 'mapping.credit',
                default => 'mapping.'.$field,
            };

            throw ValidationException::withMessages(['mapping' => $message, $key => __('This column doesn’t fit: see the message above.')]);
        }
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
