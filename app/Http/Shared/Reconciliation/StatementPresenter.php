<?php

namespace App\Http\Shared\Reconciliation;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reconciliation\Enums\CaseType;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Shared\Transactions\TransactionController;
use App\Http\Shared\Transactions\TransactionPresenter;
use Carbon\CarbonImmutable;

/**
 * Statement lines and reconciliation cases as the Admin and branch portals
 * show them (design: Manual A/C Statement table and drawer).
 */
class StatementPresenter
{
    /** Relations entry() reads; eager-load them for lists. */
    public const WITH = ['paymentAccount', 'branch', 'creator', 'import.file', 'import.importer', 'transaction.branch', 'transaction.decider', 'transaction.partner', 'cases.transaction.branch'];

    public function __construct(private TransactionPresenter $transactions) {}

    /**
     * @return array<string, mixed>
     */
    public function entry(StatementEntry $entry, User $viewer): array
    {
        $txn = $entry->transaction;
        $case = $entry->cases->last();
        $pointsAt = $txn ?? $case?->transaction;

        return [
            'id' => $entry->id,
            'value_date' => $entry->value_date->toDateString(),
            'direction' => $entry->entry_direction,
            'amount' => $entry->amount,
            'utr' => $entry->utr_normalized,
            'description' => $entry->description,
            'status' => $entry->status,
            'display' => match ($entry->status) {
                'matched' => $txn?->status === 'under_review' ? 'hold' : 'pending',
                'reconciled' => 'approved',
                'ignored' => 'ignored',
                default => 'unsettled',
            },
            'account' => $this->account($entry->paymentAccount),
            'branch' => ['code' => $entry->branch->code, 'name' => $entry->branch->name],
            'source' => $entry->import_id === null ? 'manual' : 'import',
            'entered_by' => $entry->import_id === null ? $entry->creator?->name : $entry->import?->importer?->name,
            'file' => $entry->import?->file?->original_name,
            'created_at' => $entry->created_at->toIso8601String(),
            'matched_at' => $entry->matched_at?->toIso8601String(),
            'transaction' => $pointsAt === null ? null : $this->transaction($pointsAt, $viewer),
            'linked' => $txn !== null,
            // Design "branch match": the bank line's branch vs the branch of
            // the transaction it is linked to (or its case points at).
            'branch_match' => match (true) {
                $pointsAt === null => 'none',
                $pointsAt->branch_id === $entry->branch_id => 'matched',
                default => 'mismatch',
            },
            'case' => $case === null ? null : $this->caseSummary($case),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function caseRow(ReconciliationCase $case, User $viewer): array
    {
        return [
            ...$this->caseSummary($case),
            'branch' => ['code' => $case->branch->code, 'name' => $case->branch->name],
            'entry' => $case->entry === null ? null : $this->entry($case->entry, $viewer),
            'resolved_by' => $case->resolver->name ?? ($case->resolved_at !== null ? 'System' : null),
            'resolved_at' => $case->resolved_at?->toIso8601String(),
            'created_at' => $case->created_at?->toIso8601String(),
            'can' => [
                'resolve' => $case->isOpen() && $viewer->can('reconciliation.resolve'),
                'approve_late' => $case->isOpen() && $viewer->isType(UserType::Admin) && $viewer->can('reconciliation.resolve') && $viewer->can('payins.approve'),
            ],
        ];
    }

    /**
     * What the statement line drawer adds: its cases and audit trail.
     *
     * @return array<string, mixed>
     */
    public function entryDetail(StatementEntry $entry, User $viewer): array
    {
        return [
            'id' => $entry->id,
            'transaction' => $entry->transaction === null ? null : $this->transactions->row($entry->transaction, $viewer),
            'cases' => $entry->cases->map(fn (ReconciliationCase $case) => [
                ...$this->caseSummary($case),
                'resolved_by' => $case->resolver->name ?? ($case->resolved_at !== null ? 'System' : null),
                'resolved_at' => $case->resolved_at?->toIso8601String(),
                'created_at' => $case->created_at?->toIso8601String(),
            ])->values()->all(),
            'audit' => AuditLog::query()
                ->where(fn ($query) => $query
                    ->where(['subject_type' => 'statement_entry', 'subject_id' => $entry->id])
                    ->orWhere(fn ($query) => $query->where('subject_type', 'reconciliation_case')->whereIn('subject_id', $entry->cases->pluck('id'))))
                ->with('actor')
                ->orderBy('created_at')
                ->get()
                ->map(fn (AuditLog $log) => [
                    'action' => $log->action,
                    'actor' => $log->actor->name ?? 'System',
                    'new' => $log->new_values,
                    'at' => $log->created_at->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /**
     * Transactions a person could link a case's bank line to: same account
     * (deposits) or branch (payouts), exact amount, around the line's date,
     * not linked elsewhere. `$reference` finds one by our id instead.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(ReconciliationCase $case, User $viewer, ?string $reference = null): array
    {
        $entry = $case->entry;

        if ($entry === null || ! $case->isOpen()) {
            return [];
        }

        $credit = $entry->isCredit();
        $from = CarbonImmutable::parse($entry->value_date->toDateString(), config('app.business_timezone'))->subDays(10)->utc();
        $to = CarbonImmutable::parse($entry->value_date->toDateString(), config('app.business_timezone'))->addDays(4)->utc();

        $query = TransactionController::scoped($viewer)
            ->with(['partner', 'branch', 'customer'])
            ->where('direction', $credit ? 'payin' : 'payout')
            ->where($credit ? 'payment_account_id' : 'branch_id', $credit ? $entry->payment_account_id : $entry->branch_id)
            ->whereDoesntHave('statementEntry');

        $query = $reference !== null && trim($reference) !== ''
            ? $query->where(fn ($query) => TransactionController::search($query, trim($reference)))
            : $query->where('amount', $entry->amount)
                ->whereIn('status', $credit ? ['payment_submitted', 'payment_detected', 'under_review', 'success', 'rejected', 'expired'] : ['success'])
                ->whereBetween('created_at', [$from, $to]);

        return array_values($query->orderByDesc('created_at')->limit(20)->get()
            ->map(fn (Transaction $txn) => [
                ...$this->transaction($txn, $viewer),
                'late' => in_array($txn->status, ['rejected', 'expired'], true),
                'same_amount' => $txn->amount === $entry->amount,
            ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function caseSummary(ReconciliationCase $case): array
    {
        return [
            'id' => $case->id,
            'reference' => $case->reference,
            'type' => $case->type->value,
            'type_label' => $case->type->label(),
            'status' => $case->status,
            'resolution' => $case->resolution?->value,
            'resolution_label' => $case->resolution?->label(),
            'notes' => $case->notes,
            'late' => $case->type === CaseType::LatePayment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transaction(Transaction $txn, User $viewer): array
    {
        return [
            'id' => $txn->id,
            'reference' => $txn->reference,
            'direction' => $txn->direction,
            'amount' => $txn->amount,
            'status' => $txn->status,
            'partner' => $txn->partner->code,
            'branch' => $txn->branch?->code,
            'customer_utr' => $txn->customer_utr_normalized,
            'bank_utr' => $txn->bank_utr_normalized,
            'decided_by' => $txn->decider?->name,
            'created_at' => $txn->created_at?->toIso8601String(),
            'submitted_at' => $txn->submitted_at?->toIso8601String(),
            'decided_at' => $txn->decided_at?->toIso8601String(),
            'can_decide' => $viewer->can('decide', $txn),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function account(PaymentAccount $account): array
    {
        return [
            'id' => $account->id,
            'label' => $account->label,
            'bank' => $account->bank_name,
            'number' => $account->maskedAccountNumber(),
            'upi' => $account->maskedUpiId(),
        ];
    }
}
