<?php

namespace App\Domain\PaymentAccount\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin's verification decision on an account waiting for it: approve
 * (it becomes `verified`; the branch or Admin then activates it) or reject
 * with a reason the branch sees. Editing a rejected account resubmits it.
 */
class ReviewPaymentAccount
{
    public function approve(User $actor, PaymentAccount $account): PaymentAccount
    {
        $this->ensurePending($account);

        DB::transaction(function () use ($actor, $account) {
            $account->forceFill([
                'status' => AccountStatus::Verified,
                'verified_at' => now(),
                'verified_by' => $actor->id,
                'rejected_reason' => null,
            ])->save();

            AuditLog::record('payment_account.verified', $account, ['status' => AccountStatus::VerificationPending->value], ['status' => AccountStatus::Verified->value], $actor);
            app(AlertDispatcher::class)->accountReviewed($account);
        });

        return $account;
    }

    public function reject(User $actor, PaymentAccount $account, string $reason): PaymentAccount
    {
        $this->ensurePending($account);

        DB::transaction(function () use ($actor, $account, $reason) {
            $account->forceFill(['status' => AccountStatus::Rejected, 'rejected_reason' => $reason])->save();

            AuditLog::record('payment_account.rejected', $account, ['status' => AccountStatus::VerificationPending->value], ['status' => AccountStatus::Rejected->value, 'reason' => $reason], $actor);
            app(AlertDispatcher::class)->accountReviewed($account);
        });

        return $account;
    }

    private function ensurePending(PaymentAccount $account): void
    {
        if ($account->status !== AccountStatus::VerificationPending) {
            throw ValidationException::withMessages(['status' => __('Only accounts waiting for verification can be approved or rejected.')]);
        }
    }
}
