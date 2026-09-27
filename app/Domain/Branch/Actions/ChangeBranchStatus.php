<?php

namespace App\Domain\Branch\Actions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\RateBook;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Activates, suspends or offboards a branch. Activation needs a commission
 * rate for each enabled direction (commission is calculated on every
 * successful transaction). A branch without accounts may go live; it simply
 * receives no customers until an account is verified and active.
 */
class ChangeBranchStatus
{
    public function __construct(private RateBook $rates) {}

    public function handle(User $actor, Branch $branch, OrganisationStatus $status, string $reason): Branch
    {
        if (! $branch->status->canMoveTo($status)) {
            throw ValidationException::withMessages(['status' => __('A :from branch can’t become :to.', [
                'from' => str_replace('_', ' ', $branch->status->value),
                'to' => str_replace('_', ' ', $status->value),
            ])]);
        }

        if ($status === OrganisationStatus::Active) {
            $missing = $this->activationBlockers($branch);

            if ($missing !== []) {
                throw ValidationException::withMessages(['status' => __('Not ready to go live: :missing.', ['missing' => implode('; ', $missing)])]);
            }
        }

        DB::transaction(function () use ($actor, $branch, $status, $reason) {
            $old = $branch->status;
            $branch->status = $status;

            if ($status === OrganisationStatus::Active && $branch->verified_at === null) {
                $branch->verified_at = now();
                $branch->verified_by = $actor->id;
            }

            $branch->save();

            AuditLog::record('branch.status_changed', $branch, ['status' => $old->value], ['status' => $status->value, 'reason' => $reason], $actor);
        });

        return $branch;
    }

    /**
     * @return list<string>
     */
    public function activationBlockers(Branch $branch): array
    {
        $missing = [];

        if (! $branch->is_deposit_enabled && ! $branch->is_withdrawal_enabled) {
            $missing[] = __('enable deposits or withdrawals');
        }

        if ($branch->is_deposit_enabled && $this->rates->branchRate($branch, Direction::Deposit) === null) {
            $missing[] = __('set the deposit commission');
        }

        if ($branch->is_withdrawal_enabled && $this->rates->branchRate($branch, Direction::Withdrawal) === null) {
            $missing[] = __('set the withdrawal commission');
        }

        return $missing;
    }
}
