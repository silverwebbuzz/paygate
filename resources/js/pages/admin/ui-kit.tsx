import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/pg/confirm-dialog';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { Drawer, KeyValues } from '@/components/pg/drawer';
import { EmptyState } from '@/components/pg/empty-state';
import { FilterBar, ViewTabs } from '@/components/pg/filter-bar';
import { KpiCard, KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { LiveIndicator, PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { StatusBadge } from '@/components/pg/status-badge';
import { WizardSteps } from '@/components/pg/wizard-steps';
import { formatPaise } from '@/lib/money';

/**
 * Local-only reference of the PayGate design system (Phase 2).
 * Every value on this page is SAMPLE DATA.
 */
type Row = {
    id: string;
    partner: string;
    order: string;
    customer: string;
    amount: number;
    fee: number;
    method: string;
    utr: string;
    branch: string;
    status: string;
};

const ROWS: Row[] = [
    {
        id: 'PI-260927-8F3K2Q',
        partner: 'Demo Partner',
        order: 'ORD-1001',
        customer: 'Aarav Kaur',
        amount: 200000,
        fee: 12000,
        method: 'Manual · Bank',
        utr: '',
        branch: 'Demo Branch',
        status: 'payment_submitted',
    },
    {
        id: 'PI-260927-3JD8XA',
        partner: 'Demo Partner',
        order: 'ORD-1002',
        customer: 'Vala Kanaiya',
        amount: 500000,
        fee: 30000,
        method: 'UPI · Intent',
        utr: '626839834644',
        branch: 'Demo Branch',
        status: 'success',
    },
    {
        id: 'PI-260927-Q7LM20',
        partner: 'Demo Partner',
        order: 'ORD-1003',
        customer: 'Mansi Sehrawat',
        amount: 1253400,
        fee: 75204,
        method: 'Manual · Bank',
        utr: '',
        branch: 'Demo Branch',
        status: 'under_review',
    },
    {
        id: 'PI-260927-ZX91KE',
        partner: 'Demo Partner',
        order: 'ORD-1004',
        customer: 'Kushal Patel',
        amount: 95000,
        fee: 5700,
        method: 'UPI · QR',
        utr: '',
        branch: 'Demo Branch',
        status: 'rejected',
    },
];

const RANGES = [
    '1h',
    '24h',
    '7d',
    '30d',
    'Today',
    'Yesterday',
    'This month',
    'Custom',
].map((label) => ({ value: label, label }));

export default function UiKit() {
    const [range, setRange] = useState('Today');
    const [view, setView] = useState('all');
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<Row | null>(null);
    const [tab, setTab] = useState('overview');
    const [modal, setModal] = useState<'approve' | 'hold' | 'decline' | null>(
        null,
    );

    const columns: Column<Row>[] = [
        {
            key: 'id',
            header: 'Transaction',
            cell: (row) => <span className="font-mono text-xs">{row.id}</span>,
        },
        {
            key: 'partner',
            header: 'Partner · Order ID',
            cell: (row) => (
                <>
                    <div>{row.partner}</div>
                    <div className="font-mono text-[11.5px] text-tx3">
                        {row.order}
                    </div>
                </>
            ),
        },
        { key: 'customer', header: 'Customer', cell: (row) => row.customer },
        {
            key: 'amount',
            header: 'Amount · Net',
            align: 'right',
            cell: (row) => (
                <>
                    <div className="font-medium">{formatPaise(row.amount)}</div>
                    <div className="text-[11.5px] text-tx3">
                        {formatPaise(row.amount - row.fee)}
                    </div>
                </>
            ),
        },
        { key: 'method', header: 'Method', cell: (row) => row.method },
        {
            key: 'utr',
            header: 'UTR',
            cell: (row) => (
                <span className="font-mono text-xs">{row.utr || '—'}</span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => <StatusBadge status={row.status} />,
        },
    ];

    const rows = ROWS.filter(
        (row) =>
            (view === 'all' || row.status === view) &&
            (search === '' ||
                row.id.toLowerCase().includes(search.toLowerCase())),
    );

    return (
        <>
            <Head title="UI kit" />
            <PageHeader
                title="UI kit"
                description="Design-system reference for developers (local only). All values are sample data."
                eyebrow={<LiveIndicator>Sample data · not live</LiveIndicator>}
                actions={
                    <Segmented
                        options={RANGES}
                        value={range}
                        onChange={setRange}
                    />
                }
            />

            <KpiGrid>
                <KpiCard
                    label="Total volume"
                    value="₹4.82 Cr"
                    sub="▲ 12.4% vs yesterday"
                    trend="up"
                />
                <KpiCard
                    label="Successful"
                    value="16,902"
                    sub="91.7% success rate"
                    trend="up"
                />
                <KpiCard
                    label="Pending"
                    value="1,118"
                    sub="214 older than 30 min"
                    trend="warn"
                />
                <KpiCard
                    label="Failed"
                    value="406"
                    sub="▼ 0.4 pts"
                    trend="down"
                />
                <KpiCard
                    label="Commission"
                    value="₹11.84 L"
                    sub="Deposit + withdrawal"
                />
            </KpiGrid>

            <div className="grid grid-cols-[repeat(auto-fill,minmax(180px,1fr))] gap-2.5">
                <StatTile label="Approved" value="16,902" icon="✓" tone="ok" />
                <StatTile label="Pending" value="1,118" icon="◷" tone="wn" />
                <StatTile label="Payment hold" value="96" icon="‖" tone="hd" />
                <StatTile label="Declined" value="406" icon="✕" tone="er" />
            </div>

            <Panel>
                <ViewTabs
                    active={view}
                    onChange={setView}
                    views={[
                        { key: 'all', label: 'All', count: ROWS.length },
                        {
                            key: 'payment_submitted',
                            label: 'Pending',
                            count: 1,
                        },
                        {
                            key: 'under_review',
                            label: 'Payment hold',
                            count: 1,
                        },
                        { key: 'success', label: 'Success', count: 1 },
                    ]}
                />
                <FilterBar
                    search={search}
                    onSearch={setSearch}
                    placeholder="Txn ID, UTR, order ID…"
                    chips={[
                        {
                            key: 'partner',
                            label: 'Partner',
                            value: 'Demo Partner',
                        },
                    ]}
                    onAddFilter={() => undefined}
                    trailing={`Showing ${rows.length} of ${ROWS.length}`}
                />
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    onRowClick={(row) => {
                        setSelected(row);
                        setTab('overview');
                    }}
                />
            </Panel>

            <div className="grid gap-4 md:grid-cols-2">
                <Panel className="p-4">
                    <div className="mb-3 text-sm font-semibold">
                        Status badges
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {[
                            'created',
                            'payment_submitted',
                            'under_review',
                            'success',
                            'rejected',
                            'expired',
                            'processing',
                            'failed',
                            'verified',
                            'active',
                            'exhausted',
                            'suspended',
                            'matched',
                            'mismatch',
                            'unsettled',
                            'partially_settled',
                            'settled',
                            'delivered',
                            'retrying',
                        ].map((status) => (
                            <StatusBadge key={status} status={status} />
                        ))}
                    </div>
                </Panel>
                <Panel className="p-4">
                    <div className="mb-3 text-sm font-semibold">
                        Wizard steps
                    </div>
                    <WizardSteps
                        current={2}
                        steps={[
                            {
                                title: 'Basic information',
                                description: 'Who the partner is',
                            },
                            {
                                title: 'API & security',
                                description: 'Endpoints and allowed IPs',
                            },
                            {
                                title: 'Payment configuration',
                                description: 'Methods on the checkout page',
                            },
                            { title: 'Commission' },
                        ]}
                    />
                </Panel>
            </div>

            <Panel>
                <EmptyState
                    title="Empty state"
                    description="Shown when a list has no rows yet."
                />
            </Panel>

            <Drawer
                open={selected !== null}
                onOpenChange={(open) => !open && setSelected(null)}
                kind="Pay-in transaction"
                title={selected?.id ?? ''}
                status={selected && <StatusBadge status={selected.status} />}
                subtitle={
                    selected && `${selected.partner} · ${selected.customer}`
                }
                actions={
                    selected?.status === 'payment_submitted' && (
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={() => setModal('hold')}
                                className="h-8 rounded-[7px] border border-ln px-3 text-[12.5px] font-medium"
                            >
                                Hold
                            </button>
                            <button
                                type="button"
                                onClick={() => setModal('decline')}
                                className="h-8 rounded-[7px] border border-ln px-3 text-[12.5px] font-medium text-er"
                            >
                                Decline
                            </button>
                            <button
                                type="button"
                                onClick={() => setModal('approve')}
                                className="h-8 rounded-[7px] bg-brand px-3 text-[12.5px] font-medium text-white"
                            >
                                Approve
                            </button>
                        </div>
                    )
                }
                summaries={
                    selected
                        ? [
                              {
                                  label: 'Gross amount',
                                  value: formatPaise(selected.amount),
                              },
                              {
                                  label: 'Partner commission',
                                  value: formatPaise(selected.fee),
                              },
                              {
                                  label: 'Net to partner',
                                  value: formatPaise(
                                      selected.amount - selected.fee,
                                  ),
                                  tone: 'text-ok',
                              },
                          ]
                        : []
                }
                tabs={[
                    { key: 'overview', label: 'Overview' },
                    { key: 'timeline', label: 'Timeline' },
                    { key: 'proof', label: 'Proof' },
                    { key: 'ledger', label: 'Ledger' },
                    { key: 'webhooks', label: 'Webhooks' },
                    { key: 'audit', label: 'Audit' },
                ]}
                activeTab={tab}
                onTabChange={setTab}
            >
                {selected && tab === 'overview' ? (
                    <KeyValues
                        items={[
                            {
                                label: 'Order ID',
                                value: selected.order,
                                mono: true,
                            },
                            { label: 'Customer', value: selected.customer },
                            { label: 'Method', value: selected.method },
                            { label: 'Branch', value: selected.branch },
                            {
                                label: 'Bank UTR',
                                value: selected.utr || '—',
                                mono: true,
                            },
                        ]}
                    />
                ) : (
                    <EmptyState
                        title={`${tab} tab`}
                        description="Filled with real data in Phase 7."
                    />
                )}
            </Drawer>

            <ConfirmDialog
                open={modal === 'approve'}
                onOpenChange={(open) => !open && setModal(null)}
                title="Approve deposit"
                description="Enter the UTR exactly as it appears in your bank statement."
                confirmLabel="Approve"
                input={{
                    label: 'Bank UTR',
                    placeholder: '12-digit UTR',
                    required: true,
                }}
                onConfirm={() => setModal(null)}
            />
            <ConfirmDialog
                open={modal === 'hold'}
                onOpenChange={(open) => !open && setModal(null)}
                title="Move to payment hold"
                description="The deposit stays open for review."
                confirmLabel="Hold"
                tone="warning"
                onConfirm={() => setModal(null)}
            />
            <ConfirmDialog
                open={modal === 'decline'}
                onOpenChange={(open) => !open && setModal(null)}
                title="Decline deposit"
                description="The partner is notified by webhook."
                confirmLabel="Decline"
                tone="danger"
                input={{
                    label: 'Reason',
                    placeholder: 'e.g. UTR not found',
                    required: true,
                }}
                onConfirm={() => setModal(null)}
            />
        </>
    );
}
