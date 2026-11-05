<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Partner\Models\Partner;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Scope;
use App\Domain\Transaction\Models\Transaction;

/**
 * Branch / Partner Deposit and Withdrawal reports, as in the client's old
 * system: one row per branch (or partner; a branch or partner sees only its
 * own row) with the successful deposits (or
 * withdrawals) in the period, the commission rate and the commission. The
 * rate is the effective one (commission ÷ amount), so it is exact even when
 * a branch has different rates with different partners.
 */
class PartyReport extends Report
{
    /**
     * @param  'payin'|'payout'  $direction
     * @param  'branch'|'partner'  $by
     */
    public function __construct(private string $direction, private string $by) {}

    public function key(): string
    {
        return $this->by.'_'.($this->direction === 'payin' ? 'deposits' : 'withdrawals');
    }

    public function title(): string
    {
        return ucfirst($this->by).' '.($this->direction === 'payin' ? 'Deposit' : 'Withdrawal').' Report';
    }

    public function description(): string
    {
        $what = $this->direction === 'payin' ? 'successful deposits' : 'paid withdrawals';

        return $this->by === 'branch'
            ? "Per branch: total of the {$what} in the period, the branch's commission rate and commission earned."
            : "Per partner: total of the {$what} in the period, the partner's fee rate and fees charged.";
    }

    public function portals(): array
    {
        // A partner never sees branch commission, and a branch never sees
        // partner fees, so each gets only the reports about itself.
        return [UserType::Admin, $this->by === 'branch' ? UserType::Branch : UserType::Partner];
    }

    public function filters(Scope $scope): array
    {
        // Outside Admin the rows are already the viewer's own.
        return $scope->isAdmin()
            ? [$this->by => ucfirst($this->by), 'search' => 'Search']
            : [];
    }

    public function columns(Scope $scope): array
    {
        return [
            $this->column('sr', 'Sr', 'count'),
            $this->column('name', $this->by === 'branch' ? 'Branch Name' : 'Partner Name'),
            $this->column('amount', 'Total Amount', 'money'),
            $this->column('rate', 'Commission', 'percent'),
            $this->column('commission', 'Total Commission', 'money'),
        ];
    }

    public function rows(Scope $scope, Period $period, array $filters): iterable
    {
        $column = $this->by.'_id';
        $commission = $this->by.'_commission';
        $parties = ($this->by === 'branch' ? Branch::query() : Partner::query())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        $query = $scope->apply(Transaction::query())
            ->where(['direction' => $this->direction, 'status' => 'success'])
            ->whereNotNull($column)
            ->when($filters[$this->by] ?? null, fn ($query, $id) => $query->where($column, $id));
        $period->apply($query, 'succeeded_at');

        $totals = $query
            ->groupBy($column)
            ->toBase()
            ->selectRaw("{$column} AS party_id, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM({$commission}), 0) AS commission")
            ->get()
            ->map(function (object $row) use ($parties) {
                $party = $parties->get((string) $row->party_id);

                return [
                    // Names only on screen; the code still matches a search.
                    'name' => $party === null ? '—' : $party->name,
                    'code' => $party === null ? '' : $party->code,
                    'amount' => (int) $row->amount,
                    'commission' => (int) $row->commission,
                ];
            })
            ->filter(fn (array $row) => $search === '' || str_contains(mb_strtolower($row['name'].' '.$row['code']), $search))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        foreach ($totals as $index => $row) {
            yield [
                'sr' => $index + 1,
                'name' => $row['name'],
                'amount' => $row['amount'],
                'rate' => $row['amount'] === 0 ? 0 : round($row['commission'] / $row['amount'] * 100, 2),
                'commission' => $row['commission'],
            ];
        }
    }

    public function totals(Scope $scope, Period $period, array $filters): array
    {
        // "Sr" is a row number, not a figure to add up.
        return array_diff_key(parent::totals($scope, $period, $filters), ['sr' => true]);
    }
}
