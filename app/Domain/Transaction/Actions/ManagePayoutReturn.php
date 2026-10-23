<?php

namespace App\Domain\Transaction\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Payout\Models\PayoutPayment;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Transaction\Models\PayoutReturn;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Domain\Webhook\Actions\QueueWebhook;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payout returns (decided 2026-10-22): a paid payout, or part of it, came
 * back to the branch's account (e.g. one of two transfers bounced), after
 * the payout was settled. The chargeback of a payout.
 *
 * - request: the partner (its customer says the money never arrived), or
 *   Admin for it, raises it on a successful payout. Nothing is booked.
 * - confirm: the branch (or Admin, never the admin who raised it) picks
 *   the transfer that bounced (a payout may be paid in parts) and enters
 *   how much came back (full or part, up to that transfer), the UTR of that
 *   credit, the date it came back and the bank’s proof (optional for the branch, required from Admin). Booked that day
 *   (the confirmation day, whatever date it came back): branch −amount, partner
 *   +amount (fees already earned stay earned). It shows in that day's
 *   settlements, not the payout's. The partner gets `payout.returned`.
 * - record: a branch that sees the money come back first does both at
 *   once (it only ever costs the branch, so no second person is needed).
 * - reject: nothing is booked; it may be raised again.
 *
 * One open return per payout at a time, one confirmed return per transfer:
 * if a second transfer bounces later, the partner raises it again.
 *
 * What the partner then does for its customer (pay them, or have them ask
 * again) is up to the partner. The payout's status stays `success`;
 * `return_status` says where the return is.
 */
class ManagePayoutReturn
{
    public function __construct(private Ledger $ledger, private QueueWebhook $webhooks, private AlertDispatcher $alerts) {}

    public function request(User $actor, Transaction $txn, ?string $reason = null): PayoutReturn
    {
        $return = DB::transaction(fn () => $this->open($actor, $txn, $reason));

        $this->alerts->payoutReturnRequested($return, $txn, $actor);

        return $return;
    }

    /**
     * The branch records a return it found itself: raised and confirmed.
     */
    public function record(User $actor, Transaction $txn, ?string $paymentId, int $amount, string $utr, CarbonInterface $returnedAt, ?UploadedFile $proof, ?string $note = null): PayoutReturn
    {
        $return = DB::transaction(function () use ($actor, $txn, $paymentId, $amount, $utr, $returnedAt, $proof, $note) {
            $return = $this->open($actor, $txn, $note);

            return $this->book($actor, $return, $paymentId, $amount, $utr, $returnedAt, $proof, $note);
        });

        $this->alerts->payoutReturnDecided($return, $txn);

        return $return;
    }

    public function confirm(User $actor, PayoutReturn $return, ?string $paymentId, int $amount, string $utr, CarbonInterface $returnedAt, ?UploadedFile $proof, ?string $note = null): PayoutReturn
    {
        $confirmed = DB::transaction(fn () => $this->book($actor, $return, $paymentId, $amount, $utr, $returnedAt, $proof, $note));

        $return->setRawAttributes($confirmed->getAttributes(), true);
        $this->alerts->payoutReturnDecided($confirmed, $confirmed->transaction);

        return $confirmed;
    }

    public function reject(User $actor, PayoutReturn $return, string $note): PayoutReturn
    {
        $rejected = DB::transaction(function () use ($actor, $return, $note) {
            [$locked, $txn] = $this->lockRequested($actor, $return);

            $locked->forceFill(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            // An earlier transfer's confirmed return still stands.
            $txn->forceFill(['return_status' => PayoutReturn::query()->where(['transaction_id' => $txn->id, 'status' => 'approved'])->exists() ? 'approved' : 'rejected'])->save();

            TransactionEvent::record($txn, 'return_rejected', $txn->status, $txn->status, 'user', $actor->id, $note, ['return' => $locked->reference]);
            AuditLog::record('payout_return.rejected', $locked, ['status' => 'requested'], ['status' => 'rejected', 'note' => $note], $actor);

            return $locked->setRelation('transaction', $txn);
        });

        $return->setRawAttributes($rejected->getAttributes(), true);
        $this->alerts->payoutReturnDecided($rejected, $rejected->transaction);

        return $rejected;
    }

    private function open(User $actor, Transaction $txn, ?string $reason): PayoutReturn
    {
        /** @var Transaction $locked */
        $locked = Transaction::query()->whereKey($txn->id)->lockForUpdate()->firstOrFail();

        $problem = match (true) {
            $locked->direction !== 'payout' || $locked->status !== 'success' || $locked->branch_id === null => __(':reference isn’t a paid payout.', ['reference' => $locked->reference]),
            $locked->return_status === 'requested' => __('A return on :reference is already raised.', ['reference' => $locked->reference]),
            self::returnableTransfers($locked)->isEmpty() => __('Every transfer of :reference already has a confirmed return.', ['reference' => $locked->reference]),
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['transaction' => $problem]);
        }

        $return = PayoutReturn::create([
            'reference' => PayoutReturn::newReference(),
            'transaction_id' => $locked->id,
            'partner_id' => $locked->partner_id,
            'branch_id' => $locked->branch_id,
            'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
            'status' => 'requested',
            'requested_by' => $actor->id,
        ]);

        $locked->forceFill(['return_status' => 'requested'])->save();

        TransactionEvent::record($locked, 'return_requested', $locked->status, $locked->status, 'user', $actor->id, $return->reason, ['return' => $return->reference]);
        AuditLog::record('payout_return.requested', $return, [], $return->only(['reference', 'reason']), $actor);

        return $return;
    }

    private function book(User $actor, PayoutReturn $return, ?string $paymentId, int $amount, string $utr, CarbonInterface $returnedAt, ?UploadedFile $proof, ?string $note): PayoutReturn
    {
        [$locked, $txn] = $this->lockRequested($actor, $return);

        $normalised = Transaction::normaliseUtr($utr);

        if (preg_match(SubmitPayinProof::UTR_PATTERN, $normalised) !== 1) {
            throw ValidationException::withMessages(['utr' => __('That doesn’t look like a UTR. Enter the reference of the money that came back, as shown on your bank statement (6–22 letters and digits).')]);
        }

        $transfers = self::returnableTransfers($txn);
        $part = $paymentId === null
            ? ($transfers->count() === 1 ? $transfers->first() : null)
            : $transfers->firstWhere('id', $paymentId);

        if ($part === null) {
            throw ValidationException::withMessages(['payout_payment_id' => $paymentId === null
                ? __('Choose which transfer came back.')
                : __('That transfer isn’t part of :reference or already has a confirmed return.', ['reference' => $txn->reference])]);
        }

        if ($amount <= 0 || $amount > $part->amount) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount up to that transfer’s amount (₹:max).', ['max' => number_format($part->amount / 100, 2)])]);
        }

        // Bank statements often show only the date: not before the day that
        // transfer was sent (India time). Not the payout's completion day: a
        // transfer sent before midnight may bounce before the last one goes.
        $paidDay = $part->paid_at->copy()->setTimezone((string) config('app.business_timezone'))->startOfDay();

        if ($returnedAt->isFuture() || $returnedAt->lt($paidDay)) {
            throw ValidationException::withMessages(['returned_at' => __('Enter when the money came back: not before the day that transfer was sent, and not in the future.')]);
        }

        // Optional for the branch: it confirms against its own balance. An
        // admin confirming for the branch must show the bank's proof.
        if ($proof === null && $actor->isType(UserType::Admin)) {
            throw ValidationException::withMessages(['proof' => __('Attach the bank’s proof: the branch didn’t confirm this itself.')]);
        }

        $file = $proof === null ? null : StoredFile::store($proof, 'return_proof', $locked, 'user', $actor->id);

        $journalId = $this->ledger->post(
            'payout_return',
            [
                $this->ledger->account(Ledger::PARTNER_POSITION, $locked->partner_id, $locked->branch_id) => $amount,
                $this->ledger->account(Ledger::BRANCH_POSITION, $locked->partner_id, $locked->branch_id) => -$amount,
            ],
            $txn->id,
            "Return {$locked->reference} of {$txn->reference}",
            $actor->id,
            // The journal that booked the transfer that came back.
            array_filter(['reverses_journal_id' => $part->journal_id ?? $this->ledger->journalId($txn->id, 'payout_success')]),
        );

        $locked->forceFill([
            'status' => 'approved',
            'amount' => $amount,
            'utr' => $normalised,
            'returned_at' => $returnedAt->copy()->utc(),
            'payout_payment_id' => $part->id,
            'proof_file_id' => $file?->id,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_note' => $note === null || trim($note) === '' ? null : trim($note),
            'journal_id' => $journalId,
        ])->save();
        $txn->forceFill(['return_status' => 'approved'])->save();

        TransactionEvent::record($txn, 'return_approved', $txn->status, $txn->status, 'user', $actor->id, $locked->decision_note, [
            'return' => $locked->reference,
            'amount' => $amount,
            'utr' => $normalised,
            'returned_at' => $returnedAt->toIso8601String(),
            'transfer_utr' => $part->utr_normalized,
        ]);
        AuditLog::record('payout_return.approved', $locked, ['status' => 'requested'], ['status' => 'approved', ...$locked->only(['amount', 'utr', 'returned_at', 'decision_note'])], $actor);

        $this->webhooks->forPayout($txn->load('partner'), 'payout.returned');

        return $locked->setRelation('transaction', $txn);
    }

    /**
     * The payout's transfers that haven't come back yet (one confirmed
     * return per transfer).
     *
     * @return Collection<int, PayoutPayment>
     */
    public static function returnableTransfers(Transaction $payout): Collection
    {
        return PayoutPayment::query()
            ->where('transaction_id', $payout->id)
            ->whereDoesntHave('approvedReturn')
            ->orderBy('paid_at')
            ->get();
    }

    /**
     * @return array{0: PayoutReturn, 1: Transaction}
     */
    private function lockRequested(User $actor, PayoutReturn $return): array
    {
        /** @var Transaction $txn */
        $txn = Transaction::query()->whereKey($return->transaction_id)->lockForUpdate()->firstOrFail();
        /** @var PayoutReturn $locked */
        $locked = PayoutReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'requested') {
            throw ValidationException::withMessages(['return' => __('This return was already :status.', ['status' => $locked->status === 'approved' ? __('confirmed') : $locked->status])]);
        }

        // Maker–checker for Admin: an admin who raised it for the partner
        // doesn't also confirm it. (The branch may: it only costs the branch.)
        if ($actor->isType(UserType::Admin) && $locked->requested_by === $actor->id) {
            throw ValidationException::withMessages(['return' => __('You raised this return: the branch or another admin must confirm or reject it.')]);
        }

        if ($txn->status !== 'success') {
            throw ValidationException::withMessages(['return' => __(':reference is no longer a paid payout (:status).', ['reference' => $txn->reference, 'status' => $txn->status])]);
        }

        return [$locked, $txn];
    }
}
