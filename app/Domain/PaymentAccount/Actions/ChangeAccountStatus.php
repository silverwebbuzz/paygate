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
 * way are unaffected. A branch can turn a previously verified account back
 * on after it is disabled. Admin can set any status, and every admin
 * change needs a reason. Changing bank or UPI details is a separate save
 * and still waits for verification.
 */
class ChangeAccountStatus
{
    public function handle(User $actor, PaymentAccount $account, AccountStatus $status, ?string $reason = null, bool $unrestricted = false): PaymentAccount
    {
        $allowed = $unrestricted
            ? array_values(array_filter(AccountStatus::cases(), fn (AccountStatus $next) => $next !== AccountStatus::New && $next !== $account->status))
            : $account->status->switchableTo($account->verified_at !== null);

        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('A :from account can’t become :to.', [
                'from' => str_replace('_', ' ', $account->status->value),
                'to' => $status->value,
            ])]);
        }

        $needsReason = $unrestricted || in_array($status, [AccountStatus::Paused, AccountStatus::Disabled], true);

        if ($needsReason && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages(['reason' => __('Give a reason for this status change.')]);
        }

        DB::transaction(function () use ($actor, $account, $status, $reason) {
            $old = $account->status;
            $fill = ['status' => $status];

            if ($status === AccountStatus::Rejected) {
                $fill['rejected_reason'] = trim((string) $reason);
            } elseif ($old === AccountStatus::Rejected) {
                $fill['rejected_reason'] = null;
            }

            if (in_array($status, [AccountStatus::Verified, AccountStatus::Active, AccountStatus::Paused], true) && $account->verified_at === null) {
                $fill['verified_at'] = now();
                $fill['verified_by'] = $actor->id;
            }

            $account->forceFill($fill)->save();

            AuditLog::record('payment_account.status_changed', $account, ['status' => $old->value], array_filter([
                'status' => $status->value,
                'reason' => $reason,
            ]), $actor);
        });

        return $account;
    }
}
