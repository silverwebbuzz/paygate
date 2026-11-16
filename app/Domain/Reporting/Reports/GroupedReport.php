<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Ledger\Ledger;
use App\Domain\Partner\Models\Partner;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Scope;
use App\Domain\Transaction\Models\Transaction;

/**
 * Partner-wise, branch-wise and partner↔branch reports: the transactions
 * that succeeded in the period, grouped, with commissions and each
 * party's position now.
 */
class GroupedReport extends Report
{
    /**
     * @param  'partners'|'branches'|'pairs'  $by
     */
    public function __construct(private string $by, private Ledger $ledger) {}

    public function key(): string
    {
        return $this->by;
    }

    public function title(): string
    {
        return match ($this->by) {
            'partners' => 'Partner-wise',
            'branches' => 'Branch-wise',
            'pairs' => 'Partner ↔ branch',
        };
    }

    public function description(): string
    {
        return match ($this->by) {
            'partners' => 'Per partner: successful deposits and withdrawals, fees paid, margin and balance now.',
            'branches' => 'Per branch: deposits received, payouts paid, commission earned and position now.',
            'pairs' => 'Per partner↔branch pair: volumes, commissions and both positions now.',
        };
    }

    public function portals(): array
    {
        // A branch's per-partner view is the pair report limited to itself.
        return $this->by === 'pairs' ? [UserType::Admin, UserType::Branch] : [UserType::Admin];
    }

    public function columns(Scope $scope): array
    {
        $admin = $scope->isAdmin();

        return array_values(array_filter([
            $this->by !== 'branches' ? $this->column('partner', 'Partner') : null,
            $this->by !== 'partners' && $admin ? $this->column('branch', 'Branch') : null,
            $this->column('payin_count', 'Deposits', 'count'),
            $this->column('payin_amount', 'Deposit amount', 'money'),
            $this->column('payout_count', 'Withdrawals', 'count'),
            $this->column('payout_amount', 'Withdrawal amount', 'money'),
            $this->by !== 'branches' && $admin ? $this->column('partner_commission', 'Partner fees', 'money') : null,
            $this->by !== 'partners' ? $this->column('branch_commission', $admin ? 'Branch commission' : 'Commission', 'money') : null,
            $admin ? $this->column('platform_margin', 'Margin', 'money') : null,
            $this->by !== 'branches' && $admin ? $this->column('partner_position', 'Partner position now', 'signed') : null,
            $this->by !== 'partners' ? $this->column('branch_position', $admin ? 'Branch position now' : 'Position now', 'signed') : null,
        ]));
    }

    public function rows(Scope $scope, Period $period, array $filters): iterable
    {
        $group = match ($this->by) {
            'partners' => ['partner_id'],
            'branches' => ['branch_id'],
            'pairs' => ['partner_id', 'branch_id'],
        };

        $rows = $scope->apply(Transaction::query())
            ->where('status', 'success')
            ->whereNotNull('branch_id')
            ->where('succeeded_at', '>=', $period->from)
            ->where('succeeded_at', '<', $period->to)
            ->groupBy($group)
            ->toBase()
            ->selectRaw(implode(', ', $group).", COUNT(*) FILTER (WHERE direction = 'payin') AS payin_count, COALESCE(SUM(amount) FILTER (WHERE direction = 'payin'), 0) AS payin_amount, COUNT(*) FILTER (WHERE direction = 'payout') AS payout_count, COALESCE(SUM(amount) FILTER (WHERE direction = 'payout'), 0) AS payout_amount, COALESCE(SUM(partner_commission), 0) AS partner_commission, COALESCE(SUM(branch_commission), 0) AS branch_commission, COALESCE(SUM(platform_margin), 0) AS platform_margin")
            ->get();

        $partners = Partner::query()->pluck('code', 'id');
        $branches = Branch::query()->pluck('code', 'id');
        $pairs = $this->ledger->pairPositions();
        $position = function (string $kind, ?string $partnerId, ?string $branchId) use ($pairs): int {
            $total = 0;

            foreach ($pairs as $pair) {
                if (($partnerId === null || $pair['partner_id'] === $partnerId) && ($branchId === null || $pair['branch_id'] === $branchId)) {
                    $total += $pair[$kind];
                }
            }

            return $total;
        };

        foreach ($rows->sortBy(fn (object $row) => ($partners[$row->partner_id ?? ''] ?? '').($branches[$row->branch_id ?? ''] ?? '')) as $row) {
            $partnerId = isset($row->partner_id) ? (string) $row->partner_id : null;
            $branchId = isset($row->branch_id) ? (string) $row->branch_id : null;

            yield [
                'partner' => $partnerId === null ? null : ($partners[$partnerId] ?? '—'),
                'branch' => $branchId === null ? null : ($branches[$branchId] ?? '—'),
                'payin_count' => (int) $row->payin_count,
                'payin_amount' => (int) $row->payin_amount,
                'payout_count' => (int) $row->payout_count,
                'payout_amount' => (int) $row->payout_amount,
                'partner_commission' => (int) $row->partner_commission,
                'branch_commission' => (int) $row->branch_commission,
                'platform_margin' => (int) $row->platform_margin,
                'partner_position' => $position('partner', $partnerId, $branchId),
                'branch_position' => $position('branch', $partnerId, $branchId),
            ];
        }
    }
}
