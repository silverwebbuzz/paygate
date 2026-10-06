<?php

namespace App\Http\Shared\Accounts;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Illuminate\Support\Collection;

/**
 * The account row both account screens (Admin, branch portal) show.
 * Admin sees the full account number and UPI ID. A branch sees them masked.
 */
class AccountPresenter
{
    public function __construct(private UsageCounters $usage) {}

    /**
     * @param  Collection<int, PaymentAccount>  $accounts
     * @return array<int, array<string, mixed>>
     */
    public function rows(Collection $accounts, User $actor): array
    {
        $usage = $this->usage->today('account', $accounts->pluck('id')->all(), Direction::Deposit);

        $full = $actor->isType(UserType::Admin);

        return $accounts->map(fn (PaymentAccount $account) => [
            'id' => $account->id,
            'label' => $account->label,
            'holder' => $account->account_holder_name,
            'branch' => ['id' => $account->branch->id, 'code' => $account->branch->code, 'name' => $account->branch->name],
            'is_bank_enabled' => $account->is_bank_enabled,
            'bank_name' => $account->bank_name,
            'ifsc' => $account->ifsc,
            'account_number' => $full && is_string($account->account_number_encrypted) && $account->account_number_encrypted !== ''
                ? $account->account_number_encrypted
                : $account->maskedAccountNumber(),
            'is_upi_enabled' => $account->is_upi_enabled,
            'upi_id' => $full && is_string($account->upi_id_encrypted) && $account->upi_id_encrypted !== ''
                ? $account->upi_id_encrypted
                : $account->maskedUpiId(),
            'upi_display_name' => $account->upi_display_name,
            'upi_code' => $account->upi_code,
            'is_qr_enabled' => $account->is_qr_enabled,
            'min_amount' => $account->min_amount,
            'max_amount' => $account->max_amount,
            'daily_amount_limit' => $account->daily_amount_limit,
            'daily_count_limit' => $account->daily_count_limit,
            'max_open_sessions' => $account->max_open_sessions,
            'used_today' => $usage[$account->id] ?? ['amount' => 0, 'count' => 0],
            'status' => $account->status->value,
            'rejected_reason' => $account->rejected_reason,
            'verified_at' => $account->verified_at?->toIso8601String(),
            'created_at' => $account->created_at?->toIso8601String(),
            'can' => [
                'update' => $actor->can('accounts.update') && ($full || $account->status !== AccountStatus::Disabled),
                'verify' => $actor->can('accounts.verify') && $account->status === AccountStatus::VerificationPending,
                'switch_to' => $actor->can('accounts.update')
                    ? ($full
                        ? array_values(array_map(
                            fn (AccountStatus $status) => $status->value,
                            array_filter(AccountStatus::cases(), fn (AccountStatus $status) => $status !== AccountStatus::New && $status !== $account->status),
                        ))
                        : array_map(fn (AccountStatus $status) => $status->value, $account->status->switchableTo()))
                    : [],
            ],
        ])->values()->all();
    }
}
