import { Head, router } from '@inertiajs/react';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { formatPaise } from '@/lib/money';
import admin from '@/routes/admin';

type Figures = { count: number; amount: number; commission: number };

type Row = {
    id: string;
    code: string;
    name: string;
    payin: Figures;
    payout: Figures;
    margin: number;
};

type Props = {
    by: 'partner' | 'branch';
    filters: { from: string; to: string };
    rows: Row[];
    totals: {
        partner_commission: number;
        branch_commission: number;
        margin: number;
        volume: number;
    };
};

/**
 * Commissions: what partners paid and branches earned on successful
 * transactions in a period, with the margin between them.
 */
export default function Commissions({ by, filters, rows, totals }: Props) {
    const visit = (next: Record<string, string>) =>
        router.get(
            admin.commissions.index({ query: { by, ...filters, ...next } }).url,
            {},
            { preserveState: true, replace: true },
        );
    const noun = by === 'partner' ? 'paid' : 'earned';

    const columns: Column<Row>[] = [
        {
            key: 'party',
            header: by === 'partner' ? 'Partner' : 'Branch',
            cell: (row) => (
                <span>
                    {row.name}{' '}
                    <span className="font-mono text-xs text-tx3">
                        {row.code}
                    </span>
                </span>
            ),
        },
        {
            key: 'payin',
            header: 'Pay-ins',
            align: 'right',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap">
                    <div>{formatPaise(row.payin.amount)}</div>
                    <div className="text-tx3">
                        {row.payin.count} transactions
                    </div>
                </div>
            ),
        },
        {
            key: 'payin_commission',
            header: `Pay-in commission ${noun}`,
            align: 'right',
            cell: (row) => formatPaise(row.payin.commission),
        },
        {
            key: 'payout',
            header: 'Payouts',
            align: 'right',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap">
                    <div>{formatPaise(row.payout.amount)}</div>
                    <div className="text-tx3">
                        {row.payout.count} transactions
                    </div>
                </div>
            ),
        },
        {
            key: 'payout_commission',
            header: `Payout commission ${noun}`,
            align: 'right',
            cell: (row) => formatPaise(row.payout.commission),
        },
        {
            key: 'total',
            header: 'Total',
            align: 'right',
            cell: (row) => (
                <b>
                    {formatPaise(row.payin.commission + row.payout.commission)}
                </b>
            ),
        },
        {
            key: 'margin',
            header: 'Platform margin',
            align: 'right',
            cell: (row) => (
                <span className={row.margin < 0 ? 'text-er' : 'text-ok'}>
                    {formatPaise(row.margin)}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title="Commissions" />
            <PageHeader
                title="Commissions"
                description="Commission on the transactions that succeeded in the period, at the rates that applied to each transaction."
                actions={
                    <Segmented
                        options={[
                            { value: 'partner', label: 'By partner' },
                            { value: 'branch', label: 'By branch' },
                        ]}
                        value={by}
                        onChange={(value) => visit({ by: value })}
                    />
                }
            />

            <KpiGrid>
                <StatTile
                    label="Volume"
                    value={formatPaise(totals.volume, 0)}
                    icon="≡"
                    tone="nt"
                />
                <StatTile
                    label="Partners paid"
                    value={formatPaise(totals.partner_commission)}
                    icon="↓"
                    tone="in"
                />
                <StatTile
                    label="Branches earned"
                    value={formatPaise(totals.branch_commission)}
                    icon="↑"
                    tone="wn"
                />
                <StatTile
                    label="Platform margin"
                    value={formatPaise(totals.margin)}
                    icon="✓"
                    tone="ok"
                />
            </KpiGrid>

            <Panel className="overflow-hidden">
                <div className="flex items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] items-center gap-1.5 rounded-[7px] border border-ln px-2.5 text-[12.5px]">
                        <span className="text-tx3">Succeeded</span>
                        <input
                            type="date"
                            value={filters.from}
                            max={filters.to}
                            onChange={(event) =>
                                event.target.value &&
                                visit({ from: event.target.value })
                            }
                            className="bg-transparent outline-none"
                        />
                        <span className="text-tx3">–</span>
                        <input
                            type="date"
                            value={filters.to}
                            min={filters.from}
                            onChange={(event) =>
                                event.target.value &&
                                visit({ to: event.target.value })
                            }
                            className="bg-transparent outline-none"
                        />
                    </label>
                </div>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    empty={
                        <EmptyState
                            title="No commission in this period"
                            description="Only successful pay-ins and payouts earn commission."
                        />
                    }
                />
            </Panel>
        </>
    );
}
