<?php

namespace App\Domain\Network\Actions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use Illuminate\Support\Facades\DB;

/**
 * Sets which branches may collect and pay out for a partner, from either
 * side (a partner's branches, or a branch's partners). Unticked pairs are
 * deactivated, never deleted (transactions and ledger accounts refer to the
 * pair); ticking them again reactivates the same mapping.
 */
class SyncMappings
{
    /**
     * @param  list<string>  $branchIds
     */
    public function forPartner(User $actor, Partner $partner, array $branchIds): void
    {
        $this->sync($actor, $partner, 'partner_id', 'branch_id', $branchIds);
    }

    /**
     * @param  list<string>  $partnerIds
     */
    public function forBranch(User $actor, Branch $branch, array $partnerIds): void
    {
        $this->sync($actor, $branch, 'branch_id', 'partner_id', $partnerIds);
    }

    /**
     * Maps one pair (or reactivates it); returns the mapping.
     */
    public function map(User $actor, Partner $partner, Branch $branch): PartnerBranchMapping
    {
        return DB::transaction(function () use ($actor, $partner, $branch) {
            $mapping = PartnerBranchMapping::query()
                ->where(['partner_id' => $partner->id, 'branch_id' => $branch->id])
                ->lockForUpdate()
                ->first();

            if ($mapping?->status === 'active') {
                return $mapping;
            }

            $mapping ??= new PartnerBranchMapping(['partner_id' => $partner->id, 'branch_id' => $branch->id, 'created_by' => $actor->id]);
            $mapping->status = 'active';
            $mapping->save();

            AuditLog::record('mapping.activated', $mapping, [], ['partner' => $partner->code, 'branch' => $branch->code], $actor);

            return $mapping;
        });
    }

    /**
     * @param  list<string>  $otherIds
     */
    private function sync(User $actor, Partner|Branch $owner, string $ownerColumn, string $otherColumn, array $otherIds): void
    {
        $otherIds = array_values(array_unique($otherIds));

        DB::transaction(function () use ($actor, $owner, $ownerColumn, $otherColumn, $otherIds) {
            $mappings = PartnerBranchMapping::query()->where($ownerColumn, $owner->id)->lockForUpdate()->get()->keyBy($otherColumn);
            $added = [];
            $removed = [];

            foreach ($otherIds as $otherId) {
                $mapping = $mappings->get($otherId);

                if ($mapping === null) {
                    PartnerBranchMapping::create([
                        $ownerColumn => $owner->id,
                        $otherColumn => $otherId,
                        'status' => 'active',
                        'created_by' => $actor->id,
                    ]);
                    $added[] = $otherId;
                } elseif ($mapping->status !== 'active') {
                    $mapping->update(['status' => 'active']);
                    $added[] = $otherId;
                }
            }

            foreach ($mappings as $otherId => $mapping) {
                if ($mapping->status === 'active' && ! in_array($otherId, $otherIds, true)) {
                    $mapping->update(['status' => 'inactive']);
                    $removed[] = (string) $otherId;
                }
            }

            if ($added === [] && $removed === []) {
                return;
            }

            $codes = ($owner instanceof Partner ? Branch::query() : Partner::query())
                ->whereIn('id', [...$added, ...$removed])
                ->pluck('code', 'id');

            AuditLog::record($owner instanceof Partner ? 'partner.branches_updated' : 'branch.partners_updated', $owner, [], [
                'added' => array_map(fn ($id) => $codes[$id] ?? $id, $added),
                'removed' => array_map(fn ($id) => $codes[$id] ?? $id, $removed),
            ], $actor);
        });
    }
}
