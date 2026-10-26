<?php

namespace App\Domain\Transaction\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Transaction\Models\Chargeback;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Domain\Webhook\Actions\QueueWebhook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chargebacks (decided 2026-10-21): a customer's bank took back an
 * approved deposit, a few days after it was settled.
 *
 * - request: the branch (or Admin) raises it on a successful deposit with
 *   the bank's notice (image / PDF). No UTR is asked for (decided
 *   2026-10-26): the notice is the evidence. The amount starts as the
 *   deposit's and may be lowered. Nothing is booked.
 * - approve: Admin (reversals.approve, never the person who raised it)
 *   books it that day: partner −amount, branch +amount (the partner always
 *   bears it; fees already earned stay earned). It shows in that day's
 *   settlements, not the deposit's. The partner gets `payin.chargeback`.
 * - reject: nothing is booked; the branch may raise it again.
 *
 * The deposit's status stays `success`; `chargeback_status` says where the
 * chargeback is.
 */
class ManageChargeback
{
    public function __construct(private Ledger $ledger, private QueueWebhook $webhooks, private AlertDispatcher $alerts) {}

    public function request(User $actor, Transaction $txn, int $amount, UploadedFile $proof, ?string $reason = null): Chargeback
    {
        $chargeback = DB::transaction(function () use ($actor, $txn, $amount, $proof, $reason) {
            /** @var Transaction $locked */
            $locked = Transaction::query()->whereKey($txn->id)->lockForUpdate()->firstOrFail();

            $problem = match (true) {
                $locked->direction !== 'payin' || $locked->status !== 'success' || $locked->branch_id === null => __(':reference isn’t an approved deposit.', ['reference' => $locked->reference]),
                in_array($locked->chargeback_status, ['requested', 'approved'], true) => __('A chargeback on :reference is already :status.', ['reference' => $locked->reference, 'status' => $locked->chargeback_status]),
                default => null,
            };

            if ($problem !== null) {
                throw ValidationException::withMessages(['transaction' => $problem]);
            }

            if ($amount <= 0 || $amount > $locked->amount) {
                throw ValidationException::withMessages(['amount' => __('Enter an amount up to the deposit’s amount.')]);
            }

            $chargeback = Chargeback::create([
                'reference' => Chargeback::newReference(),
                'transaction_id' => $locked->id,
                'partner_id' => $locked->partner_id,
                'branch_id' => $locked->branch_id,
                'amount' => $amount,
                'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
                'status' => 'requested',
                'requested_by' => $actor->id,
            ]);

            $file = StoredFile::store($proof, 'chargeback_proof', $chargeback, 'user', $actor->id);
            $chargeback->forceFill(['proof_file_id' => $file->id])->save();

            $locked->forceFill(['chargeback_status' => 'requested'])->save();

            TransactionEvent::record($locked, 'chargeback_requested', $locked->status, $locked->status, 'user', $actor->id, $chargeback->reason, [
                'chargeback' => $chargeback->reference,
                'amount' => $amount,
            ]);
            AuditLog::record('chargeback.requested', $chargeback, [], $chargeback->only(['reference', 'amount', 'reason']), $actor);

            return $chargeback;
        });

        $this->alerts->chargebackRequested($chargeback, $txn, $actor);

        return $chargeback;
    }

    public function approve(User $actor, Chargeback $chargeback, ?string $note = null): Chargeback
    {
        return DB::transaction(function () use ($actor, $chargeback, $note) {
            [$locked, $txn] = $this->lockRequested($actor, $chargeback);

            $journalId = $this->ledger->post(
                'chargeback',
                [
                    $this->ledger->account(Ledger::PARTNER_POSITION, $locked->partner_id, $locked->branch_id) => -$locked->amount,
                    $this->ledger->account(Ledger::BRANCH_POSITION, $locked->partner_id, $locked->branch_id) => $locked->amount,
                ],
                $txn->id,
                "Chargeback {$locked->reference} of {$txn->reference}",
                $actor->id,
                array_filter(['reverses_journal_id' => $this->ledger->journalId($txn->id, 'payin_success')]),
            );

            $locked->forceFill(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'journal_id' => $journalId])->save();
            $txn->forceFill(['chargeback_status' => 'approved'])->save();

            TransactionEvent::record($txn, 'chargeback_approved', $txn->status, $txn->status, 'user', $actor->id, $note, ['chargeback' => $locked->reference, 'amount' => $locked->amount]);
            AuditLog::record('chargeback.approved', $locked, ['status' => 'requested'], ['status' => 'approved', 'note' => $note], $actor);

            $this->webhooks->forPayin($txn->load('partner'), 'payin.chargeback');
            $this->alerts->chargebackDecided($locked, $txn);
            $chargeback->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    public function reject(User $actor, Chargeback $chargeback, string $note): Chargeback
    {
        return DB::transaction(function () use ($actor, $chargeback, $note) {
            [$locked, $txn] = $this->lockRequested($actor, $chargeback);

            $locked->forceFill(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $txn->forceFill(['chargeback_status' => 'rejected'])->save();

            TransactionEvent::record($txn, 'chargeback_rejected', $txn->status, $txn->status, 'user', $actor->id, $note, ['chargeback' => $locked->reference]);
            AuditLog::record('chargeback.rejected', $locked, ['status' => 'requested'], ['status' => 'rejected', 'note' => $note], $actor);

            $this->alerts->chargebackDecided($locked, $txn);
            $chargeback->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * @return array{0: Chargeback, 1: Transaction}
     */
    private function lockRequested(User $actor, Chargeback $chargeback): array
    {
        /** @var Transaction $txn */
        $txn = Transaction::query()->whereKey($chargeback->transaction_id)->lockForUpdate()->firstOrFail();
        /** @var Chargeback $locked */
        $locked = Chargeback::query()->whereKey($chargeback->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'requested') {
            throw ValidationException::withMessages(['chargeback' => __('This chargeback was already :status.', ['status' => $locked->status])]);
        }

        if ($locked->requested_by === $actor->id) {
            throw ValidationException::withMessages(['chargeback' => __('You raised this chargeback: another admin must approve or reject it.')]);
        }

        // Refunded (or otherwise reversed) since it was raised.
        if ($txn->status !== 'success') {
            throw ValidationException::withMessages(['chargeback' => __(':reference is no longer an approved deposit (:status).', ['reference' => $txn->reference, 'status' => $txn->status])]);
        }

        return [$locked, $txn];
    }
}
