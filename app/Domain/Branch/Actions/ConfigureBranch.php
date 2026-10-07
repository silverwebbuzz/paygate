<?php

namespace App\Domain\Branch\Actions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Actions\SetCommissionRate;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\RateBook;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Actions\CreateUser;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Network\Actions\SyncMappings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a branch in one transaction: profile and limits, the
 * commission the branch earns, which partners it serves, and (on create)
 * its branch admin (with a password). A section passed as null is left
 * unchanged. A new branch starts as a draft.
 */
class ConfigureBranch
{
    public function __construct(
        private SyncMappings $mappings,
        private SetCommissionRate $setRate,
        private RateBook $rates,
        private CreateUser $createUser,
        private ChangeBranchStatus $changeStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  branch columns (amounts in paise)
     * @param  array<string, string>|null  $rates  direction value => rate percent
     * @param  list<string>|null  $partnerIds
     * @param  array{username: string, password: string}|null  $admin  branch admin to create (create only)
     * @return array{branch: Branch, negative_margins: array<string, mixed>}
     */
    public function handle(User $actor, ?Branch $branch, array $attributes, ?array $rates, ?array $partnerIds, ?array $admin = null, bool $activate = false): array
    {
        return DB::transaction(function () use ($actor, $branch, $attributes, $rates, $partnerIds, $admin, $activate) {
            if ($branch === null) {
                $branch = Branch::create([...$attributes, 'status' => OrganisationStatus::Draft]);
                AuditLog::record('branch.created', $branch, [], $branch->only(array_keys($attributes)), $actor);

                if ($admin !== null) {
                    $this->createUser->handle($actor, UserType::Branch, $branch->id, $admin['username'], Role::bySlug(SystemRoles::BRANCH_OWNER), $admin['password']);
                }
            } else {
                $this->update($actor, $branch, $attributes);
            }

            if ($partnerIds !== null) {
                $this->mappings->forBranch($actor, $branch, $partnerIds);
            }

            $negative = [];

            foreach ($rates ?? [] as $direction => $rate) {
                $direction = Direction::from($direction);
                $problems = $this->rates->negativeMarginsForBranch($branch, $direction, $rate);
                $this->setRate->handle($actor, 'branch', $branch, 'branch', $direction, $rate, $problems);

                if ($problems !== []) {
                    $negative[$direction->value] = $problems;
                }
            }

            if ($activate && $branch->status !== OrganisationStatus::Active) {
                $this->changeStatus->handle($actor, $branch, OrganisationStatus::Active, __('Activated from the branch form.'));
            }

            return ['branch' => $branch, 'negative_margins' => $negative];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function update(User $actor, Branch $branch, array $attributes): void
    {
        $branch->fill($attributes);

        if ($branch->status !== OrganisationStatus::Draft && $branch->isDirty('code')) {
            throw ValidationException::withMessages(['code' => __('The branch code can’t change after the branch went live.')]);
        }

        $changed = array_keys($branch->getDirty());

        if ($changed === []) {
            return;
        }

        $old = array_intersect_key($branch->getOriginal(), array_flip($changed));
        $branch->save();

        AuditLog::record('branch.updated', $branch, $old, $branch->only($changed), $actor);
    }
}
