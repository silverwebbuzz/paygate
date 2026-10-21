import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { ChargebackBadge, ProofLinks } from '@/components/pg/chargeback';
import { ConfirmDialog } from '@/components/pg/confirm-dialog';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { ViewTabs } from '@/components/pg/filter-bar';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { StatusBadge } from '@/components/pg/status-badge';
import { METHOD_LABELS } from '@/components/pg/transaction-drawer';
import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import adminChargebacks from '@/routes/admin/chargebacks';
import admin from '@/routes/admin';

type Tab = 'requested' | 'approved' | 'rejected';

type Row = {
    id: string;
    reference: string;
    status: Tab;
    amount: number;
    utr: string;
    utr_matches: boolean;
    reason: string | null;
    proof: { id: string; name: string | null; mime: string } | null;
    requested_by: string;
    requested_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    partner: { code: string; name: string };
    branch: { code: string; name: string };
    transaction: {
        id: string;
        reference: string;
        order_id: string;
        status: string;
        amount: number;
        method: string | null;
        bank_utr: string | null;
        customer: {
            id: string;
            name: string | null;
            mobile: string | null;
        } | null;
        created_at: string | null;
        succeeded_at: string | null;
    };
    can_decide: boolean;
};

type Props = {
    tab: Tab;
    search: string;
    items: {
        data: Row[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    totals: Record<Tab, { count: number; amount: number }>;
};

/**
 * Chargebacks (decided 2026-10-21): branches raise them on approved
 * deposits with the bank's notice; Admin approves (booked today: partner
 * −amount, branch +amount) or rejects. The deposit stays Success.
 */
export default function Chargebacks({ tab, search, items, totals }: Props) {
    const [query, setQuery] = useState(search);
    const [deciding, setDeciding] = useState<{
        row: Row;
        approve: boolean;
    } | null>(null);

    const visit = (next: { tab?: Tab; search?: string }) =>
        router.get(
            adminChargebacks.index({
                query: Object.fromEntries(
                    Object.entries({ tab, search: query, ...next }).filter(
                        ([, v]) => v,
                    ),
                ),
            }).url,
            {},
            { preserveState: true, replace: true },
        );

    useEffect(() => {
        if (query === search) return;
        const timer = setTimeout(() => visit({ search: query }), 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: 'Chargeback',
            cell: (row) => (
                <div className="text-xs">
                    <div className="font-mono font-medium">{row.reference}</div>
                    <div className="mt-1">
                        <ChargebackBadge status={row.status} />
                    </div>
                </div>
            ),
        },
        {
            key: 'parties',
            header: 'Partner · Branch',
            cell: (row) => (
                <div className="text-xs">
                    <div>
                        <span className="text-tx3">Partner </span>
                        {row.partner.name}
                    </div>
                    <div>
                        <span className="text-tx3">Branch </span>
                        {row.branch.name}
                    </div>
                </div>
            ),
        },
        {
            key: 'customer',
            header: 'Customer',
            cell: (row) => (
                <div className="text-xs">
                    <div>
                        {row.transaction.customer?.name ??
                            row.transaction.customer?.id ??
                            '—'}
                    </div>
                    <div className="text-tx3">
                        {row.transaction.customer?.mobile}
                    </div>
                </div>
            ),
        },
        {
            key: 'deposit',
            header: 'Deposit',
            cell: (row) => (
                <div className="text-xs">
                    <Link
                        href={
                            admin.transactions.index({
                                query: { txn: row.transaction.id },
                            }).url
                        }
                        className="font-mono font-medium text-ac hover:underline"
                    >
                        {row.transaction.reference}
                    </Link>
                    <div className="text-tx3">
                        Order {row.transaction.order_id}
                    </div>
                    <div className="mt-0.5 flex items-center gap-1.5">
                        <b>{formatPaise(row.transaction.amount)}</b>
                        <StatusBadge status={row.transaction.status} />
                    </div>
                    <div className="text-tx3">
                        {row.transaction.method
                            ? METHOD_LABELS[row.transaction.method]
                            : '—'}{' '}
                        · approved{' '}
                        {formatDateTime(row.transaction.succeeded_at)}
                    </div>
                </div>
            ),
        },
        {
            key: 'utr',
            header: 'UTR',
            cell: (row) => (
                <div className="text-xs">
                    <div className="font-mono">{row.utr}</div>
                    {row.utr_matches ? (
                        <div className="text-ok">✓ matches the deposit</div>
                    ) : (
                        <div className="text-wn">
                            Deposit’s bank UTR:{' '}
                            <span className="font-mono">
                                {row.transaction.bank_utr ?? '—'}
                            </span>
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'amount',
            header: 'Chargeback',
            align: 'right',
            cell: (row) => (
                <div className="text-xs">
                    <div className="text-[13px] font-semibold">
                        {formatPaise(row.amount)}
                    </div>
                    {row.amount !== row.transaction.amount && (
                        <div className="text-wn">
                            of {formatPaise(row.transaction.amount)}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'proof',
            header: 'Bank’s notice',
            cell: (row) =>
                row.proof ? (
                    <ProofLinks fileId={row.proof.id} />
                ) : (
                    <span className="text-tx3">—</span>
                ),
        },
        {
            key: 'raised',
            header: 'Raised',
            cell: (row) => (
                <div className="max-w-[220px] text-xs">
                    <div className="whitespace-nowrap">
                        {formatDateTime(row.requested_at)}
                    </div>
                    <div className="text-tx3">{row.requested_by}</div>
                    {row.reason && <div className="mt-0.5">{row.reason}</div>}
                </div>
            ),
        },
        {
            key: 'decision',
            header: tab === 'requested' ? '' : 'Decided',
            cell: (row) =>
                row.status === 'requested' ? (
                    row.can_decide ? (
                        <div className="flex gap-1.5">
                            <PgButton
                                variant="danger"
                                className="h-7 px-2 text-xs"
                                onClick={() =>
                                    setDeciding({ row, approve: false })
                                }
                            >
                                Reject
                            </PgButton>
                            <PgButton
                                variant="primary"
                                className="h-7 px-2 text-xs"
                                onClick={() =>
                                    setDeciding({ row, approve: true })
                                }
                            >
                                Approve
                            </PgButton>
                        </div>
                    ) : (
                        <span className="text-xs text-tx3">
                            Another admin approves
                        </span>
                    )
                ) : (
                    <div className="max-w-[220px] text-xs">
                        <div className="whitespace-nowrap">
                            {formatDateTime(row.decided_at)}
                        </div>
                        <div className="text-tx3">{row.decided_by}</div>
                        {row.decision_note && (
                            <div className="mt-0.5">{row.decision_note}</div>
                        )}
                    </div>
                ),
        },
    ];

    return (
        <>
            <Head title="Chargebacks" />
            <PageHeader
                title="Chargebacks"
                description="A customer’s bank took back an approved deposit. Branches raise them with the bank’s notice; approving books it today: the partner is debited the amount and the branch owes that much less. The deposit stays Success, and fees already earned stay earned."
            />
            <KpiGrid>
                <StatTile
                    label="Awaiting approval"
                    value={`${formatPaise(totals.requested.amount, 0)} · ${totals.requested.count}`}
                    icon="◷"
                    tone="wn"
                />
                <StatTile
                    label="Approved"
                    value={`${formatPaise(totals.approved.amount, 0)} · ${totals.approved.count}`}
                    icon="↺"
                    tone="er"
                />
                <StatTile
                    label="Rejected"
                    value={`${formatPaise(totals.rejected.amount, 0)} · ${totals.rejected.count}`}
                    icon="✕"
                    tone="nt"
                />
            </KpiGrid>
            <Panel className="overflow-hidden">
                <ViewTabs
                    views={[
                        {
                            key: 'requested',
                            label: 'Awaiting approval',
                            count: totals.requested.count,
                        },
                        {
                            key: 'approved',
                            label: 'Approved',
                            count: totals.approved.count,
                        },
                        {
                            key: 'rejected',
                            label: 'Rejected',
                            count: totals.rejected.count,
                        },
                    ]}
                    active={tab}
                    onChange={(key) => visit({ tab: key as Tab })}
                />
                <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] w-[320px] max-w-full items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Chargeback id, deposit id, order id or UTR"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                </div>
                <div className="overflow-x-auto">
                    <DataTable
                        columns={columns}
                        rows={items.data}
                        rowKey={(row) => row.id}
                        empty={
                            <EmptyState
                                title={
                                    tab === 'requested'
                                        ? 'Nothing waiting for approval'
                                        : 'No chargebacks'
                                }
                                description="Branches raise chargebacks from Pay-in History."
                            />
                        }
                    />
                </div>
                {(items.prev_page_url || items.next_page_url) && (
                    <div className="flex justify-end gap-2 px-3 py-2.5 text-xs">
                        {items.prev_page_url && (
                            <Link
                                href={items.prev_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                ‹ Newer
                            </Link>
                        )}
                        {items.next_page_url && (
                            <Link
                                href={items.next_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                Older ›
                            </Link>
                        )}
                    </div>
                )}
            </Panel>

            {deciding && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setDeciding(null)}
                    title={`${deciding.approve ? 'Approve' : 'Reject'} ${deciding.row.reference}?`}
                    description={
                        deciding.approve
                            ? `Booked today: ${deciding.row.partner.name} is debited ${formatPaise(deciding.row.amount)} and ${deciding.row.branch.name} owes ${formatPaise(deciding.row.amount)} less. It shows in today’s settlements. The partner is notified. This can’t be undone.`
                            : 'Nothing is booked. The branch sees your note and can raise it again.'
                    }
                    confirmLabel={deciding.approve ? 'Approve' : 'Reject'}
                    tone={deciding.approve ? 'primary' : 'danger'}
                    input={{
                        label: deciding.approve
                            ? 'Note (optional)'
                            : 'Why (required)',
                        required: !deciding.approve,
                    }}
                    onConfirm={(note) =>
                        router.post(
                            (deciding.approve
                                ? adminChargebacks.approve
                                : adminChargebacks.reject)(deciding.row.id).url,
                            { note },
                            {
                                preserveScroll: true,
                                onFinish: () => setDeciding(null),
                            },
                        )
                    }
                />
            )}
        </>
    );
}
