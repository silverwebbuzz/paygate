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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The customer says they paid: a UTR, a screenshot, or both (decided
 * 2026-09-27, G-61). The pay-in then waits for the branch, which approves it
 * after seeing the money in its bank; for a screenshot-only claim the branch
 * types the bank UTR when approving (Phase 7), so duplicate-UTR protection
 * still works.
 *
 * A UTR is one bank transfer (Utr): one another transaction holds — a
 * pending deposit, a successful one, or a payout in process or paid — is
 * refused with a message saying which (decided 2026-10-22), and Admin is
 * alerted (a repeat is a double entry or fraud). One seen only on a
 * declined / expired / cancelled / failed transaction is accepted but
 * flagged for the branch (`possible_duplicate_of`).
 */
class SubmitPayinProof
{
    public const UTR_PATTERN = '/^[A-Z0-9]{6,22}$/';

    private const PENDING = 'This UTR has already been submitted for another payment that is pending (being checked). If that was you, please wait for it to be confirmed — don’t submit it again. Otherwise check the UTR in your bank or UPI app.';

    private const SUCCEEDED = 'This UTR has already been used for a successful payment, so it can’t be used again. Check the UTR in your bank or UPI app; if it is right, contact support.';

    private const IN_PROCESS = 'This UTR is already in use for a payment that is in process. Check the UTR in your bank or UPI app; if it is right, contact support.';

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

        if ($normalised !== null) {
            $this->refuseTaken($payin, $normalised);
        }

        return DB::transaction(function () use ($payin, $utr, $normalised, $photo) {
            /** @var Transaction $locked */
            $locked = Transaction::query()->whereKey($payin->id)->lockForUpdate()->firstOrFail();

            if ($locked->payinStatus() !== PayinStatus::AwaitingPayment) {
                throw ValidationException::withMessages(['utr' => $locked->payinStatus()->isOpen()
                    ? __('Choose how you paid first.')
                    : __('This payment can no longer be updated.')]);
            }

            // Checked again under the lock (refuseTaken ran before it).
            if ($normalised !== null && ($holder = Utr::holder($normalised, $locked->id)) !== null) {
                throw ValidationException::withMessages(['utr' => $this->takenMessage($holder)]);
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
                // at this moment (now pending for them) leaves this transaction usable.
                DB::transaction(fn () => $locked->forceFill([
                    'customer_utr' => $utr === null ? null : mb_substr(trim($utr), 0, 50),
                    'customer_utr_normalized' => $normalised,
                    'status' => PayinStatus::PaymentSubmitted->value,
                    'submitted_at' => now(),
                ])->save());
            } catch (QueryException $exception) {
                throw Utr::isDuplicateError($exception) ? ValidationException::withMessages(['utr' => __(self::PENDING)]) : $exception;
            }

            $locked->session()->update(['status' => 'completed']);
            $this->allocation->closeSession($locked);

            TransactionEvent::record($locked, 'proof_submitted', $from, $locked->status, 'customer', null, null, array_filter([
                'utr' => $normalised,
                'proof_file_id' => $file?->id,
                'possible_duplicate_of' => $duplicate,
            ]));

            $this->webhooks->forPayin($locked->load('partner'), 'payin.submitted');
            // The bank statement may already show this payment (here, or at another branch).
            $this->matcher->transactionSubmitted($locked);
            $this->autoApprove->forPayin($locked);

            return $locked;
        });
    }

    /**
     * A UTR another transaction holds is refused, saying whether it is
     * pending, successful or in process. Admin is alerted (once an hour per
     * UTR): it is a double entry, a typing mistake or someone trying fraud.
     */
    private function refuseTaken(Transaction $payin, string $utr): void
    {
        $holder = Utr::holder($utr, $payin->id);

        if ($holder === null) {
            return;
        }

        if (Cache::add("utr-refused:{$utr}", true, now()->addHour())) {
            $this->alerts->utrConflict($utr, __('A customer on :reference entered UTR :utr, which already belongs to :holder (:status). It was refused. Check for a double entry or fraud.', [
                'reference' => $payin->reference,
                'utr' => $utr,
                'holder' => $holder->reference,
                'status' => str_replace('_', ' ', $holder->status),
            ]), $holder);
        }

        throw ValidationException::withMessages(['utr' => $this->takenMessage($holder)]);
    }

    private function takenMessage(Transaction $holder): string
    {
        return __(match (true) {
            in_array($holder->status, Utr::CLAIMING, true) => self::PENDING,
            $holder->status === PayinStatus::Success->value => self::SUCCEEDED,
            default => self::IN_PROCESS,
        });
    }
}
