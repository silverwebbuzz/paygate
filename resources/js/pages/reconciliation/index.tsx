import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { SelectInput } from '@/components/pg/field';
import { ViewTabs } from '@/components/pg/filter-bar';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { TransactionDrawer } from '@/components/pg/transaction-drawer';
import type { TxnDetail, TxnRow } from '@/components/pg/transaction-drawer';
import { formatDate, formatDateTime, formatRelative } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import adminCases from '@/routes/admin/cases';
import adminReconciliation from '@/routes/admin/reconciliation';
import branchCases from '@/routes/branch/cases';
import branchReconciliation from '@/routes/branch/reconciliation';
import type { UserType } from '@/types';

type Figure = { count: number; amount: number };

type Props = {
    portal: UserType;
    direction: 'payin' | 'payout';
    tab: 'missing' | 'matched';
    items: {
        data: TxnRow[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: {
        from: string;
        to: string;
        branch: string | null;
        search: string;
    };
    summary: Record<'all' | 'matched' | 'missing', Figure>;
    open_cases: number;
    branches: { id: string; code: string; name: string }[];
    selected: string | null;
    detail?: TxnDetail | null;
};

/**
 * UTR Reconciliation: approved deposits and paid payouts, and whether a bank
 * statement line confirms each one.
 */
export default function Reconciliation(props: Props) {
    const { portal, direction, tab, items, filters, summary, branches } = props;
    const routes =
        portal === 'admin' ? adminReconciliation : branchReconciliation;
    const cases = portal === 'admin' ? adminCases : branchCases;
    const [search, setSearch] = useState(filters.search);
    const [openId, setOpenId] = useState<string | null>(props.selected);
    const open = items.data.find((txn) => txn.id === openId) ?? null;

    const visit = (next: Record<string, string | null>) =>
        router.get(
            routes.index({
                query: Object.fromEntries(
                    Object.entries({
                        direction,
                        tab,
                        ...filters,
                        search,
                        ...next,
                    }).filter(([, value]) => value),
                ) as Record<string, string>,
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

    const openTxn = (id: string) => {
        setOpenId(id);
        router.reload({ only: ['detail', 'selected'], data: { txn: id } });
    };

    const columns: Column<TxnRow>[] = [
        {
            key: 'txn',
            header: 'Transaction',
            cell: (t) => (
                <div>
                    <div className="font-mono text-xs font-medium">
                        {t.reference}
                    </div>
                    <div className="text-xs text-tx3">
                        {t.partner?.name ?? t.order_id}
                    </div>
                </div>
            ),
        },
        ...(portal === 'admin'
            ? [
                  {
                      key: 'branch',
                      header: 'Branch',
                      cell: (t: TxnRow) =>
                          t.branch && (
                              <span className="rounded-md bg-sf2 px-2 py-0.5 text-xs">
                                  {t.branch.code}
                              </span>
                          ),
                  },
              ]
            : []),
        {
            key: 'account',
            header: direction === 'payin' ? 'Paid into' : 'Paid to',
            cell: (t) => (
                <span className="text-xs">
                    {t.account
                        ? `${t.account.label}${t.account.number ? ` · ${t.account.number}` : ''}`
                        : (t.beneficiary?.masked ?? '—')}
                </span>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            cell: (t) => <b>{formatPaise(t.amount)}</b>,
        },
        {
            key: 'utr',
            header: 'Bank UTR',
            cell: (t) => (
                <span className="font-mono text-xs">{t.bank_utr ?? '—'}</span>
            ),
        },
        {
            key: 'decided',
            header: direction === 'payin' ? 'Approved' : 'Paid',
            cell: (t) => (
                <div className="text-xs whitespace-nowrap">
                    <div>{formatDateTime(t.decided_at)}</div>
                    {tab === 'missing' && (
                        <div className="text-tx3">
                            {formatRelative(t.decided_at)}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'line',
            header: 'Bank statement',
            cell: (t) =>
                t.bank_line ? (
                    <div className="text-xs">
                        <span className="font-medium text-ok">✓ Matched</span>{' '}
                        <span className="text-tx3">
                            {formatDate(t.bank_line.value_date)}
                        </span>
                    </div>
                ) : (
                    <span className="text-xs text-hd">
                        Not in a statement yet
                    </span>
                ),
        },
    ];

    return (
        <>
            <Head title="UTR Reconciliation" />
            <PageHeader
                title="UTR Reconciliation"
                description={
                    direction === 'payin'
                        ? 'Approved deposits and the bank statement line that confirms each one. A deposit with no line either isn’t in an entered statement yet, or its approval needs a second look.'
                        : 'Paid payouts and the debit on the branch’s bank statement that confirms each one.'
                }
                actions={
                    <>
                        <Segmented
                            options={[
                                { value: 'payin', label: 'Deposits' },
                                { value: 'payout', label: 'Payouts' },
                            ]}
                            value={direction}
                            onChange={(value) => visit({ direction: value })}
                        />
                        <Link
                            href={cases.index().url}
                            className="inline-flex h-8 items-center rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                        >
                            {portal === 'admin'
                                ? 'Unsettled UTR'
                                : 'Deposit Unsettled'}{' '}
                            · {props.open_cases}
                        </Link>
                    </>
                }
            />

            <KpiGrid>
                <StatTile
                    label={direction === 'payin' ? 'Approved' : 'Paid'}
                    value={`${formatPaise(summary.all.amount, 0)} · ${summary.all.count}`}
                    icon="✓"
                    tone="nt"
                />
                <StatTile
                    label="Confirmed by the bank"
                    value={`${formatPaise(summary.matched.amount, 0)} · ${summary.matched.count}`}
                    icon="≡"
                    tone="ok"
                />
                <StatTile
                    label="Not in a statement yet"
                    value={`${formatPaise(summary.missing.amount, 0)} · ${summary.missing.count}`}
                    icon="?"
                    tone="hd"
                />
                <StatTile
                    label="Open unsettled cases"
                    value={String(props.open_cases)}
                    icon="!"
                    tone="wn"
                />
            </KpiGrid>

            <Panel className="overflow-hidden">
                <ViewTabs
                    views={[
                        {
                            key: 'missing',
                            label: 'Not in a statement',
                            count: summary.missing.count,
                        },
                        {
                            key: 'matched',
                            label: 'Confirmed by the bank',
                            count: summary.matched.count,
                        },
                    ]}
                    active={tab}
                    onChange={(key) => visit({ tab: key })}
                />
                <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] items-center gap-1.5 rounded-[7px] border border-ln px-2.5 text-[12.5px]">
                        <span className="text-tx3">
                            {direction === 'payin' ? 'Approved' : 'Paid'}
                        </span>
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
                    {portal === 'admin' && (
                        <SelectInput
                            className="h-[30px] w-[190px] text-[12.5px]"
                            value={filters.branch ?? ''}
                            onChange={(event) =>
                                visit({ branch: event.target.value || null })
                            }
                        >
                            <option value="">All branches</option>
                            {branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>
                                    {branch.code} · {branch.name}
                                </option>
                            ))}
                        </SelectInput>
                    )}
                    <label className="flex h-[30px] w-[280px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Transaction id, order id or UTR"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">
                        {items.total}{' '}
                        {tab === 'missing'
                            ? '· oldest first'
                            : '· newest first'}
                    </span>
                </div>
                <DataTable
                    columns={columns}
                    rows={items.data}
                    rowKey={(t) => t.id}
                    onRowClick={(t) => openTxn(t.id)}
                    empty={
                        <EmptyState
                            title={
                                tab === 'missing'
                                    ? 'Everything is confirmed'
                                    : 'Nothing confirmed yet'
                            }
                            description={
                                tab === 'missing'
                                    ? 'Every approval in this period has its bank statement line.'
                                    : 'Enter or import the bank statements of this period.'
                            }
                        />
                    }
                />
                {(items.prev_page_url || items.next_page_url) && (
                    <div className="flex justify-end gap-2 px-3 py-2.5 text-xs">
                        {items.prev_page_url && (
                            <Link
                                href={items.prev_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                ‹ Previous
                            </Link>
                        )}
                        {items.next_page_url && (
                            <Link
                                href={items.next_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                Next ›
                            </Link>
                        )}
                    </div>
                )}
            </Panel>

            {open && (
                <TransactionDrawer
                    txn={open}
                    detail={
                        props.detail && props.detail.id === open.id
                            ? props.detail
                            : null
                    }
                    portal={portal}
                    onClose={() => setOpenId(null)}
                />
            )}
        </>
    );
}
