<?php

namespace App\Http\Shared\Reconciliation;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reconciliation\Actions\ImportStatement;
use App\Domain\Reconciliation\Actions\UndoStatementImport;
use App\Domain\Reconciliation\Import\ColumnMapping;
use App\Domain\Reconciliation\Models\StatementImport;
use App\Domain\Reporting\Period;
use App\Http\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Statement import in three steps (upload → check the columns → tick the
 * lines to import) and
 * the Statement History of every file imported. The staged upload is kept
 * in the session under a random token until it is imported or cancelled.
 */
class StatementImportController extends Controller
{
    private const SESSION = 'statement_uploads';

    public function index(Request $request): Response
    {
        Gate::authorize('statements.view');

        $actor = $this->actor($request);
        $branch = $request->query('branch');
        $account = $request->query('account');

        // Imported in the period (Today by default, client request 2026-11-18).
        $period = Period::fromRequest($request);

        $imports = ReconciliationScope::imports($actor)
            ->where(fn (Builder $query) => $period->apply($query, 'statement_imports.created_at'))
            ->with(['paymentAccount', 'branch', 'file', 'importer', 'undoer'])
            ->when(is_string($branch) && $branch !== '' && $actor->isType(UserType::Admin), fn (Builder $query) => $query->where('branch_id', $branch))
            ->when(is_string($account) && $account !== '', fn (Builder $query) => $query->where('payment_account_id', $account))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $rows = [];

        foreach ($imports->items() as $import) {
            /** @var StatementImport $import */
            $rows[] = [
                'id' => $import->id,
                'created_at' => $import->created_at->toIso8601String(),
                'account' => $import->paymentAccount->label,
                'bank' => $import->paymentAccount->bank_name,
                'number' => $import->paymentAccount->maskedAccountNumber(),
                'branch' => $import->branch->name,
                'file' => $import->file?->original_name,
                'file_id' => $import->file_id,
                'period_from' => $import->period_from?->toDateString(),
                'period_to' => $import->period_to?->toDateString(),
                'status' => $import->status,
                'rows_total' => $import->rows_total,
                'rows_imported' => $import->rows_imported,
                'rows_duplicate' => $import->rows_duplicate,
                'rows_failed' => $import->rows_failed,
                'credit_total' => $import->credit_total,
                'debit_total' => $import->debit_total,
                'errors' => $import->errors ?? [],
                'imported_by' => $import->importer?->name,
                'undone_at' => $import->undone_at?->toIso8601String(),
                'undone_by' => $import->undoer?->name,
                'rows_removed' => $import->rows_removed,
            ];
        }

        return Inertia::render('statements/imports', [
            'portal' => $actor->type->value,
            'imports' => [...$imports->toArray(), 'data' => $rows],
            'filters' => ['branch' => is_string($branch) ? $branch : null, 'account' => is_string($account) ? $account : null],
            'period' => $period->toArray(),
            'accounts' => ReconciliationScope::accounts($actor)->with('branch')->orderBy('label')->get()
                ->map(fn ($account) => ['id' => $account->id, 'label' => $account->label, 'bank' => $account->bank_name, 'number' => $account->maskedAccountNumber(), 'branch_id' => $account->branch_id, 'branch' => $account->branch->name, 'active' => $account->status === AccountStatus::Active])
                ->values(),
            'branches' => $actor->isType(UserType::Admin) ? Branch::query()->orderBy('name')->get(['id', 'code', 'name']) : [],
            'can' => ['create' => $actor->can('statements.create'), 'undo' => $actor->isType(UserType::Admin) && $actor->can('statements.create')],
        ]);
    }

    /**
     * Step 1: keep the file and show its columns.
     */
    public function preview(Request $request, ImportStatement $import): RedirectResponse
    {
        Gate::authorize('statements.create');

        $actor = $this->actor($request);
        $request->validate([
            'payment_account_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,xls,xlsx'],
        ], ['file.extensions' => __('Upload the statement as CSV or Excel (.csv, .xls, .xlsx).')]);

        $account = ReconciliationScope::entryAccounts($actor)->find((string) $request->input('payment_account_id'));

        if ($account === null) {
            throw ValidationException::withMessages(['payment_account_id' => __('Choose one of your accounts.')]);
        }

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $path = $import->stage($file);

        try {
            $preview = $import->preview($path);
        } catch (ValidationException $exception) {
            $import->discard($path);

            throw $exception;
        }

        $token = Str::random(32);
        $request->session()->put(self::SESSION.'.'.$token, [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime' => (string) $file->getMimeType(),
            'account_id' => $account->id,
        ]);

        Inertia::flash('import_preview', [
            ...$preview,
            'token' => $token,
            'file' => $file->getClientOriginalName(),
            'account' => ['id' => $account->id, 'label' => $account->label, 'bank' => $account->bank_name, 'number' => $account->maskedAccountNumber()],
        ]);

        return back();
    }

    /**
     * Step 2: every line of the file with the confirmed columns, for the
     * branch to tick the ones to import (all unticked).
     */
    public function lines(Request $request, ImportStatement $import): RedirectResponse
    {
        Gate::authorize('statements.create');

        $actor = $this->actor($request);
        $data = $request->validate([
            'token' => ['required', 'string', 'size:32'],
            'mapping' => ['required', 'array'],
        ]);
        [$staged, $account] = $this->staged($request, $actor, $data['token']);

        $read = $import->lines($account, $staged['path'], ColumnMapping::fromArray($data['mapping']));

        Inertia::flash('import_lines', [
            'token' => $data['token'],
            'lines' => array_map(fn (array $line) => [
                'row' => $line['row'],
                'date' => $line['date'],
                'direction' => $line['direction'],
                'amount' => $line['amount'],
                'utr' => $line['utr_normalized'],
                'description' => $line['description'] === null ? null : mb_substr($line['description'], 0, 160),
                'duplicate' => $line['duplicate'],
                'check' => $line['check'],
                'transaction' => $line['transaction'],
            ], $read['lines']),
            'errors' => array_slice($read['errors'], 0, 200),
            'failed' => count($read['errors']),
            // Per rule (utr / date / amount), over the whole file.
            'failed_by' => (object) array_count_values(array_column($read['errors'], 'field')),
        ]);

        return back();
    }

    /**
     * Step 3: import the ticked lines.
     */
    public function store(Request $request, ImportStatement $import): RedirectResponse
    {
        Gate::authorize('statements.create');

        $actor = $this->actor($request);
        $data = $request->validate([
            'token' => ['required', 'string', 'size:32'],
            'layout' => ['nullable', 'string', 'max:100'],
            'mapping' => ['required', 'array'],
            'rows' => ['required', 'array', 'min:1', 'max:'.ImportStatement::MAX_ROWS],
            'rows.*' => ['integer', 'min:1'],
        ], ['rows.required' => __('Tick the lines to import.'), 'rows.min' => __('Tick the lines to import.')]);
        [$staged, $account] = $this->staged($request, $actor, $data['token']);

        $rows = array_values(array_map('intval', $data['rows']));
        $result = $import->handle($actor, $account, $staged['path'], $staged['name'], $staged['mime'], ColumnMapping::fromArray($data['mapping']), $rows, $data['layout'] ?? null);
        $request->session()->forget(self::SESSION.'.'.$data['token']);

        // What became of the lines: matched, or waiting in Unsettled UTR.
        $unsettled = $result->entries()->whereHas('openCase')->count();
        $matched = $result->rows_imported - $unsettled;

        Inertia::flash('toast', [
            'type' => $unsettled > 0 ? 'warning' : 'success',
            'message' => trim(__(':imported lines imported: :matched matched, :unsettled in Unsettled UTR (open it to see why).', [
                'imported' => $result->rows_imported,
                'matched' => $matched,
                'unsettled' => $unsettled,
            ]).($result->rows_duplicate > 0 ? ' '.__(':duplicates were already in PayGate and skipped.', ['duplicates' => $result->rows_duplicate]) : '')),
        ]);

        return back();
    }

    /**
     * Admin: remove the lines of a wrong import that nothing depends on
     * (UndoStatementImport).
     */
    public function undo(Request $request, StatementImport $statementImport, UndoStatementImport $undo): RedirectResponse
    {
        Gate::authorize('statements.create');

        $actor = $this->actor($request);
        abort_unless($actor->isType(UserType::Admin), 403);

        $result = $undo->handle($actor, $statementImport);

        Inertia::flash('toast', [
            'type' => $result['kept'] > 0 ? 'warning' : 'success',
            'message' => $result['kept'] > 0
                ? __(':removed lines removed. :kept kept: they are linked to a deposit / payout or their case was already resolved.', $result)
                : __(':removed lines removed with their Unsettled UTR cases.', $result),
        ]);

        return back();
    }

    public function cancel(Request $request, string $token, ImportStatement $import): RedirectResponse
    {
        /** @var array{path: string}|null $staged */
        $staged = $request->session()->pull(self::SESSION.'.'.$token);

        if ($staged !== null) {
            $import->discard($staged['path']);
        }

        return back();
    }

    /**
     * The staged upload of this token and its account (still in scope).
     *
     * @return array{0: array{path: string, name: string, mime: string, account_id: string}, 1: PaymentAccount}
     */
    private function staged(Request $request, User $actor, string $token): array
    {
        /** @var array{path: string, name: string, mime: string, account_id: string}|null $staged */
        $staged = $request->session()->get(self::SESSION.'.'.$token);
        $account = $staged === null ? null : ReconciliationScope::entryAccounts($actor)->find($staged['account_id']);

        if ($staged === null || ! $account instanceof PaymentAccount) {
            throw ValidationException::withMessages(['file' => __('The upload has expired. Choose the file again.')]);
        }

        return [$staged, $account];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
