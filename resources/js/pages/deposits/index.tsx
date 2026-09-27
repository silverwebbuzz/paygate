import { Head, router, useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { PgButton } from '@/components/pg/button';
import { ConfirmDialog } from '@/components/pg/confirm-dialog';
import { DataTable, Panel } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { FormDialog } from '@/components/pg/form-dialog';
import { LiveIndicator, PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { StatusBadge } from '@/components/pg/status-badge';
import {
    METHOD_LABELS,
    TransactionDrawer,
} from '@/components/pg/transaction-drawer';
import type { TxnDetail, TxnRow } from '@/components/pg/transaction-drawer';
import { formatDateTime, formatRelative } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { TONE_CLASSES } from '@/lib/status';
import { cn } from '@/lib/utils';
import adminDeposits from '@/routes/admin/deposits';
import branchDeposits from '@/routes/branch/deposits';
import type { UserType } from '@/types';

type Tab = 'pending' | 'hold' | 'awaiting' | 'approved' | 'declined';

type Props = {
    portal: UserType;
    tab: Tab;
    tabs: Record<Tab, { count: number; amount: number }>;
    items: {
        data: TxnRow[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { partner: string | null; search: string };
    partners: { id: string; code: string; name: string }[];
    reasons: Record<string, string>;
    selected: string | null;
    detail?: TxnDetail | null;
};

const TAB_INFO: Record<
    Tab,
    { label: string; icon: string; tone: keyof typeof TONE_CLASSES }
> = {
    pending: { label: 'Pending', icon: '◷', tone: 'wn' },
    hold: { label: 'Payment hold', icon: '‖', tone: 'hd' },
    awaiting: { label: 'Not paid yet', icon: '○', tone: 'nt' },
    approved: { label: 'Approved today', icon: '✓', tone: 'ok' },
    declined: { label: 'Declined today', icon: '✕', tone: 'er' },
};

type Dialog = null | { kind: 'approve' | 'hold' | 'decline'; txn: TxnRow };

export default function Deposits(props: Props) {
    const { portal, tab, tabs, items, filters, partners } = props;
    const routes = portal === 'admin' ? adminDeposits : branchDeposits;
    const [mode, setMode] = useState<'cards' | 'list'>(() =>
        typeof window !== 'undefined' &&
        window.localStorage?.getItem('pg-deposit-view') === 'list'
            ? 'list'
            : 'cards',
    );
    const [search, setSearch] = useState(filters.search);
    const [openId, setOpenId] = useState<string | null>(props.selected);
    const [dialog, setDialog] = useState<Dialog>(null);
    const open = items.data.find((txn) => txn.id === openId) ?? null;

    const visit = (next: Record<string, string | null>) =>
        router.get(
            routes.index({
                query: Object.fromEntries(
                    Object.entries({ tab, ...filters, search, ...next }).filter(
                        ([, v]) => v,
                    ),
                ),
            }).url,
            {},
            { preserveState: true, replace: true },
        );

    useEffect(() => {
        try {
            window.localStorage.setItem('pg-deposit-view', mode);
        } catch {
            // storage unavailable: the choice just isn't remembered
        }
    }, [mode]);

    useEffect(() => {
        if (search === filters.search) return;
        const timer = setTimeout(() => visit({ search }), 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // A live queue: new customer submissions appear without reloading.
    useEffect(() => {
        if (tab !== 'pending' && tab !== 'hold') return;
        const timer = setInterval(
            () => router.reload({ only: ['items', 'tabs'] }),
            20000,
        );

        return () => clearInterval(timer);
    }, [tab]);

    const openTxn = (id: string) => {
        setOpenId(id);
        router.reload({ only: ['detail', 'selected'], data: { txn: id } });
    };

    const decisionButtons = (txn: TxnRow, compact = false) =>
        txn.can.decide &&
        ['payment_submitted', 'payment_detected', 'under_review'].includes(
            txn.status,
        ) && (
            <div
                className="flex gap-1.5"
                onClick={(event) => event.stopPropagation()}
            >
                {!compact && (
                    <PgButton
                        variant="danger"
                        className="h-7 px-2 text-xs"
                        title="Decline"
                        onClick={() => setDialog({ kind: 'decline', txn })}
                    >
                        ✕
                    </PgButton>
                )}
                {txn.status !== 'under_review' && (
                    <PgButton
                        className="h-7 text-xs text-hd"
                        onClick={() => setDialog({ kind: 'hold', txn })}
                    >
                        Hold
                    </PgButton>
                )}
                <PgButton
                    variant="primary"
                    className="h-7 text-xs"
                    onClick={() => setDialog({ kind: 'approve', txn })}
                >
                    Approve
                </PgButton>
            </div>
        );

    return (
        <>
            <Head title="Manual Deposit" />
            <PageHeader
                title="Manual Deposit"
                description="Check each payment in your bank, then approve it with the bank UTR, hold it, or decline it. Approving credits the partner at once."
                eyebrow={
                    (tab === 'pending' || tab === 'hold') && (
                        <LiveIndicator>
                            Live · refreshes every 20 s
                        </LiveIndicator>
                    )
                }
                actions={
                    <Segmented
                        options={[
                            { value: 'cards', label: 'Cards' },
                            { value: 'list', label: 'Dense list' },
                        ]}
                        value={mode}
                        onChange={setMode}
                    />
                }
            />

            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5">
                {(Object.keys(TAB_INFO) as Tab[]).map((key) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => visit({ tab: key })}
                        className={cn(
                            'flex flex-col gap-1 rounded-[10px] border bg-sf px-3.5 py-3 text-left',
                            key === tab
                                ? 'border-ac shadow-[0_0_0_3px_var(--pg-acs)]'
                                : 'border-ln hover:border-ac',
                        )}
                    >
                        <span className="flex items-center gap-1.5 text-xs text-tx2">
                            <span
                                className={cn(
                                    'grid size-5 place-items-center rounded text-[10px]',
                                    TONE_CLASSES[TAB_INFO[key].tone],
                                )}
                            >
                                {TAB_INFO[key].icon}
                            </span>
                            {TAB_INFO[key].label}
                        </span>
                        <span className="text-lg font-semibold">
                            {tabs[key].count}
                        </span>
                        <span className="text-xs text-tx3">
                            {formatPaise(tabs[key].amount, 0)}
                        </span>
                    </button>
                ))}
            </div>

            <Panel className="flex flex-wrap items-center gap-2 px-3 py-2.5">
                <label className="flex h-[30px] w-[300px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                    <Search className="size-3.5" />
                    <input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Transaction id, order id, UTR or customer"
                        className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                    />
                </label>
                <SelectInput
                    className="h-[30px] w-[220px] text-[12.5px]"
                    value={filters.partner ?? ''}
                    onChange={(event) =>
                        visit({ partner: event.target.value || null })
                    }
                >
                    <option value="">All partners</option>
                    {partners.map((partner) => (
                        <option key={partner.id} value={partner.id}>
                            {partner.code} · {partner.name}
                        </option>
                    ))}
                </SelectInput>
                <div className="flex-1" />
                <span className="text-xs text-tx3">
                    {items.total}{' '}
                    {tab === 'pending' || tab === 'hold'
                        ? 'waiting · oldest first'
                        : ''}
                </span>
            </Panel>

            {items.data.length === 0 ? (
                <Panel>
                    <EmptyState
                        title={
                            tab === 'pending'
                                ? 'Nothing to check right now'
                                : 'Nothing here'
                        }
                        description={
                            tab === 'pending'
                                ? 'New payments appear here as soon as customers submit them.'
                                : undefined
                        }
                    />
                </Panel>
            ) : mode === 'cards' ? (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {items.data.map((txn) => (
                        <div
                            key={txn.id}
                            onClick={() => openTxn(txn.id)}
                            className="flex cursor-pointer flex-col gap-3 rounded-[12px] border border-ln bg-sf p-4 hover:border-ac hover:shadow-[0_4px_16px_rgba(15,23,42,.06)]"
                        >
                            <div className="flex items-start gap-2.5">
                                <span className="grid size-9 flex-none place-items-center rounded-lg bg-acs text-xs font-semibold text-act">
                                    {(txn.partner?.code ?? '').slice(0, 2)}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="truncate font-medium">
                                        {txn.partner?.name}{' '}
                                        <span className="font-mono text-xs text-tx3">
                                            {txn.partner?.code}
                                        </span>
                                    </div>
                                    <div className="truncate text-xs text-tx3">
                                        {txn.branch?.code} ·{' '}
                                        {txn.customer?.name ?? txn.customer?.id}
                                    </div>
                                </div>
                                <StatusBadge status={txn.status} />
                            </div>
                            <div className="flex items-baseline justify-between">
                                <span className="text-[22px] font-semibold tracking-[-.02em]">
                                    {formatPaise(txn.amount)}
                                </span>
                                <span className="text-xs text-tx3">
                                    {txn.method
                                        ? METHOD_LABELS[txn.method]
                                        : ''}
                                </span>
                            </div>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                                <Row label="Transaction">
                                    <span className="font-mono">
                                        {txn.reference}
                                    </span>
                                </Row>
                                <Row label="Account">
                                    {txn.account
                                        ? txn.method === 'bank_transfer'
                                            ? `${txn.account.bank} · ${txn.account.number}`
                                            : (txn.account.upi ??
                                              txn.account.label)
                                        : '—'}
                                </Row>
                                <Row label="Customer UTR">
                                    <span className="font-mono">
                                        {txn.customer_utr ?? 'screenshot only'}
                                    </span>
                                </Row>
                                <Row label="Submitted">
                                    {formatDateTime(txn.submitted_at)}{' '}
                                    <span className="text-tx3">
                                        · {formatRelative(txn.submitted_at)}
                                    </span>
                                </Row>
                                {(tab === 'approved' || tab === 'declined') && (
                                    <Row label="Decision">
                                        {tab === 'approved'
                                            ? `Bank UTR ${txn.bank_utr}`
                                            : txn.reason_code?.replaceAll(
                                                  '_',
                                                  ' ',
                                              )}{' '}
                                        · {formatDateTime(txn.decided_at)}
                                    </Row>
                                )}
                            </dl>
                            <div className="flex items-center justify-between border-t border-ln2 pt-3">
                                <span className="text-xs font-medium text-ac">
                                    View proof & history ›
                                </span>
                                {decisionButtons(txn)}
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <Panel className="overflow-hidden">
                    <DataTable
                        columns={[
                            {
                                key: 'partner',
                                header: 'Partner · Branch',
                                cell: (t: TxnRow) => (
                                    <div>
                                        <div className="font-medium">
                                            {t.partner?.name}
                                        </div>
                                        <div className="text-xs text-tx3">
                                            {t.branch?.code}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'txn',
                                header: 'Transaction · Order',
                                cell: (t: TxnRow) => (
                                    <div>
                                        <div className="font-mono text-xs">
                                            {t.reference}
                                        </div>
                                        <div className="text-xs text-tx3">
                                            {t.order_id}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'customer',
                                header: 'Customer',
                                cell: (t: TxnRow) =>
                                    t.customer?.name ?? t.customer?.id,
                            },
                            {
                                key: 'utr',
                                header: 'Customer UTR',
                                cell: (t: TxnRow) => (
                                    <span className="font-mono text-xs">
                                        {t.customer_utr ?? 'screenshot'}
                                    </span>
                                ),
                            },
                            {
                                key: 'amount',
                                header: 'Amount',
                                align: 'right',
                                cell: (t: TxnRow) => (
                                    <b>{formatPaise(t.amount)}</b>
                                ),
                            },
                            {
                                key: 'status',
                                header: 'Status',
                                cell: (t: TxnRow) => (
                                    <StatusBadge status={t.status} />
                                ),
                            },
                            {
                                key: 'submitted',
                                header: 'Submitted',
                                cell: (t: TxnRow) => (
                                    <span className="text-xs whitespace-nowrap">
                                        {formatRelative(t.submitted_at)}
                                    </span>
                                ),
                            },
                            {
                                key: 'actions',
                                header: '',
                                align: 'right',
                                cell: (t: TxnRow) => decisionButtons(t, true),
                            },
                        ]}
                        rows={items.data}
                        rowKey={(t) => t.id}
                        onRowClick={(t) => openTxn(t.id)}
                    />
                </Panel>
            )}

            {(items.prev_page_url || items.next_page_url) && (
                <div className="flex justify-end gap-2 text-xs">
                    {items.prev_page_url && (
                        <PgButton
                            onClick={() => router.visit(items.prev_page_url!)}
                        >
                            ‹ Previous
                        </PgButton>
                    )}
                    {items.next_page_url && (
                        <PgButton
                            onClick={() => router.visit(items.next_page_url!)}
                        >
                            Next ›
                        </PgButton>
                    )}
                </div>
            )}

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
                    actions={decisionButtons(open)}
                />
            )}

            {dialog?.kind === 'approve' && (
                <ApproveDialog
                    txn={dialog.txn}
                    url={routes.approve(dialog.txn.id).url}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog?.kind === 'decline' && (
                <DeclineDialog
                    txn={dialog.txn}
                    reasons={props.reasons}
                    url={routes.decline(dialog.txn.id).url}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog?.kind === 'hold' && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setDialog(null)}
                    title={`Hold ${dialog.txn.reference}?`}
                    description="Moves it to Payment hold while you check. You can approve or decline it later."
                    confirmLabel="Hold"
                    tone="warning"
                    input={{
                        label: 'Reason (kept in the history)',
                        required: true,
                    }}
                    onConfirm={(reason) =>
                        router.post(
                            routes.hold(dialog.txn.id).url,
                            { reason },
                            {
                                preserveScroll: true,
                                onFinish: () => setDialog(null),
                            },
                        )
                    }
                />
            )}
        </>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-tx3">{label}</dt>
            <dd className="truncate">{children}</dd>
        </>
    );
}

function ApproveDialog({
    txn,
    url,
    onClose,
}: {
    txn: TxnRow;
    url: string;
    onClose: () => void;
}) {
    const form = useForm({ bank_utr: '', note: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Approve ${formatPaise(txn.amount)}?`}
            description={`${txn.reference} · ${txn.partner?.name ?? ''}. Approve only when this exact amount is in your bank account. The partner is credited immediately and this can’t be undone.`}
            submitLabel="Approve"
            processing={form.processing}
            onSubmit={() =>
                form.post(url, { preserveScroll: true, onSuccess: onClose })
            }
        >
            <div className="rounded-lg bg-sf2 px-3 py-2.5 text-[12.5px]">
                Customer’s UTR:{' '}
                <b className="font-mono">
                    {txn.customer_utr ?? 'none — screenshot only'}
                </b>
                {txn.account && (
                    <div className="text-tx3">
                        Paid into:{' '}
                        {txn.method === 'bank_transfer'
                            ? `${txn.account.bank} ${txn.account.number}`
                            : (txn.account.upi ?? txn.account.label)}
                    </div>
                )}
            </div>
            <Field
                label="Bank UTR (from your bank statement)"
                hint="Type it from the bank entry, not from the customer’s claim."
                error={errors.bank_utr ?? errors.commission ?? errors.status}
            >
                <TextInput
                    autoFocus
                    required
                    className="font-mono"
                    value={form.data.bank_utr}
                    invalid={!!errors.bank_utr}
                    onChange={(event) =>
                        form.setData('bank_utr', event.target.value)
                    }
                />
            </Field>
            <Field label="Note (optional)">
                <TextInput
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}

function DeclineDialog({
    txn,
    reasons,
    url,
    onClose,
}: {
    txn: TxnRow;
    reasons: Record<string, string>;
    url: string;
    onClose: () => void;
}) {
    const form = useForm({ reason_code: '', note: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Decline ${txn.reference}?`}
            description="The partner is told the payment could not be confirmed. The account’s capacity is released."
            submitLabel="Decline"
            processing={form.processing}
            onSubmit={() =>
                form.post(url, { preserveScroll: true, onSuccess: onClose })
            }
        >
            <Field label="Reason" error={errors.reason_code ?? errors.status}>
                <SelectInput
                    required
                    value={form.data.reason_code}
                    onChange={(event) =>
                        form.setData('reason_code', event.target.value)
                    }
                >
                    <option value="">Choose…</option>
                    {Object.entries(reasons).map(([code, label]) => (
                        <option key={code} value={code}>
                            {label}
                        </option>
                    ))}
                </SelectInput>
            </Field>
            <Field
                label={
                    form.data.reason_code === 'other'
                        ? 'Details (required)'
                        : 'Details (optional)'
                }
                error={errors.note}
            >
                <TextInput
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}
