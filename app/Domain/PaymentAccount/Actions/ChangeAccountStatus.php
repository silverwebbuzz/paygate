<?php

namespace App\Domain\PaymentAccount\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Enums\AccountVerification;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeAccountStatus
{
    public function handle(User $actor, PaymentAccount $account, AccountStatus $status, ?string $reason = null, bool $requireReason = false): PaymentAccount
    {
        if ($status === $account->status) {
            throw ValidationException::withMessages(['status' => __('This account is already :status.', ['status' => $status->value])]);
        }

        if ($status === AccountStatus::Active && $account->verification !== AccountVerification::Verified) {
            throw ValidationException::withMessages(['status' => __('Verify the account before making it active.')]);
        }

        $reason = is_string($reason) ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;

        if ($requireReason && $status === AccountStatus::Inactive && $reason === null) {
            throw ValidationException::withMessages(['reason' => __('Give a reason for this status change.')]);
        }

        DB::transaction(function () use ($actor, $account, $status, $reason) {
            $old = $account->status;
            $account->forceFill(['status' => $status])->save();

            AuditLog::record('payment_account.status_changed', $account, ['status' => $old->value], array_filter([
                'status' => $status->value,
                'reason' => $reason,
            ]), $actor);
        });

        return $account;
    }
}
