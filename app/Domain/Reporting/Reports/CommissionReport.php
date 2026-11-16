<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Period;
use App\Domain\Reporting\Scope;
use App\Domain\Transaction\Models\Transaction;

/**
 * Commission per business day: volume and the commissions the viewer may
 * see, from each transaction's snapshotted rates.
 */
class CommissionReport extends Report
{
    public function key(): string
    {
        return 'commission';
    }

    public function title(): string
    {
        return 'Commission';
    }

    public function description(): string
    {
        return 'Per day: successful volume and commission (fees paid, commission earned, margin), at each transaction’s own rates.';
    }

    public function columns(Scope $scope): array
    {
        return array_values(array_filter([
            $this->column('day', 'Day'),
            $this->column('count', 'Transactions', 'count'),
            $this->column('volume', 'Volume', 'money'),
            $scope->sees('partner_commission') ? $this->column('partner_commission', $scope->isAdmin() ? 'Partner fees' : 'Fees paid', 'money') : null,
            $scope->sees('branch_commission') ? $this->column('branch_commission', $scope->isAdmin() ? 'Branch commission' : 'Commission earned', 'money') : null,
            $scope->sees('margin') ? $this->column('platform_margin', 'Margin', 'money') : null,
        ]));
    }

    public function rows(Scope $scope, Period $period, array $filters): iterable
    {
        $rows = $scope->apply(Transaction::query())
            ->where('status', 'success')
            ->where('succeeded_at', '>=', $period->from)
            ->where('succeeded_at', '<', $period->to)
            ->toBase()
            ->selectRaw("to_char(succeeded_at AT TIME ZONE ?, 'YYYY-MM-DD') AS day, COUNT(*) AS count, SUM(amount) AS volume, COALESCE(SUM(partner_commission), 0) AS pc, COALESCE(SUM(branch_commission), 0) AS bc, COALESCE(SUM(platform_margin), 0) AS pm", [config('app.business_timezone')])
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        foreach ($rows as $row) {
            yield [
                'day' => (string) $row->day,
                'count' => (int) $row->count,
                'volume' => (int) $row->volume,
                'partner_commission' => (int) $row->pc,
                'branch_commission' => (int) $row->bc,
                'platform_margin' => (int) $row->pm,
            ];
        }
    }
}
