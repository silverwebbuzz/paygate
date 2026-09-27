<?php

namespace App\Domain\Commission;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\Models\CommissionRate;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;

/**
 * Reads the rates in force: a pair (mapping) override for a side wins,
 * otherwise the partner's rate (side partner) or the branch's rate (side
 * branch). Database.md §2.3 "Rate lookup".
 */
class RateBook
{
    public function current(string $subjectType, string $subjectId, string $side, Direction $direction): ?string
    {
        return CommissionRate::query()
            ->for($subjectType, $subjectId, $side, $direction)
            ->inForceAt()
            ->value('rate_percent');
    }

    /**
     * Current rates of many subjects at once (for lists).
     *
     * @param  array<mixed>  $subjectIds
     * @return array<string, array<string, string>> subject id => direction => rate
     */
    public function currentForMany(string $subjectType, array $subjectIds, string $side): array
    {
        $rates = [];

        CommissionRate::query()
            ->where(['subject_type' => $subjectType, 'side' => $side])
            ->whereIn('subject_id', $subjectIds)
            ->inForceAt()
            ->get(['subject_id', 'direction', 'rate_percent'])
            ->each(function (CommissionRate $rate) use (&$rates) {
                $rates[$rate->subject_id][$rate->direction->value] = $rate->rate_percent;
            });

        return $rates;
    }

    public function partnerRate(Partner $partner, Direction $direction): ?string
    {
        return $this->current('partner', $partner->id, 'partner', $direction);
    }

    public function branchRate(Branch $branch, Direction $direction): ?string
    {
        return $this->current('branch', $branch->id, 'branch', $direction);
    }

    /**
     * The partner and branch rates that apply to a pair.
     *
     * @return array{partner: string|null, branch: string|null}
     */
    public function forPair(PartnerBranchMapping $mapping, Direction $direction): array
    {
        return [
            'partner' => $this->current('mapping', $mapping->id, 'partner', $direction)
                ?? $this->current('partner', $mapping->partner_id, 'partner', $direction),
            'branch' => $this->current('mapping', $mapping->id, 'branch', $direction)
                ?? $this->current('branch', $mapping->branch_id, 'branch', $direction),
        ];
    }

    /**
     * Branches mapped to the partner that would earn more than the partner
     * pays in this direction if the partner's rate were `$partnerRate`
     * (the platform would lose the difference on every transaction).
     *
     * @return list<array{branch_id: string, code: string, name: string, partner_rate: string, branch_rate: string}>
     */
    public function negativeMargins(Partner $partner, Direction $direction, ?string $partnerRate = null): array
    {
        $problems = [];

        $mappings = PartnerBranchMapping::query()
            ->where('partner_id', $partner->id)
            ->where('status', 'active')
            ->with('branch')
            ->get();

        foreach ($mappings as $mapping) {
            $rates = $this->forPair($mapping, $direction);
            $pairOverride = $this->current('mapping', $mapping->id, 'partner', $direction);
            $effectivePartner = $pairOverride ?? $partnerRate ?? $rates['partner'];

            if ($effectivePartner === null || $rates['branch'] === null) {
                continue;
            }

            if (RatePercent::compare($effectivePartner, $rates['branch']) < 0) {
                $problems[] = [
                    'branch_id' => $mapping->branch_id,
                    'code' => $mapping->branch->code,
                    'name' => $mapping->branch->name,
                    'partner_rate' => RatePercent::normalize($effectivePartner),
                    'branch_rate' => RatePercent::normalize($rates['branch']),
                ];
            }
        }

        return $problems;
    }

    /**
     * Partners mapped to the branch that would pay less than the branch
     * earns in this direction if the branch's rate were `$branchRate`.
     *
     * @return list<array{partner_id: string, code: string, name: string, partner_rate: string, branch_rate: string}>
     */
    public function negativeMarginsForBranch(Branch $branch, Direction $direction, ?string $branchRate = null): array
    {
        $problems = [];

        $mappings = PartnerBranchMapping::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'active')
            ->with('partner')
            ->get();

        foreach ($mappings as $mapping) {
            $rates = $this->forPair($mapping, $direction);
            $pairOverride = $this->current('mapping', $mapping->id, 'branch', $direction);
            $effectiveBranch = $pairOverride ?? $branchRate ?? $rates['branch'];

            if ($effectiveBranch === null || $rates['partner'] === null) {
                continue;
            }

            if (RatePercent::compare($rates['partner'], $effectiveBranch) < 0) {
                $problems[] = [
                    'partner_id' => $mapping->partner_id,
                    'code' => $mapping->partner->code,
                    'name' => $mapping->partner->name,
                    'partner_rate' => RatePercent::normalize($rates['partner']),
                    'branch_rate' => RatePercent::normalize($effectiveBranch),
                ];
            }
        }

        return $problems;
    }
}
