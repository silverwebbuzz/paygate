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
 * Money that moved back after a transaction succeeded (decided 2026-09-28).
 * PayGate never bears a loss, and earned fees stay earned except when a
 * payout is fully reversed:
 *
 * - chargeback (G-20): the customer's bank took back an approved deposit.
 *   Admin picks who bears it. Partner: partner −amount, branch +amount.
 *   Branch: nothing is booked (the branch absorbs it).
 * - refund (G-22): the branch sent an approved deposit back to the
 *   customer. Partner −amount, branch +amount.
 * - return (G-21): a paid payout came back. Full reversal: partner
 *   +(amount + fee), branch −(amount + commission), margin reversed.
 *
 * One reversal per transaction; a `reversal` journal pointing at the
 * original, a new status, a webhook, the timeline and the audit log.
 */
class ReverseTransaction
{
    private const KINDS = [
        'chargeback' => ['direction' => 'payin', 'status' => 'chargeback', 'event' => 'payin.chargeback'],
        'refund' => ['direction' => 'payin', 'status' => 'refunded', 'event' => 'payin.refunded'],
        'return' => ['direction' => 'payout', 'status' => 'returned', 'event' => 'payout.returned'],
    ];

    public function __construct(private Ledger $ledger, private QueueWebhook $webhooks, private AlertDispatcher $alerts) {}

    public function handle(User $actor, Transaction $txn, string $kind, string $reason, ?string $bearer = null, ?string $externalReference = null): TransactionReversal
    {
        $rule = self::KINDS[$kind] ?? throw ValidationException::withMessages(['kind' => __('Choose chargeback, refund or return.')]);

        if ($kind === 'chargeback' && ! in_array($bearer, ['partner', 'branch'], true)) {
            throw ValidationException::withMessages(['bearer' => __('Choose who bears the chargeback.')]);
        }

        return DB::transaction(function () use ($actor, $txn, $kind, $rule, $reason, $bearer, $externalReference) {
            /** @var Transaction $locked */
            $locked = Transaction::query()->with('partner')->whereKey($txn->id)->lockForUpdate()->firstOrFail();

            if ($locked->direction !== $rule['direction'] || $locked->status !== 'success') {
                throw ValidationException::withMessages(['transaction' => $rule['direction'] === 'payin'
                    ? __(':reference isn’t an approved pay-in.', ['reference' => $locked->reference])
                    : __(':reference isn’t a paid payout.', ['reference' => $locked->reference])]);
            }

            $partner = $this->ledger->account(Ledger::PARTNER_POSITION, $locked->partner_id, $locked->branch_id);
            $branch = $this->ledger->account(Ledger::BRANCH_POSITION, $locked->partner_id, $locked->branch_id);
            $amount = $locked->amount;

            $lines = match (true) {
                $kind === 'return' => [
                    $partner => $amount + (int) $locked->partner_commission,
                    $branch => -($amount + (int) $locked->branch_commission),
                    $this->ledger->account(Ledger::PLATFORM_MARGIN) => -(int) $locked->platform_margin,
                ],
                $kind === 'chargeback' && $bearer === 'branch' => [],
                default => [$partner => -$amount, $branch => $amount],
            };

            $journalId = $lines === [] ? null : $this->ledger->post(
                'reversal',
                $lines,
                $locked->id,
                ucfirst($kind)." of {$locked->reference}: {$reason}",
                $actor->id,
                array_filter(['reverses_journal_id' => $this->ledger->journalId($locked->id, $locked->direction === 'payin' ? 'payin_success' : 'payout_success')]),
            );

            $reversal = TransactionReversal::create([
                'reference' => 'RV'.substr(Transaction::newPayinReference(), 2),
                'transaction_id' => $locked->id,
                'kind' => $kind,
                'bearer' => $kind === 'chargeback' ? $bearer : null,
                'amount' => $amount,
                'reason' => $reason,
                'external_reference' => $externalReference === null ? null : mb_substr(trim($externalReference), 0, 100),
                'journal_id' => $journalId,
                'created_by' => $actor->id,
            ]);

            $from = $locked->status;
            $locked->forceFill(['status' => $rule['status'], 'status_note' => $reason])->save();

            TransactionEvent::record($locked, $kind === 'return' ? 'returned' : ($kind === 'refund' ? 'refunded' : 'chargeback'), $from, $locked->status, 'user', $actor->id, $reason, array_filter([
                'reversal' => $reversal->reference,
                'bearer' => $reversal->bearer,
                'external_reference' => $reversal->external_reference,
            ]));
            AuditLog::record("transaction.{$kind}", $locked, ['status' => $from], ['status' => $locked->status, 'bearer' => $reversal->bearer, 'reason' => $reason], $actor);

            $locked->direction === 'payin'
                ? $this->webhooks->forPayin($locked, $rule['event'])
                : $this->webhooks->forPayout($locked, $rule['event']);

            $this->alerts->reversal($reversal, $locked);
            $txn->setRawAttributes($locked->getAttributes(), true);

            return $reversal;
        });
    }
}
