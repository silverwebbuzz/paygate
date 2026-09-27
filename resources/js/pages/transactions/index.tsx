import { Head, Link, router } from '@inertiajs/react';
import { RefreshCw, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { ViewTabs } from '@/components/pg/filter-bar';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { StatusBadge } from '@/components/pg/status-badge';
import {
    METHOD_LABELS,
    TransactionDrawer,
} from '@/components/pg/transaction-drawer';
import type { TxnDetail, TxnRow } from '@/components/pg/transaction-drawer';
import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import admin from '@/routes/admin';
import branch from '@/routes/branch';
import partner from '@/routes/partner';
import type { UserType } from '@/types';

type Group = 'open' | 'pending' | 'hold' | 'success' | 'rejected' | 'closed';

type Props = {
    portal: UserType;
    direction: 'payin' | 'payout';
    transactions: {
        data: TxnRow[];
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { status: Group | null; search: string };
    summary: Partial<Record<Group, { count: number; amount: number }>>;
    selected: string | null;
    detail?: TxnDetail | null;
};

const LIST_ROUTE = {
    payin: {
        admin: admin.transactions.index,
        partner: partner.payins.index,
        branch: branch.payins.index,
    },
    payout: {
        admin: admin.transactions.payouts,
        partner: partner.payouts.index,
        branch: branch.payouts.history,
    },
};

const TITLES: Record<'payin' | 'payout', Record<UserType, [string, string]>> = {
    payin: {
        admin: [
            'Transactions',
            'Every pay-in across partners and branches. Search by our id, order id, UTR or customer.',
        ],
        partner: [
            'Pay-in',
            'Your customers’ deposits. Credit a customer only when the status is Success.',
        ],
        branch: [
            'Pay-in history',
            'Deposits paid into your accounts, newest first.',
        ],
    },
    payout: {
        admin: ['Transactions', 'Every payout across partners and branches.'],
        partner: [
            'Pay-out',
            'Withdrawals to your customers. The amount plus the fee is charged to your balance.',
        ],
        branch: [
            'Pay-out history',
            'Withdrawals your branch paid or couldn’t pay.',
        ],
    },
};

const TABS: Record<'payin' | 'payout', [Group | 'all', string][]> = {
    payin: [
        ['all', 'All'],
        ['pending', 'Pending'],
        ['hold', 'On hold'],
        ['success', 'Success'],
        ['rejected', 'Declined'],
        ['open', 'Not paid yet'],
        ['closed', 'Expired / cancelled'],
    ],
    payout: [
        ['all', 'All'],
        ['pending', 'Waiting for branch'],
        ['hold', 'Being paid'],
        ['success', 'Paid'],
        ['rejected', 'Failed'],
        ['closed', 'Cancelled'],
    ],
};

const KPIS: Record<
    'payin' | 'payout',
    [Group, string, string, 'ok' | 'wn' | 'hd' | 'er'][]
> = {
    payin: [
        ['success', 'Success', '✓', 'ok'],
        ['pending', 'Pending', '◷', 'wn'],
        ['hold', 'On hold', '‖', 'hd'],
        ['rejected', 'Declined', '✕', 'er'],
    ],
    payout: [
        ['success', 'Paid', '✓', 'ok'],
        ['pending', 'Waiting for branch', '◷', 'wn'],
        ['hold', 'Being paid', '‖', 'hd'],
        ['rejected', 'Failed', '✕', 'er'],
    ],
};

export default function Transactions({
    portal,
    direction,
    transactions,
    filters,
    summary,
    selected,
    detail,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [openId, setOpenId] = useState<string | null>(selected);
    const open = transactions.data.find((txn) => txn.id === openId) ?? null;
    const [title, description] = TITLES[direction][portal];

    const visit = (next: Partial<Props['filters']>) =>
        router.get(
            LIST_ROUTE[direction][portal]({
                query: Object.fromEntries(
                    Object.entries({ ...filters, search, ...next }).filter(
                        ([, v]) => v,
                    ),
                ),
            }).url,
            {},
            { preserveState: true, replace: true },
        );

    useEffect(() => {
        if (search === filters.search) return;
        const timer = setTimeout(() => visit({ search }), 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    useEffect(() => {
        if (selected && !detail) router.reload({ only: ['detail'] });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const openTxn = (id: string) => {
        setOpenId(id);
        router.reload({ only: ['detail', 'selected'], data: { txn: id } });
    };

    const total = (
        Object.values(summary) as { count: number; amount: number }[]
    ).reduce((sum, group) => sum + group.count, 0);

    const figure = (group: Group) => summary[group] ?? { count: 0, amount: 0 };

    const columns: Column<TxnRow>[] = [
        {
            key: 'txn',
            header: 'Transaction',
            cell: (t) => (
                <div>
                    <div className="font-mono text-xs font-medium">
                        {t.reference}
                    </div>
                    <div className="text-xs text-tx3">{t.order_id}</div>
                </div>
            ),
        },
        ...(portal !== 'partner'
            ? [
                  {
                      key: 'partner',
                      header: 'Partner',
                      cell: (t: TxnRow) =>
                          t.partner && (
                              <span>
                                  {t.partner.name}{' '}
                                  <span className="font-mono text-xs text-tx3">
                                      {t.partner.code}
                                  </span>
                              </span>
                          ),
                  },
              ]
            : []),
        {
            key: 'customer',
            header: 'Customer',
            cell: (t) => (
                <div>
                    <div>{t.customer?.name ?? t.customer?.id ?? '—'}</div>
                    <div className="text-xs text-tx3">
                        {t.customer?.mobile ?? t.customer?.id}
                    </div>
                </div>
            ),
        },
        {
            key: 'amount',
            header: 'Amount · Net',
            align: 'right',
            cell: (t) => (
                <div>
                    <div className="font-medium">{formatPaise(t.amount)}</div>
                    <div className="text-xs text-tx3">
                        {t.net === null
                            ? '—'
                            : `${direction === 'payout' ? 'Charged' : 'Net'} ${formatPaise(t.net)}`}
                    </div>
                </div>
            ),
        },
        {
            key: 'method',
            header: 'Method',
            cell: (t) => (
                <div>
                    <div>{t.method ? METHOD_LABELS[t.method] : '—'}</div>
                    {t.account && (
                        <div className="text-xs text-tx3">
                            {t.account.bank ?? t.account.upi}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'utr',
            header: 'UTR',
            cell: (t) => (
                <span className="font-mono text-xs">
                    {t.bank_utr ?? t.customer_utr ?? '—'}
                </span>
            ),
        },
        ...(portal === 'admin'
            ? [
                  {
                      key: 'branch',
                      header: 'Branch',
                      cell: (t: TxnRow) =>
                          t.branch ? (
                              <span className="rounded-md bg-sf2 px-2 py-0.5 text-xs">
                                  {t.branch.code}
                              </span>
                          ) : (
                              '—'
                          ),
                  },
              ]
            : []),
        {
            key: 'status',
            header: 'Status',
            cell: (t) => <StatusBadge status={t.status} />,
        },
        {
            key: 'created',
            header: 'Created ↓',
            cell: (t) => (
                <span className="text-xs whitespace-nowrap">
                    {formatDateTime(t.created_at)}
                </span>
            ),
        },
        {
            key: 'decided',
            header: 'Decided',
            cell: (t) => (
                <span className="text-xs whitespace-nowrap text-tx3">
                    {formatDateTime(t.decided_at)}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description={description}
                actions={
                    <>
                        {portal === 'admin' && (
                            <Segmented
                                options={[
                                    { value: 'payin', label: 'Pay-ins' },
                                    { value: 'payout', label: 'Payouts' },
                                ]}
                                value={direction}
                                onChange={(value) =>
                                    router.get(LIST_ROUTE[value].admin().url)
                                }
                            />
                        )}
                        <PgButton onClick={() => router.reload()}>
                            <RefreshCw className="size-3.5" /> Refresh
                        </PgButton>
                    </>
                }
            />

            <KpiGrid>
                {KPIS[direction].map(([group, label, icon, tone]) => (
                    <StatTile
                        key={group}
                        label={label}
                        value={`${formatPaise(figure(group).amount, 0)} · ${figure(group).count}`}
                        icon={icon}
                        tone={tone}
                    />
                ))}
            </KpiGrid>

            <Panel className="overflow-hidden">
                <ViewTabs
                    views={TABS[direction].map(([key, label]) => ({
                        key,
                        label,
                        count: key === 'all' ? total : figure(key).count,
                    }))}
                    active={filters.status ?? 'all'}
                    onChange={(key) =>
                        visit({ status: key === 'all' ? null : (key as Group) })
                    }
                />
                <div className="flex items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] w-[320px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Transaction id, order id, UTR, customer id or mobile"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">
                        {transactions.total === 0
                            ? 'No results'
                            : `Showing ${transactions.from}–${transactions.to} of ${transactions.total}`}{' '}
                        · newest first
                    </span>
                </div>
                <DataTable
                    columns={columns}
                    rows={transactions.data}
                    rowKey={(t) => t.id}
                    onRowClick={(t) => openTxn(t.id)}
                    empty={
                        <EmptyState
                            title="No pay-ins found"
                            description={
                                filters.search || filters.status
                                    ? 'Try another search or tab.'
                                    : 'Pay-ins appear here as soon as they are created.'
                            }
                        />
                    }
                />
                {(transactions.prev_page_url || transactions.next_page_url) && (
                    <div className="flex justify-end gap-2 px-3 py-2.5 text-xs">
                        {transactions.prev_page_url && (
                            <Link
                                href={transactions.prev_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                ‹ Newer
                            </Link>
                        )}
                        {transactions.next_page_url && (
                            <Link
                                href={transactions.next_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                Older ›
                            </Link>
                        )}
                    </div>
                )}
            </Panel>

            {open && (
                <TransactionDrawer
                    txn={open}
                    detail={detail && detail.id === open.id ? detail : null}
                    portal={portal}
                    onClose={() => setOpenId(null)}
                />
            )}
        </>
    );
}
