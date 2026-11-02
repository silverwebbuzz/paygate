<?php

namespace App\Domain\PaymentAccount\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin deletes a bank / UPI account. Nothing is removed: the status
 * becomes "deleted", so past transactions, statements and the account log
 * keep pointing at it, but it leaves every list except Admin's "Deleted"
 * tab and never receives customers again. Payments already on their way
 * are unaffected, as when an account is turned off.
 */
class DeletePaymentAccount
{
    public function handle(User $actor, PaymentAccount $account, ?string $reason = null): PaymentAccount
    {
        if ($account->isDeleted()) {
            throw ValidationException::withMessages(['status' => __('This account is already deleted.')]);
        }

        $reason = is_string($reason) ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;

        DB::transaction(function () use ($actor, $account, $reason) {
            $old = $account->status;
            $account->forceFill(['status' => AccountStatus::Deleted])->save();

            AuditLog::record('payment_account.deleted', $account, ['status' => $old->value], array_filter([
                'status' => AccountStatus::Deleted->value,
                'reason' => $reason,
            ]), $actor);
        });

        return $account;
    }
}
