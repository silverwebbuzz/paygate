<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\Models\StatementImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undo import (client report 2026-11-18: a statement imported into the
 * wrong account filled Unsettled UTR with duplicates). Admin removes the
 * lines of one import that nothing depends on, with their open cases:
 *
 * - removed: lines not linked to a deposit / payout, whose cases are all
 *   still open and not used by a settlement adjustment;
 * - kept: everything else (linked, reconciled, or resolved by someone),
 *   so no approval, ledger entry or decision is ever undone.
 *
 * The import stays in Statement History, marked as undone (who, when, how
 * many removed); the file stays downloadable. Audited.
 */
class UndoStatementImport
{
    /**
     * @return array{removed: int, kept: int}
     */
    public function handle(User $actor, StatementImport $import): array
    {
        if ($import->undone_at !== null) {
            throw ValidationException::withMessages(['import' => __('This import was already undone.')]);
        }

        return DB::transaction(function () use ($actor, $import) {
            /** @var StatementImport $locked */
            $locked = StatementImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();

            $removable = $locked->entries()
                ->whereNull('transaction_id')
                ->whereNull('payout_payment_id')
                ->whereIn('status', ['imported', 'unmatched', 'duplicate'])
                ->whereDoesntHave('cases', fn (Builder $cases) => $cases
                    ->where('status', 'resolved')
                    ->orWhereExists(fn ($query) => $query->selectRaw('1')->from('adjustments')->whereColumn('adjustments.case_id', 'reconciliation_cases.id')))
                ->lockForUpdate()
                ->pluck('id');

            $total = $locked->entries()->count();

            ReconciliationCase::query()->whereIn('statement_entry_id', $removable)->delete();
            StatementEntry::query()->whereIn('id', $removable)->delete();

            $removed = $removable->count();
            $locked->forceFill([
                'undone_at' => now(),
                'undone_by' => $actor->id,
                'rows_removed' => $removed,
            ])->save();

            AuditLog::record('statement.import_undone', $locked, [], [
                'account' => $locked->paymentAccount->label,
                'file' => $locked->file?->original_name,
                'removed' => $removed,
                'kept' => $total - $removed,
            ], $actor);

            return ['removed' => $removed, 'kept' => $total - $removed];
        });
    }
}
