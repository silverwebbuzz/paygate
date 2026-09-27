<?php

namespace App\Domain\Network\Actions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use Illuminate\Support\Facades\DB;

/**
 * Sets which branches may collect and pay out for a partner. Unticked
 * branches are deactivated (never deleted: transactions and ledger accounts
 * refer to the pair); ticking them again reactivates the same mapping.
 */
class SyncPartnerBranches
{
    /**
     * @param  list<string>  $branchIds
     */
    public function handle(User $actor, Partner $partner, array $branchIds): void
    {
        $branchIds = array_values(array_unique($branchIds));

        DB::transaction(function () use ($actor, $partner, $branchIds) {
            $mappings = PartnerBranchMapping::query()->where('partner_id', $partner->id)->lockForUpdate()->get()->keyBy('branch_id');
            $added = [];
            $removed = [];

            foreach ($branchIds as $branchId) {
                $mapping = $mappings->get($branchId);

                if ($mapping === null) {
                    PartnerBranchMapping::create([
                        'partner_id' => $partner->id,
                        'branch_id' => $branchId,
                        'status' => 'active',
                        'created_by' => $actor->id,
                    ]);
                    $added[] = $branchId;
                } elseif ($mapping->status !== 'active') {
                    $mapping->update(['status' => 'active']);
                    $added[] = $branchId;
                }
            }

            foreach ($mappings as $branchId => $mapping) {
                if ($mapping->status === 'active' && ! in_array($branchId, $branchIds, true)) {
                    $mapping->update(['status' => 'inactive']);
                    $removed[] = (string) $branchId;
                }
            }

            if ($added !== [] || $removed !== []) {
                $codes = Branch::query()->whereIn('id', [...$added, ...$removed])->pluck('code', 'id');

                AuditLog::record('partner.branches_updated', $partner, [], [
                    'added' => array_map(fn ($id) => $codes[$id] ?? $id, $added),
                    'removed' => array_map(fn ($id) => $codes[$id] ?? $id, $removed),
                ], $actor);
            }
        });
    }
}
