<?php

namespace App\Domain\Transaction\Actions;

use App\Domain\Platform\Models\StoredFile;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
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
 * A UTR another open or successful pay-in already claimed is accepted but
 * flagged for the branch (`possible_duplicate_of`).
 */
class SubmitPayinProof
{
    public const UTR_PATTERN = '/^[A-Z0-9]{6,22}$/';

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

            $duplicate = $normalised === null ? null : Transaction::query()
                ->where('direction', 'payin')
                ->whereKeyNot($locked->id)
                ->where('customer_utr_normalized', $normalised)
                ->whereNotIn('status', [PayinStatus::Rejected->value, PayinStatus::Expired->value, PayinStatus::Cancelled->value])
                ->value('reference');

            $file = $photo === null ? null : StoredFile::store($photo, 'payment_proof', $locked, 'customer');

            $from = $locked->status;
            $locked->forceFill([
                'customer_utr' => $utr === null ? null : mb_substr(trim($utr), 0, 50),
                'customer_utr_normalized' => $normalised,
                'status' => PayinStatus::PaymentSubmitted->value,
                'submitted_at' => now(),
            ])->save();

            $locked->session()->update(['status' => 'completed']);

            TransactionEvent::record($locked, 'proof_submitted', $from, $locked->status, 'customer', null, null, array_filter([
                'utr' => $normalised,
                'proof_file_id' => $file?->id,
                'possible_duplicate_of' => $duplicate,
            ]));

            return $locked;
        });
    }
}
