<?php

namespace App\Domain\Transaction\Policies;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Transaction\Models\Transaction;

/**
 * Who may see and decide a transaction: Admin (by permission) everywhere; a
 * partner only its own; a branch only those paid into its accounts.
 * Who may approve which amounts (G-44) is still open: today any user with
 * payins.approve in scope may approve.
 */
class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        $permission = $transaction->direction === 'payin' ? Permission::PayinsView : Permission::PayoutsView;

        return $user->hasPermission($permission) && $this->inScope($user, $transaction);
    }

    public function decide(User $user, Transaction $transaction): bool
    {
        return $transaction->direction === 'payin'
            && $user->hasPermission(Permission::PayinsApprove)
            && $this->inScope($user, $transaction)
            && $user->type !== UserType::Partner;
    }

    /**
     * Pay, fail or (Admin) reassign a payout: the assigned branch or Admin.
     */
    public function process(User $user, Transaction $transaction): bool
    {
        return $transaction->direction === 'payout'
            && $user->hasPermission(Permission::PayoutsProcess)
            && $this->inScope($user, $transaction)
            && $user->type !== UserType::Partner;
    }

    private function inScope(User $user, Transaction $transaction): bool
    {
        return match ($user->type) {
            UserType::Admin => true,
            UserType::Partner => $transaction->partner_id === $user->partner_id,
            UserType::Branch => $transaction->branch_id !== null && $transaction->branch_id === $user->branch_id,
        };
    }
}
