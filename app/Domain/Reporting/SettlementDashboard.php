<?php

namespace App\Domain\Reporting;

use App\Domain\Branch\Models\Branch;
use App\Domain\Ledger\Ledger;
use App\Domain\Partner\Models\Partner;
use App\Domain\Payout\PayoutBookings;
use App\Domain\Transaction\Models\Transaction;

/**
 * Settlement Dashboard (client's Admin / Branch / Partner Settlement
 * Dashboard): for a period, what moved on the partner or branch positions,
 * from the ledger by posting time, the same way settlements are calculated:
 *
 *   deposits − commission, payouts + commission, chargebacks (on the day
 *   Admin approved them), payout returns (on the day the branch confirmed
 *   them), adjustments and refunds = Current Balance;
 *   + settlement payments recorded = Net Balance.
 *
 * Signs follow the ledger: positive = PayGate owes the party, negative =
 * the party owes PayGate. The position at the start / end includes anything
 * left unpaid from before the period.
 */
final class SettlementDashboard
{
    public function __construct(private Ledger $ledger) {}

    /**
     * One side (all partner positions or all branch positions) for the pairs
     * matching the filters (empty = all).
     *
     * @param  'partner'|'branch'  $side
     * @param  list<string>  $partnerIds
     * @param  list<string>  $branchIds
     * @return array<string, mixed>
     */
    public function side(string $side, Period $period, array $partnerIds = [], array $branchIds = [], bool $withPairs = true): array
    {
        $accounts = $this->ledger->positionAccounts($side === 'partner' ? Ledger::PARTNER_POSITION : Ledger::BRANCH_POSITION, $partnerIds, $branchIds);
        $accountIds = array_column($accounts, 'id');
        $statement = $this->ledger->statement($accountIds, $period->from, $period->to);
        $ids = array_unique(array_merge([], ...array_column($statement, 'transactions')));
        // Pay-ins by transaction; payouts by the transfers booked in the
        // period (a payout paid in parts is booked part by part).
        $transactions = Transaction::query()
            ->whereIn('id', $ids)
            ->where('direction', 'payin')
            ->get(['id', 'direction', 'partner_id', 'branch_id', 'amount', 'partner_commission', 'branch_commission', 'platform_margin']);
        $payoutParts = PayoutBookings::forJournals(array_merge([], ...array_column($statement, 'payout_journals')));
        $chargebacks = $this->ledger->journalCounts($accountIds, 'chargeback', $period->from, $period->to);
        $returns = $this->ledger->journalCounts($accountIds, 'payout_return', $period->from, $period->to);

        $pairs = [];

        foreach ($accounts as $account) {
            $figures = $statement[$account['id']];
            $booked = $transactions->where('partner_id', $account['partner_id'])->where('branch_id', $account['branch_id']);
            $pairs[] = [
                'partner_id' => $account['partner_id'],
                'branch_id' => $account['branch_id'],
                ...$this->figures($side, [$figures], $booked, PayoutBookings::totals($payoutParts->only($figures['payout_journals'])), $chargebacks[$account['id']] ?? 0, $returns[$account['id']] ?? 0),
            ];
        }

        $total = $this->figures($side, array_values($statement), $transactions, PayoutBookings::totals($payoutParts), array_sum($chargebacks), array_sum($returns));

        if (! $withPairs) {
            return ['total' => $total, 'pairs' => []];
        }

        $partners = Partner::query()->whereIn('id', array_column($pairs, 'partner_id'))->get(['id', 'code', 'name'])->keyBy('id');
        $branches = Branch::query()->whereIn('id', array_column($pairs, 'branch_id'))->get(['id', 'code', 'name'])->keyBy('id');

        $pairs = array_values(array_map(fn (array $pair) => [
            'partner' => ['code' => $partners->get($pair['partner_id'])->code ?? '—', 'name' => $partners->get($pair['partner_id'])->name ?? '—'],
            'branch' => ['code' => $branches->get($pair['branch_id'])->code ?? '—', 'name' => $branches->get($pair['branch_id'])->name ?? '—'],
            ...array_diff_key($pair, array_flip(['partner_id', 'branch_id'])),
        ], array_filter($pairs, fn (array $pair) => $pair['moved'] || $pair['closing'] !== 0)));

        // Grouped by the dashboard's own side (branch view: by branch).
        $order = $side === 'branch' ? ['branch', 'partner'] : ['partner', 'branch'];
        usort($pairs, fn (array $a, array $b) => [$a[$order[0]]['code'], $a[$order[1]]['code']] <=> [$b[$order[0]]['code'], $b[$order[1]]['code']]);

        return ['total' => $total, 'pairs' => $pairs];
    }

    /**
     * Admin view: both sides and PayGate's margin.
     *
     * @param  list<string>  $partnerIds
     * @param  list<string>  $branchIds
     * @return array<string, mixed>
     */
    public function admin(Period $period, array $partnerIds = [], array $branchIds = []): array
    {
        $partner = $this->side('partner', $period, $partnerIds, $branchIds, false)['total'];
        $branch = $this->side('branch', $period, $partnerIds, $branchIds, false)['total'];

        return [
            'partner' => $partner,
            'branch' => $branch,
            'margin' => $partner['margin'],
        ];
    }

    /**
     * @param  list<array{opening: int, closing: int, by_type: array<string, int>, transactions: list<string>}>  $statements
     * @param  iterable<Transaction>  $transactions  pay-ins
     * @param  array{count: int, amount: int, partner_commission: int, branch_commission: int, platform_margin: int}  $payouts  transfers booked
     * @return array<string, mixed>
     */
    private function figures(string $side, array $statements, iterable $transactions, array $payouts, int $chargebackCount, int $returnCount): array
    {
        $byType = [];

        foreach ($statements as $statement) {
            foreach ($statement['by_type'] as $type => $amount) {
                $byType[$type] = ($byType[$type] ?? 0) + $amount;
            }
        }

        $column = $side === 'partner' ? 'partner_commission' : 'branch_commission';
        /** @var array<string, array{count: int, amount: int, commission: int}> $flow */
        $flow = ['payin' => ['count' => 0, 'amount' => 0, 'commission' => 0], 'payout' => ['count' => 0, 'amount' => 0, 'commission' => 0]];
        $flow['payout'] = ['count' => $payouts['count'], 'amount' => $payouts['amount'], 'commission' => $payouts[$column]];
        $margin = $payouts['platform_margin'];

        foreach ($transactions as $txn) {
            $current = $flow[$txn->direction];
            $flow[$txn->direction] = [
                'count' => $current['count'] + 1,
                'amount' => $current['amount'] + $txn->amount,
                'commission' => $current['commission'] + (int) $txn->{$column},
            ];
            $margin += (int) $txn->platform_margin;
        }

        $rate = fn (array $figures) => $figures['amount'] === 0 ? null : round($figures['commission'] * 100 / $figures['amount'], 2);
        $settlement = $byType['settlement'] ?? 0;
        $net = array_sum($byType);

        return [
            'deposit' => [...$flow['payin'], 'rate' => $rate($flow['payin'])],
            'withdraw' => [...$flow['payout'], 'rate' => $rate($flow['payout'])],
            'commission' => $flow['payin']['commission'] + $flow['payout']['commission'],
            'margin' => $margin,
            'chargeback' => ['count' => $chargebackCount, 'amount' => $byType['chargeback'] ?? 0],
            'return' => ['count' => $returnCount, 'amount' => $byType['payout_return'] ?? 0],
            'adjustments' => ($byType['adjustment'] ?? 0) + ($byType['reversal'] ?? 0),
            'settlement' => $settlement,
            // Before / after the settlement payments recorded in the period.
            'current' => $net - $settlement,
            'net' => $net,
            'opening' => array_sum(array_column($statements, 'opening')),
            'closing' => array_sum(array_column($statements, 'closing')),
            'moved' => $byType !== [],
        ];
    }
}
