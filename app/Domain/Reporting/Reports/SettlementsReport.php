<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Period;
use App\Domain\Reporting\Scope;
use App\Domain\Settlement\Models\Settlement;

/**
 * Settlements whose period ended in the report period, with the breakdown
 * the viewer may see and how much was paid.
 */
class SettlementsReport extends Report
{
    public function key(): string
    {
        return 'settlements';
    }

    public function title(): string
    {
        return 'Settlements';
    }

    public function description(): string
    {
        return 'Settlements that ended in the period: movements, position at the end, amount to settle and paid.';
    }

    public function filters(Scope $scope): array
    {
        return $scope->isAdmin() ? ['party_type' => 'Partners or branches'] : [];
    }

    public function columns(Scope $scope): array
    {
        return array_values(array_filter([
            $this->column('reference', 'Settlement'),
            $scope->isAdmin() ? $this->column('party', 'Party') : null,
            $this->column('period_start', 'From', 'date'),
            $this->column('period_end', 'To', 'date'),
            $this->column('opening', 'Position at start', 'signed'),
            $this->column('gross_payin', 'Pay-ins', 'money'),
            $this->column('gross_payout', 'Payouts', 'money'),
            $scope->sees('partner_commission') ? $this->column('partner_commission', $scope->isAdmin() ? 'Partner fees' : 'Fees', 'money') : null,
            $scope->sees('branch_commission') ? $this->column('branch_commission', $scope->isAdmin() ? 'Branch commission' : 'Commission', 'money') : null,
            $scope->sees('margin') ? $this->column('platform_margin', 'Margin', 'money') : null,
            $this->column('adjustments', 'Adjustments', 'signed'),
            $this->column('closing', 'Position at end', 'signed'),
            $this->column('net_amount', 'To settle', 'money'),
            $this->column('direction', 'Who pays'),
            $this->column('settled_amount', 'Paid', 'money'),
            $this->column('status', 'Status', 'status'),
        ]));
    }

    public function rows(Scope $scope, Period $period, array $filters): iterable
    {
        $query = Settlement::query()
            ->with(['partner', 'branch'])
            ->where('period_end', '>', $period->from)
            ->where('period_end', '<=', $period->to)
            ->when(! $scope->isAdmin(), fn ($query) => $query->where($scope->partnerId !== null
                ? ['party_type' => 'partner', 'partner_id' => $scope->partnerId]
                : ['party_type' => 'branch', 'branch_id' => $scope->branchId]))
            ->when($scope->isAdmin() && in_array($filters['party_type'] ?? null, ['partner', 'branch'], true), fn ($query) => $query->where('party_type', $filters['party_type']))
            ->orderBy('period_end')
            ->orderBy('reference');

        foreach ($query->lazy(500) as $settlement) {
            /** @var Settlement $settlement */
            yield [
                'reference' => $settlement->reference,
                'party' => $settlement->party_type === 'partner' ? 'Partner '.($settlement->partner->code ?? '—') : 'Branch '.($settlement->branch->code ?? '—'),
                'period_start' => $settlement->period_start->toIso8601String(),
                'period_end' => $settlement->period_end->toIso8601String(),
                'opening' => $settlement->opening_balance,
                'gross_payin' => $settlement->gross_payin,
                'gross_payout' => $settlement->gross_payout,
                'partner_commission' => $settlement->partner_commission,
                'branch_commission' => $settlement->branch_commission,
                'platform_margin' => $settlement->platform_margin,
                'adjustments' => $settlement->adjustments_total,
                'closing' => $settlement->closing_balance,
                'net_amount' => $settlement->net_amount,
                'direction' => match ($settlement->direction) {
                    'party_to_platform' => $scope->isAdmin() ? 'Party pays PayGate' : 'You pay PayGate',
                    'platform_to_party' => $scope->isAdmin() ? 'PayGate pays party' : 'PayGate pays you',
                    default => '—',
                },
                'settled_amount' => $settlement->settled_amount,
                'status' => $settlement->status,
            ];
        }
    }
}
