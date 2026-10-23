<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Payout\Models\PayoutPayment;
use App\Domain\Payout\PayoutReservation;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Platform\SectionRollout;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Domain\Webhook\Models\WebhookAttempt;
use App\Domain\Webhook\Models\WebhookEvent;

/**
 * Transactions as each portal may see them:
 * - partners never see branch, account or branch commission details;
 * - branches never see the partner's rate or the platform margin;
 * - Admin sees everything.
 */
class TransactionPresenter
{
    /** @var array<string, bool> Per viewer and section: open under Section rollout? */
    private array $open = [];

    public function __construct(private Ledger $ledger, private SectionRollout $rollout) {}

    /**
     * @return array<string, mixed>
     */
    public function row(Transaction $txn, User $viewer): array
    {
        $type = $viewer->type;
        $payout = $txn->direction === 'payout';
        $returns = $payout && $this->sectionOpen($viewer, 'return');
        $chargebacks = ! $payout && $this->sectionOpen($viewer, 'chargeback');
        $partnerFee = $payout ? PayoutReservation::fee($txn) : $txn->partner_commission;
        // Pay-in: what the partner is credited (amount − fee). Payout: what
        // the partner is charged (amount + fee). Branches see their side.
        $net = match ($type) {
            UserType::Branch => $txn->branch_commission === null ? null : ($payout ? $txn->amount + $txn->branch_commission : $txn->amount - $txn->branch_commission),
            default => $partnerFee === null ? null : ($payout ? $txn->amount + $partnerFee : $txn->amount - $partnerFee),
        };

        return [
            'id' => $txn->id,
            'reference' => $txn->reference,
            'direction' => $txn->direction,
            'order_id' => $txn->partner_transaction_id,
            'partner' => $type === UserType::Partner ? null : ['name' => $txn->partner->name, 'code' => $txn->partner->code],
            'branch' => $type === UserType::Partner || $txn->branch === null ? null : ['name' => $txn->branch->name, 'code' => $txn->branch->code],
            'account' => $type === UserType::Partner || $txn->paymentAccount === null ? null : [
                'label' => $txn->paymentAccount->label,
                'bank' => $txn->paymentAccount->bank_name,
                'number' => $txn->paymentAccount->maskedAccountNumber(),
                'upi' => $txn->paymentAccount->maskedUpiId(),
            ],
            'beneficiary' => $payout && $txn->beneficiary ? [
                'type' => $txn->beneficiary->type,
                'name' => $txn->beneficiary->account_holder_name,
                'masked' => $txn->beneficiary->masked(),
            ] : null,
            'customer' => $txn->customer ? [
                'id' => $txn->customer->external_id,
                'name' => $txn->customer->name,
                'mobile' => $txn->customer->mobile,
            ] : null,
            'amount' => $txn->amount,
            'order_amount' => $txn->order_amount,
            'net' => $net,
            'method' => $txn->method,
            'status' => $txn->status,
            'customer_utr' => $txn->customer_utr_normalized,
            'bank_utr' => $txn->bank_utr_normalized,
            'reason_code' => $txn->status_reason_code,
            'note' => $txn->status_note,
            'created_at' => $txn->created_at?->toIso8601String(),
            'submitted_at' => $txn->submitted_at?->toIso8601String(),
            'decided_at' => $txn->decided_at?->toIso8601String(),
            'decided_by' => $type === UserType::Partner ? null : $txn->decider?->name,
            'expires_at' => $txn->expires_at?->toIso8601String(),
            'origin' => $txn->origin,
            // When the customer first opened our payment page (the pay link).
            'link_opened_at' => $txn->session?->opened_at?->toIso8601String(),
            'proof_file_id' => $type === UserType::Partner ? null : $txn->latestProof?->id,
            // The bank statement line that confirms it (never shown to partners).
            'bank_line' => $type === UserType::Partner || $txn->statementEntry === null ? null : [
                'status' => $txn->statementEntry->status,
                'utr' => $txn->statementEntry->utr_normalized,
                'amount' => $txn->statementEntry->amount,
                'value_date' => $txn->statementEntry->value_date->toDateString(),
                // The bank's narration, which usually names the payer.
                'description' => $txn->statementEntry->description,
            ],
            'chargeback' => $chargebacks ? $this->chargeback($txn, $viewer) : null,
            'payout_return' => $returns ? $this->payoutReturn($txn, $viewer) : null,
            // Payouts: each bank transfer, and how much is paid so far.
            'payments' => $this->payments($txn, $viewer),
            'paid_amount' => $payout ? (int) $txn->payoutPayments->sum('amount') : null,
            'can' => [
                'decide' => $viewer->can('decide', $txn),
                'process' => $viewer->can('process', $txn),
                'chargeback' => $chargebacks
                    && $type !== UserType::Partner
                    && $txn->direction === 'payin'
                    && $txn->status === 'success'
                    && ! in_array($txn->chargeback_status, ['requested', 'approved'], true)
                    && $viewer->can('reversals.create')
                    && $viewer->can('view', $txn),
                // Payout returns: raise (partner / Admin) or record (branch)
                // on a paid payout with none open; confirm / reject a raised one.
                'return_raise' => $returns && $this->returnable($txn) && $viewer->can('raiseReturn', $txn),
                'return_record' => $returns && $this->returnable($txn) && $viewer->can('recordReturn', $txn),
                'return_decide' => $returns
                    && $txn->return_status === 'requested'
                    && $viewer->can('decideReturn', $txn)
                    && ! ($type === UserType::Admin && $txn->currentReturn?->requested_by === $viewer->id),
            ],
        ];
    }

    /**
     * The latest chargeback on a deposit. Partners see an approved one only
     * (the amount they bear and when), never the bank's notice.
     *
     * @return array<string, mixed>|null
     */
    private function chargeback(Transaction $txn, User $viewer): ?array
    {
        $chargeback = $txn->chargeback_status === null ? null : $txn->currentChargeback;
        $partner = $viewer->isType(UserType::Partner);

        if ($chargeback === null || ($partner && $chargeback->status !== 'approved')) {
            return null;
        }

        return [
            'id' => $chargeback->id,
            'reference' => $chargeback->reference,
            'status' => $chargeback->status,
            'amount' => $chargeback->amount,
            'utr' => $partner ? null : $chargeback->utr,
            'reason' => $partner ? null : $chargeback->reason,
            'proof_file_id' => $partner ? null : $chargeback->proof_file_id,
            'requested_at' => $partner ? null : $chargeback->created_at?->toIso8601String(),
            'requested_by' => $partner ? null : $chargeback->requester->name,
            'decided_at' => $chargeback->decided_at?->toIso8601String(),
            'decision_note' => $partner ? null : $chargeback->decision_note,
        ];
    }

    /**
     * Chargebacks / Payout returns, while their section is open (Section rollout).
     */
    private function sectionOpen(User $viewer, string $extra): bool
    {
        return $this->open[$viewer->id.$extra] ??= $this->rollout->allows($viewer, $viewer->type->value.'/'.TransactionController::SECTION_PATHS[$extra]);
    }

    /**
     * A paid payout with no return open and a transfer that hasn't come back.
     */
    private function returnable(Transaction $txn): bool
    {
        return $txn->status === 'success'
            && $txn->return_status !== 'requested'
            && $txn->payoutPayments->contains(fn (PayoutPayment $part) => $part->approvedReturn === null);
    }

    /**
     * A payout's bank transfers (it may be paid in parts). Everyone sees the
     * amount, UTR, date and proof; partners never who paid it or the bank line.
     *
     * @return list<array<string, mixed>>|null
     */
    private function payments(Transaction $txn, User $viewer): ?array
    {
        if ($txn->direction !== 'payout') {
            return null;
        }

        $partner = $viewer->isType(UserType::Partner);

        return array_values($txn->payoutPayments->map(fn (PayoutPayment $part) => [
            'id' => $part->id,
            'amount' => $part->amount,
            'utr' => $part->utr_normalized,
            'paid_at' => $part->paid_at->toIso8601String(),
            'proof_file_id' => $part->proof_file_id,
            'proof_mime' => $part->proof?->mime,
            'note' => $partner ? null : $part->note,
            'recorded_by' => $partner ? null : $part->creator?->name,
            'bank_line' => $partner || $part->statementEntry === null ? null : $part->statementEntry->status,
            'returned' => $part->approvedReturn?->amount,
        ])->all());
    }

    /**
     * The latest return on a payout. Partners see it all (they raise it)
     * except the bank's proof.
     *
     * @return array<string, mixed>|null
     */
    private function payoutReturn(Transaction $txn, User $viewer): ?array
    {
        $return = $txn->return_status === null ? null : $txn->currentReturn;

        if ($return === null) {
            return null;
        }

        $partner = $viewer->isType(UserType::Partner);

        return [
            'id' => $return->id,
            'reference' => $return->reference,
            'status' => $return->status,
            'amount' => $return->amount,
            'utr' => $return->utr,
            'returned_at' => $return->returned_at?->toIso8601String(),
            'payout_payment_id' => $return->payout_payment_id,
            'reason' => $return->reason,
            'proof_file_id' => $partner ? null : $return->proof_file_id,
            'requested_at' => $return->created_at?->toIso8601String(),
            'requested_by' => $partner ? null : $return->requester->name,
            'decided_at' => $return->decided_at?->toIso8601String(),
            'decided_by' => $partner ? null : $return->decider?->name,
            'decision_note' => $return->decision_note,
        ];
    }

    /**
     * Everything the drawer shows, loaded when it opens.
     *
     * @return array<string, mixed>
     */
    public function detail(Transaction $txn, User $viewer): array
    {
        $type = $viewer->type;
        $events = $txn->events()->get();
        $proofIds = $events->pluck('data.proof_file_id')->filter()->values()->all();

        return [
            'id' => $txn->id,
            'commission' => match ($type) {
                UserType::Admin => [
                    'partner_rate' => $txn->partner_rate_percent,
                    'partner' => $txn->partner_commission,
                    'branch_rate' => $txn->branch_rate_percent,
                    'branch' => $txn->branch_commission,
                    'margin' => $txn->platform_margin,
                ],
                UserType::Partner => ['partner_rate' => $txn->partner_rate_percent, 'partner' => $txn->partner_commission],
                UserType::Branch => ['branch_rate' => $txn->branch_rate_percent, 'branch' => $txn->branch_commission],
            },
            'timeline' => $events->map(fn (TransactionEvent $event) => [
                'event' => $event->event,
                'from' => $event->from_status,
                'to' => $event->to_status,
                'actor' => $event->actor_type,
                'reason' => $event->reason,
                'at' => $event->created_at->toIso8601String(),
                'data' => $type === UserType::Admin ? $event->data : array_intersect_key($event->data ?? [], array_flip(['utr', 'bank_utr', 'reason_code', 'possible_duplicate_of', 'method', 'to', 'auto', 'claimed_by', 'approved'])),
            ])->values()->all(),
            // Payment proof: branches and Admin check it; partners don't need it.
            'proofs' => $type === UserType::Partner ? [] : StoredFile::query()->whereIn('id', $proofIds)->get()->map(fn (StoredFile $file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'mime' => $file->mime,
                'size' => $file->size_bytes,
                'at' => $file->created_at->toIso8601String(),
            ])->values()->all(),
            'ledger' => $type === UserType::Admin ? $this->ledger->journalsForTransaction($txn->id) : null,
            'reconciliation' => $type === UserType::Partner ? null : $this->reconciliation($txn),
            'webhooks' => $type === UserType::Branch ? null : WebhookEvent::query()
                ->where('transaction_id', $txn->id)
                ->with('attemptsLog')
                ->orderBy('created_at')
                ->get()
                ->map(fn (WebhookEvent $hook) => [
                    'id' => $hook->id,
                    'type' => $hook->event_type,
                    'url' => $hook->url,
                    'status' => $hook->status,
                    'attempts' => $hook->attemptsLog->map(fn (WebhookAttempt $attempt) => [
                        'no' => $attempt->attempt_no,
                        'status' => $attempt->response_status,
                        'error' => $attempt->error,
                        'ms' => $attempt->duration_ms,
                        'at' => $attempt->created_at->toIso8601String(),
                    ])->values()->all(),
                    'next_attempt_at' => $hook->next_attempt_at?->toIso8601String(),
                    'can_resend' => $viewer->can('webhooks.update') && $hook->status !== 'pending',
                ])->values()->all(),
            'audit' => $type === UserType::Admin ? AuditLog::query()
                ->where(['subject_type' => 'transaction', 'subject_id' => $txn->id])
                ->with('actor')
                ->orderBy('created_at')
                ->get()
                ->map(fn (AuditLog $log) => [
                    'action' => $log->action,
                    'actor' => $log->actor->name ?? 'System',
                    'ip' => $log->ip_address,
                    'request_id' => $log->request_id,
                    'old' => $log->old_values,
                    'new' => $log->new_values,
                    'at' => $log->created_at->toIso8601String(),
                ])->values()->all() : null,
        ];
    }

    /**
     * Drawer "Bank" tab (design: compare): the statement line linked to the
     * transaction, the two branches side by side, and any cases about it.
     *
     * @return array<string, mixed>
     */
    private function reconciliation(Transaction $txn): array
    {
        $line = $txn->statementEntry()->with(['paymentAccount', 'branch', 'import.file', 'creator'])->first();

        return [
            'line' => $line === null ? null : [
                'id' => $line->id,
                'value_date' => $line->value_date->toDateString(),
                'direction' => $line->entry_direction,
                'amount' => $line->amount,
                'utr' => $line->utr_normalized,
                'description' => $line->description,
                'status' => $line->status,
                'account' => $line->paymentAccount->label,
                'branch' => $line->branch->code,
                'source' => $line->import_id === null ? __('Typed in by :name', ['name' => $line->creator->name ?? '—']) : __('Imported from :file', ['file' => $line->import->file->original_name ?? '—']),
                'matched_at' => $line->matched_at?->toIso8601String(),
            ],
            'transaction_branch' => $txn->branch?->code,
            'cases' => ReconciliationCase::query()
                ->where('transaction_id', $txn->id)
                ->with('entry.branch')
                ->orderBy('created_at')
                ->get()
                ->map(fn (ReconciliationCase $case) => [
                    'reference' => $case->reference,
                    'type' => $case->type->label(),
                    'status' => $case->status,
                    'resolution' => $case->resolution?->label(),
                    'notes' => $case->notes,
                    'line_branch' => $case->entry?->branch->code,
                    'at' => $case->created_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
