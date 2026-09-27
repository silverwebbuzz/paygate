<?php

namespace App\Domain\Branch\Actions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Branch\Models\BranchLimitTopup;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds to (or, with a negative amount, corrects) the deposit allowance of a
 * `topup` branch (D-3). Every change is kept in branch_limit_topups, which
 * can't be edited or deleted, with the balance after it.
 */
class TopUpBranchLimit
{
    public function handle(User $actor, Branch $branch, int $amount, string $reason): BranchLimitTopup
    {
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount other than zero.')]);
        }

        return DB::transaction(function () use ($actor, $branch, $amount, $reason) {
            /** @var Branch $locked */
            $locked = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();

            if (! $locked->usesTopup()) {
                throw ValidationException::withMessages(['amount' => __('This branch uses a daily limit, not a top-up balance.')]);
            }

            $after = $locked->deposit_topup_balance + $amount;

            if ($after < 0) {
                throw ValidationException::withMessages(['amount' => __('The balance can’t go below zero (it is :balance paise).', ['balance' => $locked->deposit_topup_balance])]);
            }

            $locked->update(['deposit_topup_balance' => $after]);

            $topup = BranchLimitTopup::create([
                'branch_id' => $locked->id,
                'amount' => $amount,
                'balance_after' => $after,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);

            AuditLog::record('branch.limit_topped_up', $locked, ['deposit_topup_balance' => $after - $amount], [
                'deposit_topup_balance' => $after,
                'amount' => $amount,
                'reason' => $reason,
            ], $actor);

            $branch->setRawAttributes($locked->getAttributes(), true);

            return $topup;
        });
    }
}
