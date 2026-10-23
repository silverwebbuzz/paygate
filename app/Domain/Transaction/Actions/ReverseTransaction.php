<?php

namespace App\Domain\Transaction\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Domain\Transaction\Models\TransactionReversal;
use App\Domain\Webhook\Actions\QueueWebhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A refund (decided 2026-09-28, G-22): the branch sent an approved deposit
 * back to the customer. Partner −amount, branch +amount; earned fees stay
 * earned, PayGate never bears a loss.
 *
 * Chargebacks (ManageChargeback) and payout returns (ManagePayoutReturn,
 * which replaced the full reversal of a returned payout on 2026-10-22) are
 * raised and confirmed separately; older `returned` payouts stay as booked.
 *
 * One reversal per transaction; a `reversal` journal pointing at the
 * original, a new status, a webhook, the timeline and the audit log.
 */
class ReverseTransaction
{
    private const KINDS = [
        'refund' => ['direction' => 'payin', 'status' => 'refunded', 'event' => 'payin.refunded'],
    ];

    public function __construct(private Ledger $ledger, private QueueWebhook $webhooks, private AlertDispatcher $alerts) {}

    public function handle(User $actor, Transaction $txn, string $kind, string $reason, ?string $externalReference = null): TransactionReversal
    {
        $rule = self::KINDS[$kind] ?? throw ValidationException::withMessages(['kind' => __('Only a refund can be recorded here; a payout that came back is raised on the payout.')]);

        return DB::transaction(function () use ($actor, $txn, $kind, $rule, $reason, $externalReference) {
            /** @var Transaction $locked */
            $locked = Transaction::query()->with('partner')->whereKey($txn->id)->lockForUpdate()->firstOrFail();

            if ($locked->direction !== $rule['direction'] || $locked->status !== 'success') {
                throw ValidationException::withMessages(['transaction' => __(':reference isn’t an approved pay-in.', ['reference' => $locked->reference])]);
            }

            if (in_array($locked->chargeback_status, ['requested', 'approved'], true)) {
                throw ValidationException::withMessages(['transaction' => __(':reference has a chargeback :status; it can’t also be refunded.', ['reference' => $locked->reference, 'status' => $locked->chargeback_status])]);
            }

            $partner = $this->ledger->account(Ledger::PARTNER_POSITION, $locked->partner_id, $locked->branch_id);
            $branch = $this->ledger->account(Ledger::BRANCH_POSITION, $locked->partner_id, $locked->branch_id);
            $amount = $locked->amount;

            $lines = [$partner => -$amount, $branch => $amount];

            $journalId = $this->ledger->post(
                'reversal',
                $lines,
                $locked->id,
                ucfirst($kind)." of {$locked->reference}: {$reason}",
                $actor->id,
                array_filter(['reverses_journal_id' => $this->ledger->journalId($locked->id, 'payin_success')]),
            );

            $reversal = TransactionReversal::create([
                'reference' => 'RV'.substr(Transaction::newPayinReference(), 2),
                'transaction_id' => $locked->id,
                'kind' => $kind,
                'bearer' => null,
                'amount' => $amount,
                'reason' => $reason,
                'external_reference' => $externalReference === null ? null : mb_substr(trim($externalReference), 0, 100),
                'journal_id' => $journalId,
                'created_by' => $actor->id,
            ]);

            $from = $locked->status;
            $locked->forceFill(['status' => $rule['status'], 'status_note' => $reason])->save();

            TransactionEvent::record($locked, 'refunded', $from, $locked->status, 'user', $actor->id, $reason, array_filter([
                'reversal' => $reversal->reference,
                'external_reference' => $reversal->external_reference,
            ]));
            AuditLog::record("transaction.{$kind}", $locked, ['status' => $from], ['status' => $locked->status, 'reason' => $reason], $actor);

            $this->webhooks->forPayin($locked, $rule['event']);

            $this->alerts->reversal($reversal, $locked);
            $txn->setRawAttributes($locked->getAttributes(), true);

            return $reversal;
        });
    }
}
