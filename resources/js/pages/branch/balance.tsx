import { Head, Link } from '@inertiajs/react';
import { Panel } from '@/components/pg/data-table';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { SimpleTable } from '@/components/pg/simple-table';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import branch from '@/routes/branch';

type Props = {
    positions: { partner: { code: string; name: string }; balance: number }[];
    total: number;
    latest: {
        reference: string;
        period_end: string;
        net_amount: number;
        direction: 'party_to_platform' | 'platform_to_party' | 'none';
        settled_amount: number;
        status: string;
    } | null;
};

const owed = (value: number) =>
    value < 0
        ? `You owe PayGate ${formatPaise(-value)}`
        : value > 0
          ? `PayGate owes you ${formatPaise(value)}`
          : 'Even';

/**
 * Branch Balance: where the branch stands with PayGate right now, per
 * partner it collects for.
 */
export default function BranchBalance({ positions, total, latest }: Props) {
    return (
        <>
            <Head title="Branch Balance" />
            <PageHeader
                title="Branch Balance"
                description="Deposits you received (less your commission) are owed to PayGate; payouts you paid (plus your commission) are owed to you. Settlements move this toward zero."
            />

            <KpiGrid>
                <StatTile
                    label="Position now"
                    value={owed(total)}
                    icon="≡"
                    tone={total < 0 ? 'wn' : 'ok'}
                />
                {latest && (
                    <StatTile
                        label={`Latest settlement ${latest.reference}`}
                        value={`${formatPaise(latest.net_amount - latest.settled_amount)} open`}
                        icon="◷"
                        tone="hd"
                    />
                )}
            </KpiGrid>

            <Panel className="flex flex-col gap-3 p-4">
                <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold">By partner</span>
                    <Link
                        href={branch.settlements.index().url}
                        className="text-[13px] font-medium text-ac"
                    >
                        Settlements ›
                    </Link>
                </div>
                <SimpleTable
                    empty="Nothing booked yet."
                    headers={['Partner', 'Position']}
                    rows={positions.map((row) => [
                        <span key="p">
                            {row.partner.name}{' '}
                            <span className="font-mono text-xs text-tx3">
                                {row.partner.code}
                            </span>
                        </span>,
                        <span
                            key="b"
                            className={row.balance < 0 ? 'text-er' : 'text-ok'}
                        >
                            {owed(row.balance)}
                        </span>,
                    ])}
                />
                {latest && (
                    <p className="flex items-center gap-2 text-xs text-tx3">
                        Latest settlement up to{' '}
                        {formatDateTime(latest.period_end)}:{' '}
                        <StatusBadge status={latest.status} />
                    </p>
                )}
            </Panel>
        </>
    );
}
