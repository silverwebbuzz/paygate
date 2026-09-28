<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Period;
use App\Domain\Reporting\Scope;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Database\Query\Builder;

/**
 * Pay-in or payout report: every transaction created in the period, with
 * its status, UTRs, dates and the commissions the viewer may see.
 */
class TransactionsReport extends Report
{
    public function __construct(private string $direction) {}

    public function key(): string
    {
        return $this->direction === 'payin' ? 'payins' : 'payouts';
    }

    public function title(): string
    {
        return $this->direction === 'payin' ? 'Pay-ins' : 'Payouts';
    }

    public function description(): string
    {
        return $this->direction === 'payin'
            ? 'Every deposit created in the period: customer, amount, method, UTRs, status, dates and commission.'
            : 'Every withdrawal created in the period: beneficiary, amount, UTR, status, dates and fee.';
    }

    public function filters(Scope $scope): array
    {
        return array_filter([
            'status' => 'Status',
            'partner' => $scope->partnerId === null ? 'Partner' : null,
            'branch' => $scope->isAdmin() ? 'Branch' : null,
        ]);
    }

    public function columns(Scope $scope): array
    {
        return array_values(array_filter([
            $this->column('reference', 'Transaction'),
            $this->column('order_id', 'Order id'),
            $this->column('created_at', 'Created', 'date'),
            $scope->partnerId === null ? $this->column('partner', 'Partner') : null,
            $scope->isAdmin() ? $this->column('branch', 'Branch') : null,
            $this->column('customer', 'Customer id'),
            $this->direction === 'payout' ? $this->column('beneficiary', 'Beneficiary') : $this->column('method', 'Method'),
            $this->column('amount', 'Amount', 'money'),
            $this->direction === 'payin' ? $this->column('customer_utr', 'Customer UTR') : null,
            $this->column('bank_utr', 'Bank UTR'),
            $this->column('status', 'Status', 'status'),
            $this->column('decided_at', $this->direction === 'payin' ? 'Decided' : 'Paid / failed', 'date'),
            $scope->sees('partner_commission') ? $this->column('partner_commission', $scope->isAdmin() ? 'Partner fee' : 'Fee', 'money') : null,
            $scope->sees('branch_commission') ? $this->column('branch_commission', $scope->isAdmin() ? 'Branch commission' : 'Commission', 'money') : null,
            $scope->sees('margin') ? $this->column('platform_margin', 'Margin', 'money') : null,
        ]));
    }

    public function rows(Scope $scope, Period $period, array $filters): iterable
    {
        foreach ($this->query($scope, $period, $filters)->select([
            't.reference', 't.partner_transaction_id', 't.created_at', 't.method', 't.amount', 't.customer_utr_normalized',
            't.bank_utr_normalized', 't.status', 't.decided_at', 't.partner_commission', 't.branch_commission', 't.platform_margin',
            'p.code as partner_code', 'b.code as branch_code', 'c.external_id as customer_id', 'pb.account_holder_name as beneficiary',
        ])->orderBy('t.created_at')->orderBy('t.id')->lazy(1000) as $row) {
            yield [
                'reference' => $row->reference,
                'order_id' => $row->partner_transaction_id,
                'created_at' => $row->created_at,
                'partner' => $row->partner_code,
                'branch' => $row->branch_code,
                'customer' => $row->customer_id,
                'method' => match ($row->method) {
                    'upi' => 'UPI',
                    'qr' => 'QR code',
                    'upi_intent' => 'UPI app',
                    'bank_transfer' => 'Bank transfer',
                    default => $row->method,
                },
                'beneficiary' => $row->beneficiary,
                'amount' => (int) $row->amount,
                'customer_utr' => $row->customer_utr_normalized,
                'bank_utr' => $row->bank_utr_normalized,
                'status' => $row->status,
                'decided_at' => $row->decided_at,
                'partner_commission' => $row->partner_commission === null ? null : (int) $row->partner_commission,
                'branch_commission' => $row->branch_commission === null ? null : (int) $row->branch_commission,
                'platform_margin' => $row->platform_margin === null ? null : (int) $row->platform_margin,
            ];
        }
    }

    public function totals(Scope $scope, Period $period, array $filters): array
    {
        $sums = $this->query($scope, $period, $filters)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(t.amount), 0) AS amount, SUM(t.partner_commission) AS pc, SUM(t.branch_commission) AS bc, SUM(t.platform_margin) AS pm')
            ->first();

        return [
            '_count' => (int) ($sums->n ?? 0),
            'amount' => (int) ($sums->amount ?? 0),
            'partner_commission' => (int) ($sums->pc ?? 0),
            'branch_commission' => (int) ($sums->bc ?? 0),
            'platform_margin' => (int) ($sums->pm ?? 0),
        ];
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    private function query(Scope $scope, Period $period, array $filters): Builder
    {
        $query = $scope->apply(Transaction::query()->from('transactions as t'), 't')
            ->toBase()
            ->leftJoin('partners as p', 'p.id', '=', 't.partner_id')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->leftJoin('partner_customers as c', 'c.id', '=', 't.partner_customer_id')
            ->leftJoin('payout_beneficiaries as pb', 'pb.transaction_id', '=', 't.id')
            ->where('t.direction', $this->direction)
            ->where('t.created_at', '>=', $period->from)
            ->where('t.created_at', '<', $period->to)
            ->when(($filters['status'] ?? null) !== null, fn (Builder $query) => $query->where('t.status', $filters['status']))
            ->when(($filters['partner'] ?? null) !== null && $scope->partnerId === null, fn (Builder $query) => $query->where('t.partner_id', $filters['partner']))
            ->when(($filters['branch'] ?? null) !== null && $scope->isAdmin(), fn (Builder $query) => $query->where('t.branch_id', $filters['branch']));

        return $query;
    }
}
