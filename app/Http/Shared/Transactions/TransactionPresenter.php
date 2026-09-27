<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Platform\Models\StoredFile;
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
    public function __construct(private Ledger $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function row(Transaction $txn, User $viewer): array
    {
        $type = $viewer->type;
        $net = match ($type) {
            UserType::Partner => $txn->partner_commission === null ? null : $txn->amount - $txn->partner_commission,
            UserType::Branch => $txn->branch_commission === null ? null : $txn->amount - $txn->branch_commission,
            UserType::Admin => $txn->partner_commission === null ? null : $txn->amount - $txn->partner_commission,
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
            'customer' => $txn->customer ? [
                'id' => $txn->customer->external_id,
                'name' => $txn->customer->name,
                'mobile' => $txn->customer->mobile,
            ] : null,
            'amount' => $txn->amount,
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
            'expires_at' => $txn->expires_at?->toIso8601String(),
            'can' => [
                'decide' => $viewer->can('decide', $txn),
            ],
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
                'data' => $type === UserType::Admin ? $event->data : array_intersect_key($event->data ?? [], array_flip(['utr', 'bank_utr', 'reason_code', 'possible_duplicate_of', 'method', 'to'])),
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
}
