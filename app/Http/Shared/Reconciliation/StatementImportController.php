<?php

namespace App\Http\Shared\Reconciliation;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reconciliation\Actions\ImportStatement;
use App\Domain\Reconciliation\Import\ColumnMapping;
use App\Domain\Reconciliation\Models\StatementImport;
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

        $imports = ReconciliationScope::imports($actor)
            ->with(['paymentAccount', 'branch', 'file', 'importer'])
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
            ];
        }

        return Inertia::render('statements/imports', [
            'portal' => $actor->type->value,
            'imports' => [...$imports->toArray(), 'data' => $rows],
            'filters' => ['branch' => is_string($branch) ? $branch : null, 'account' => is_string($account) ? $account : null],
            'accounts' => ReconciliationScope::accounts($actor)->with('branch')->orderBy('label')->get()
                ->map(fn ($account) => ['id' => $account->id, 'label' => $account->label, 'bank' => $account->bank_name, 'number' => $account->maskedAccountNumber(), 'branch_id' => $account->branch_id, 'branch' => $account->branch->name])
                ->values(),
            'branches' => $actor->isType(UserType::Admin) ? Branch::query()->orderBy('name')->get(['id', 'code', 'name']) : [],
            'can' => ['create' => $actor->can('statements.create')],
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

        $account = ReconciliationScope::accounts($actor)->find((string) $request->input('payment_account_id'));

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
                'transaction' => $line['transaction'],
            ], $read['lines']),
            'errors' => array_slice($read['errors'], 0, 50),
            'failed' => count($read['errors']),
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

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':imported lines imported, :duplicates already in PayGate. Matched lines are ready to approve; the rest are in the unsettled queue.', [
                'imported' => $result->rows_imported,
                'duplicates' => $result->rows_duplicate,
            ]),
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
        $account = $staged === null ? null : ReconciliationScope::accounts($actor)->find($staged['account_id']);

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
