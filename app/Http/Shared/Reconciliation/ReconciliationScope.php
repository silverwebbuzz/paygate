<?php

namespace App\Http\Shared\Reconciliation;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Enums\AccountVerification;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\Models\StatementImport;
use Illuminate\Database\Eloquent\Builder;

/**
 * What each portal may see of statements and cases: Admin everything, a
 * branch only its own accounts. Partners never see reconciliation data.
 */
final class ReconciliationScope
{
    /**
     * @return Builder<StatementEntry>
     */
    public static function entries(User $actor): Builder
    {
        return StatementEntry::query()->when(! $actor->isType(UserType::Admin), fn (Builder $query) => $query->where('branch_id', $actor->branch_id));
    }

    /**
     * @return Builder<ReconciliationCase>
     */
    public static function cases(User $actor): Builder
    {
        return ReconciliationCase::query()->when(! $actor->isType(UserType::Admin), fn (Builder $query) => $query->where('branch_id', $actor->branch_id));
    }

    /**
     * @return Builder<StatementImport>
     */
    public static function imports(User $actor): Builder
    {
        return StatementImport::query()->when(! $actor->isType(UserType::Admin), fn (Builder $query) => $query->where('branch_id', $actor->branch_id));
    }

    /**
     * Accounts whose statement lines may be listed and filtered: every
     * verified account, whether or not it is switched on (history).
     *
     * @return Builder<PaymentAccount>
     */
    public static function accounts(User $actor): Builder
    {
        return PaymentAccount::query()
            ->where('verification', AccountVerification::Verified)
            ->when(! $actor->isType(UserType::Admin), fn (Builder $query) => $query->where('branch_id', $actor->branch_id));
    }

    /**
     * Accounts a new line can be typed or imported for: verified and active.
     * An inactive (switched-off) or deleted account takes no new lines
     * (client request 2026-11-23): its old lines still show in the filters.
     *
     * @return Builder<PaymentAccount>
     */
    public static function entryAccounts(User $actor): Builder
    {
        return self::accounts($actor)->where('status', AccountStatus::Active);
    }

    public static function allows(User $actor, ?string $branchId): bool
    {
        return $actor->isType(UserType::Admin) || ($actor->isType(UserType::Branch) && $branchId !== null && $branchId === $actor->branch_id);
    }
}
