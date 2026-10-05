<?php

namespace App\Domain\PaymentAccount\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Activates, pauses or disables an account (AccountStatus::switchableTo).
 * Pausing stops new customers being sent to it; payments already on their
 * way are unaffected. Disabling is permanent (history is kept). Pausing and
 * disabling need a reason.
 */
class ChangeAccountStatus
{
    public function handle(User $actor, PaymentAccount $account, AccountStatus $status, ?string $reason = null): PaymentAccount
    {
        if (! in_array($status, $account->status->switchableTo(), true)) {
            throw ValidationException::withMessages(['status' => __('A :from account can’t become :to.', [
                'from' => str_replace('_', ' ', $account->status->value),
                'to' => $status->value,
            ])]);
        }

        if (in_array($status, [AccountStatus::Paused, AccountStatus::Disabled], true) && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages(['reason' => $status === AccountStatus::Paused
                ? __('Give a reason for pausing the account.')
                : __('Give a reason for disabling the account.')]);
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
