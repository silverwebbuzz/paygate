<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Branch\Models\Branch;
use App\Domain\Ledger\Ledger;
use App\Domain\Partner\Models\Partner;
use App\Domain\Payout\PartnerBalance;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Scope;

/**
 * Balances now (not a period): Admin per partner↔branch pair (both
 * positions and payout holds); a branch per partner; a partner its total,
 * holds, available and largest single payout (never per branch).
 */
class BalancesReport extends Report
{
    public function __construct(private Ledger $ledger, private PartnerBalance $balances) {}

    public function key(): string
    {
        return 'balances';
    }

    public function title(): string
    {
        return 'Balances';
    }

    public function description(): string
    {
        return 'Where everyone stands right now: + means PayGate owes the party, − the party owes PayGate.';
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function columns(Scope $scope): array
    {
        if ($scope->partnerId !== null) {
            return [
                $this->column('balance', 'Balance', 'signed'),
                $this->column('reserved', 'Held for payouts', 'money'),
                $this->column('available', 'Available', 'signed'),
                $this->column('max_payout', 'Largest single payout', 'money'),
            ];
        }

        return array_values(array_filter([
            $this->column('partner', 'Partner'),
            $scope->isAdmin() ? $this->column('branch', 'Branch') : null,
            $scope->isAdmin() ? $this->column('partner_position', 'Partner position', 'signed') : null,
            $scope->isAdmin() ? $this->column('reserved', 'Held for payouts', 'money') : null,
            $this->column('branch_position', $scope->isAdmin() ? 'Branch position' : 'Position', 'signed'),
        ]));
    }

    public function rows(Scope $scope, Period $period, array $filters): iterable
    {
        if ($scope->partnerId !== null) {
            $summary = $this->balances->summary(Partner::query()->findOrFail($scope->partnerId));

            yield ['balance' => (int) $summary['balance'], 'reserved' => (int) $summary['reserved'], 'available' => (int) $summary['available'], 'max_payout' => (int) $summary['max_payout']];

            return;
        }

        $partners = Partner::query()->pluck('code', 'id');
        $branches = Branch::query()->pluck('code', 'id');
        $pairs = array_filter($this->ledger->pairPositions(), fn (array $pair) => $scope->isAdmin() || $pair['branch_id'] === $scope->branchId);

        usort($pairs, fn (array $a, array $b) => [$partners[$a['partner_id']] ?? '', $branches[$a['branch_id']] ?? ''] <=> [$partners[$b['partner_id']] ?? '', $branches[$b['branch_id']] ?? '']);

        foreach ($pairs as $pair) {
            yield [
                'partner' => $partners[$pair['partner_id']] ?? '—',
                'branch' => $branches[$pair['branch_id']] ?? '—',
                'partner_position' => $pair['partner'],
                'reserved' => $pair['reserved'],
                'branch_position' => $pair['branch'],
            ];
        }
    }
}
