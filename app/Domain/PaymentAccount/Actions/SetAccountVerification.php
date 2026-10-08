<?php

namespace App\Domain\PaymentAccount\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Enums\AccountVerification;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Illuminate\Support\Facades\DB;

class SetAccountVerification
{
    public function handle(User $actor, PaymentAccount $account, AccountVerification $verification, ?string $reason = null): PaymentAccount
    {
        if ($account->verification === $verification) {
            return $account;
        }

        $reason = is_string($reason) ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;

        DB::transaction(function () use ($actor, $account, $verification, $reason) {
            $oldVerification = $account->verification;
            $oldStatus = $account->status;
            $fill = ['verification' => $verification];

            if ($verification === AccountVerification::Verified) {
                $fill['verified_at'] = $account->verified_at ?? now();
                $fill['verified_by'] = $account->verified_by ?? $actor->id;
                $fill['rejected_reason'] = null;
            } else {
                $fill['status'] = AccountStatus::Inactive;
                $fill['verified_at'] = null;
                $fill['verified_by'] = null;
                $fill['rejected_reason'] = $verification === AccountVerification::Unverified ? $reason : null;
            }

            $account->forceFill($fill)->save();

            $action = match ($verification) {
                AccountVerification::Verified => 'payment_account.verified',
                AccountVerification::Unverified => 'payment_account.rejected',
                AccountVerification::Pending => 'payment_account.verification_changed',
            };

            AuditLog::record($action, $account, [
                'verification' => $oldVerification->value,
                'status' => $oldStatus->value,
            ], array_filter([
                'verification' => $verification->value,
                'status' => $account->status->value,
                'reason' => $reason,
            ]), $actor);

            if ($verification !== AccountVerification::Pending) {
                app(AlertDispatcher::class)->accountReviewed($account);
            }
        });

        return $account;
    }
}
