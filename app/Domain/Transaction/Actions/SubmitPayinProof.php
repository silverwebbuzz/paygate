<?php

namespace App\Domain\Transaction\Actions;

use App\Domain\Allocation\Actions\ReleaseAllocation;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Reconciliation\Actions\AutoApproveDeposit;
use App\Domain\Reconciliation\StatementMatcher;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Domain\Transaction\Utr;
use App\Domain\Webhook\Actions\QueueWebhook;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The customer says they paid: a UTR, a screenshot, or both (decided
 * 2026-09-27, G-61). The pay-in then waits for the branch, which approves it
 * after seeing the money in its bank; for a screenshot-only claim the branch
 * types the bank UTR when approving (Phase 7), so duplicate-UTR protection
 * still works.
 *
 * A UTR is one bank transfer (Utr): one another transaction holds is
 * refused. One seen only on a declined / expired deposit is accepted but
 * flagged for the branch (`possible_duplicate_of`).
 */
class SubmitPayinProof
{
    public const UTR_PATTERN = '/^[A-Z0-9]{6,22}$/';

    private const TAKEN = 'This UTR is already used for another payment. Check the UTR in your bank or UPI app; if it is right, contact support.';

    public function __construct(
        private QueueWebhook $webhooks,
        private ReleaseAllocation $allocation,
        private StatementMatcher $matcher,
        private AutoApproveDeposit $autoApprove,
        private AlertDispatcher $alerts,
    ) {}

    public function handle(Transaction $payin, ?string $utr, ?UploadedFile $photo): Transaction
    {
        $normalised = $utr === null || trim($utr) === '' ? null : Transaction::normaliseUtr($utr);

        if ($normalised === null && $photo === null) {
            throw ValidationException::withMessages(['utr' => __('Enter the UTR / reference number, or upload a screenshot of the payment.')]);
        }

        if ($normalised !== null && preg_match(self::UTR_PATTERN, $normalised) !== 1) {
            throw ValidationException::withMessages(['utr' => __('That doesn’t look like a UTR. It is usually 12 digits, shown in your bank or UPI app.')]);
        }

        return DB::transaction(function () use ($payin, $utr, $normalised, $photo) {
            /** @var Transaction $locked */
            $locked = Transaction::query()->whereKey($payin->id)->lockForUpdate()->firstOrFail();

            if ($locked->payinStatus() !== PayinStatus::AwaitingPayment) {
                throw ValidationException::withMessages(['utr' => $locked->payinStatus()->isOpen()
                    ? __('Choose how you paid first.')
                    : __('This payment can no longer be updated.')]);
            }

            // Only an approved deposit / paid payout blocks; another waiting claim is flagged below.
            if ($normalised !== null && Utr::holder($normalised, $locked->id, claims: false) !== null) {
                throw ValidationException::withMessages(['utr' => __(self::TAKEN)]);
            }

            $duplicate = $normalised === null ? null : Transaction::query()
                ->where('direction', 'payin')
                ->whereKeyNot($locked->id)
                ->where('customer_utr_normalized', $normalised)
                ->value('reference');

            $file = $photo === null ? null : StoredFile::store($photo, 'payment_proof', $locked, 'customer');

            $from = $locked->status;

            try {
                // A savepoint, so a clash with another customer saving the same UTR
                // at this moment leaves this transaction usable.
                DB::transaction(fn () => $locked->forceFill([
                    'customer_utr' => $utr === null ? null : mb_substr(trim($utr), 0, 50),
                    'customer_utr_normalized' => $normalised,
                    'status' => PayinStatus::PaymentSubmitted->value,
                    'submitted_at' => now(),
                ])->save());
            } catch (QueryException $exception) {
                throw Utr::isDuplicateError($exception) ? ValidationException::withMessages(['utr' => __(self::TAKEN)]) : $exception;
            }

            $locked->session()->update(['status' => 'completed']);
            $this->allocation->closeSession($locked);

            TransactionEvent::record($locked, 'proof_submitted', $from, $locked->status, 'customer', null, null, array_filter([
                'utr' => $normalised,
                'proof_file_id' => $file?->id,
                'possible_duplicate_of' => $duplicate,
            ]));

            $this->webhooks->forPayin($locked->load('partner'), 'payin.submitted');
            if ($normalised !== null) {
                $this->flagSharedClaim($locked, $normalised);
            }

            // The bank statement may already show this payment (here, or at another branch).
            $this->matcher->transactionSubmitted($locked);
            $this->autoApprove->forPayin($locked);

            return $locked;
        });
    }

    /**
     * Another deposit still waiting claims the same UTR: one of the two
     * customers typed it wrongly or is trying fraud. Both are accepted and
     * flagged; the bank line decides which one is approved. Admin is alerted.
     */
    private function flagSharedClaim(Transaction $payin, string $utr): void
    {
        $others = Utr::claimants($utr, $payin->id);

        if ($others->isEmpty()) {
            return;
        }

        foreach ([$payin, ...$others] as $txn) {
            TransactionEvent::record($txn, 'utr_conflict', $txn->status, $txn->status, 'system', null, null, [
                'utr' => $utr,
                'claimed_by' => collect([$payin, ...$others])->reject(fn (Transaction $other) => $other->is($txn))->pluck('reference')->join(', '),
            ]);
        }

        $this->alerts->utrConflict($utr, __('Deposits :references both say they paid with UTR :utr. Only one can be real: the bank line decides, and the other should be declined.', [
            'references' => collect([$payin, ...$others])->pluck('reference')->join(', '),
            'utr' => $utr,
        ]), $payin);
    }
}
