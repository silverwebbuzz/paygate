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
import { TxnFilters } from '@/components/pg/txn-filters';
import type { TxnFilterValues } from '@/components/pg/txn-filters';
import { formatDateTime, formatRelative } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { TONE_CLASSES } from '@/lib/status';
import { cn } from '@/lib/utils';
import adminDeposits from '@/routes/admin/deposits';
import { show as fileUrl } from '@/routes/files';
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
    filters: TxnFilterValues & { search: string };
    partners: { id: string; code: string; name: string }[];
    branch_options: { id: string; code: string; name: string }[];
    reasons: Record<string, string>;
    move_options?: MoveOption[];
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

type Dialog = null | {
    kind: 'approve' | 'hold' | 'decline' | 'move' | 'not_in_bank';
    txn: TxnRow;
};

type MoveOption = {
    id: string;
    label: string;
    bank: string | null;
    number: string | null;
    upi: string | null;
    status: string;
    branch: { id: string; code: string; name: string };
};

/** status_reason_code of a deposit the branch says isn't in its bank. */
const NOT_IN_BANK = 'not_in_our_bank';

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

    const dated = filters.from !== null || filters.to !== null;
    const visit = (next: Record<string, string | string[] | null>) =>
        router.get(
            routes.index({
                query: Object.fromEntries(
                    Object.entries({ tab, ...filters, search, ...next }).filter(
                        ([, v]) => (Array.isArray(v) ? v.length > 0 : v),
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

    const openMove = (txn: TxnRow) => {
        setDialog({ kind: 'move', txn });
        router.reload({ only: ['move_options'], data: { move: txn.id } });
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
                {portal === 'admin' && (
                    <PgButton
                        className="h-7 px-2 text-xs"
                        title="Move to the branch / account the money reached"
                        onClick={() => openMove(txn)}
                    >
                        Move
                    </PgButton>
                )}
                {portal === 'branch' &&
                    !compact &&
                    txn.reason_code !== NOT_IN_BANK && (
                        <PgButton
                            className="h-7 px-2 text-xs"
                            title="The money isn’t in our bank: ask Admin to move it"
                            onClick={() =>
                                setDialog({ kind: 'not_in_bank', txn })
                            }
                        >
                            Not in our bank
                        </PgButton>
                    )}
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
                            {dated
                                ? TAB_INFO[key].label.replace(' today', '')
                                : TAB_INFO[key].label}
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
                <TxnFilters
                    value={filters}
                    partners={partners}
                    branches={props.branch_options}
                    onChange={visit}
                />
                <div className="flex-1" />
                <span className="text-xs text-tx3">
                    {items.total}{' '}
                    {tab === 'pending' || tab === 'hold'
                        ? 'waiting · newest first'
                        : '· newest first'}
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
                                </div>
                                <StatusBadge status={txn.status} />
                            </div>
                            <div className="flex items-baseline justify-between">
                                <span className="text-[22px] font-semibold tracking-[-.02em]">
                                    {formatPaise(txn.amount)}
                                    {txn.order_amount !== null && (
                                        <span className="ml-1.5 text-xs font-normal text-wn">
                                            of {formatPaise(txn.order_amount)}
                                        </span>
                                    )}
                                </span>
                                <span className="text-xs text-tx3">
                                    {txn.method
                                        ? METHOD_LABELS[txn.method]
                                        : ''}
                                </span>
                            </div>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                                <Row label="Branch">
                                    {txn.branch ? (
                                        <>
                                            {txn.branch.name}{' '}
                                            <span className="font-mono text-tx3">
                                                {txn.branch.code}
                                            </span>
                                        </>
                                    ) : (
                                        '—'
                                    )}
                                </Row>
                                <Row label="Customer">
                                    {txn.customer?.name ??
                                        txn.customer?.id ??
                                        '—'}
                                </Row>
                                <Row label="Transaction">
                                    <span className="font-mono">
                                        {txn.reference}
                                    </span>
                                </Row>
                                <Row label="Order ID">
                                    <span className="font-mono">
                                        {txn.order_id}
                                    </span>
                                </Row>
                                <Row label="Deposit type">
                                    {originLabel(txn.origin)}
                                </Row>
                                <Row label="Created">
                                    {formatDateTime(txn.created_at)}
                                </Row>
                                <Row label="Pay link opened">
                                    {txn.link_opened_at
                                        ? formatDateTime(txn.link_opened_at)
                                        : 'Not opened'}
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
                                <Row label="Payment proof">
                                    <ProofLink txn={txn} />
                                </Row>
                                {txn.bank_line && (
                                    <Row label="Bank statement">
                                        <span className="font-medium text-ok">
                                            ✓ Credit found
                                        </span>{' '}
                                        <span className="font-mono text-tx3">
                                            {txn.bank_line.utr}
                                        </span>
                                        {txn.bank_line.description && (
                                            <div className="text-tx3">
                                                {txn.bank_line.description}
                                            </div>
                                        )}
                                    </Row>
                                )}
                                <Row label="Submitted">
                                    {formatDateTime(txn.submitted_at)}{' '}
                                    <span className="text-tx3">
                                        · {formatRelative(txn.submitted_at)}
                                    </span>
                                </Row>
                                {txn.status === 'under_review' && (
                                    <Row label="Hold reason">
                                        {txn.reason_code === NOT_IN_BANK && (
                                            <span className="mr-1 rounded bg-wnb px-1.5 py-0.5 font-medium text-wn">
                                                Not in branch’s bank
                                            </span>
                                        )}
                                        {txn.note ?? '—'}
                                    </Row>
                                )}
                                {(tab === 'approved' || tab === 'declined') && (
                                    <Row label="Decision">
                                        {tab === 'approved'
                                            ? `Bank UTR ${txn.bank_utr}`
                                            : txn.reason_code?.replaceAll(
                                                  '_',
                                                  ' ',
                                              )}{' '}
                                        · {formatDateTime(txn.decided_at)}
                                        {txn.decided_by &&
                                            ` · by ${txn.decided_by}`}
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
                                header: 'Partner',
                                cell: (t: TxnRow) => (
                                    <div>
                                        <div className="font-medium">
                                            {t.partner?.name}
                                        </div>
                                        <div className="font-mono text-xs text-tx3">
                                            {t.partner?.code}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'branch',
                                header: 'Branch',
                                cell: (t: TxnRow) =>
                                    t.branch ? (
                                        <div>
                                            <div className="font-medium">
                                                {t.branch.name}
                                            </div>
                                            <div className="font-mono text-xs text-tx3">
                                                {t.branch.code}
                                            </div>
                                        </div>
                                    ) : (
                                        '—'
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
                                    <>
                                        <b>{formatPaise(t.amount)}</b>
                                        {t.order_amount !== null && (
                                            <div className="text-[11px] text-wn">
                                                of {formatPaise(t.order_amount)}
                                            </div>
                                        )}
                                    </>
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
                                key: 'proof',
                                header: 'Proof',
                                cell: (t: TxnRow) => <ProofLink txn={t} />,
                            },
                            {
                                key: 'submitted',
                                header: 'Created · Submitted',
                                cell: (t: TxnRow) => (
                                    <div className="text-xs whitespace-nowrap">
                                        <div>
                                            {formatDateTime(t.created_at)}
                                        </div>
                                        <div className="text-tx3">
                                            {formatRelative(t.submitted_at)}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'decided',
                                header: 'Approved/Declined by',
                                cell: (t: TxnRow) =>
                                    t.decided_by ? (
                                        <div className="text-xs">
                                            <div>{t.decided_by}</div>
                                            <div className="whitespace-nowrap text-tx3">
                                                {formatDateTime(t.decided_at)}
                                            </div>
                                        </div>
                                    ) : (
                                        '—'
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
            {dialog?.kind === 'move' && (
                <MoveDialog
                    txn={dialog.txn}
                    options={props.move_options}
                    url={adminDeposits.move(dialog.txn.id).url}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog?.kind === 'not_in_bank' && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setDialog(null)}
                    title={`${dialog.txn.reference} isn’t in your bank?`}
                    description="Puts it on Payment hold and asks Admin to move it to the branch the customer really paid. Use this when the customer’s UTR isn’t in your account’s statement."
                    confirmLabel="Send to Admin"
                    tone="warning"
                    input={{
                        label: 'Note for Admin (optional)',
                        required: false,
                    }}
                    onConfirm={(note) =>
                        router.post(
                            branchDeposits.notInBank(dialog.txn.id).url,
                            { note },
                            {
                                preserveScroll: true,
                                onFinish: () => setDialog(null),
                            },
                        )
                    }
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

function originLabel(origin: string | undefined): string {
    return origin === 'api' ? 'Client request' : (origin ?? '—');
}

/** Opens the customer's latest payment screenshot in a new tab. */
function ProofLink({ txn }: { txn: TxnRow }) {
    return txn.proof_file_id ? (
        <a
            href={fileUrl(txn.proof_file_id).url}
            target="_blank"
            rel="noreferrer"
            onClick={(event) => event.stopPropagation()}
            className="font-medium text-ac hover:underline"
        >
            View
        </a>
    ) : (
        <span className="text-tx3">—</span>
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
    // A matching bank statement line gives the verified UTR.
    const form = useForm({
        bank_utr: txn.bank_line?.utr ?? '',
        received_amount: (txn.amount / 100).toFixed(2),
        note: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const received = Math.round(
        Number(form.data.received_amount.replace(/[,\s₹]/g, '')) * 100,
    );
    const differs = Number.isFinite(received) && received !== txn.amount;
    // The bank's UTR isn't the customer's: a note is required and Admin is told.
    const bankUtr = form.data.bank_utr
        .replace(/[^A-Za-z0-9]/g, '')
        .toUpperCase();
    const utrDiffers =
        txn.customer_utr !== null &&
        bankUtr !== '' &&
        bankUtr !== txn.customer_utr;
    const noteRequired = differs || utrDiffers;

    return (
        <FormDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Approve ${formatPaise(differs && received > 0 ? received : txn.amount)}?`}
            description={`${txn.reference} · ${txn.partner?.name ?? ''}. Enter the amount that actually reached your bank account. The partner is credited that amount immediately and this can’t be undone.`}
            submitLabel={differs ? 'Approve amount received' : 'Approve'}
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
                {txn.bank_line && (
                    <div className="text-ok">
                        ✓ The bank statement shows this credit (
                        {txn.bank_line.value_date}).
                    </div>
                )}
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
            <Field
                label={`Amount received (₹) · order ${formatPaise(txn.amount)}`}
                hint="Change it only if the customer paid a different amount."
                error={errors.received_amount}
            >
                <TextInput
                    required
                    inputMode="decimal"
                    className="font-mono"
                    value={form.data.received_amount}
                    invalid={!!errors.received_amount}
                    onChange={(event) =>
                        form.setData('received_amount', event.target.value)
                    }
                />
            </Field>
            {differs && received > 0 && (
                <div className="rounded-lg bg-wnb px-3 py-2.5 text-[12.5px] text-wn">
                    The customer paid {formatPaise(received)} instead of{' '}
                    {formatPaise(txn.amount)}.{' '}
                    {txn.partner?.name ?? 'The partner'} is credited{' '}
                    {formatPaise(received)} and Admin is told.
                </div>
            )}
            {utrDiffers && (
                <div className="rounded-lg bg-erb px-3 py-2.5 text-[12.5px] text-er">
                    This isn’t the customer’s UTR ({txn.customer_utr}). Approve
                    only if your bank entry really is this deposit — say why
                    below. Admin is told.
                </div>
            )}
            <Field
                label={noteRequired ? 'Note (required)' : 'Note (optional)'}
                error={errors.note}
            >
                <TextInput
                    required={noteRequired}
                    value={form.data.note}
                    invalid={!!errors.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}

/** Admin: move a waiting deposit to the account the money really reached. */
function MoveDialog({
    txn,
    options,
    url,
    onClose,
}: {
    txn: TxnRow;
    options: MoveOption[] | undefined;
    url: string;
    onClose: () => void;
}) {
    const form = useForm({ payment_account_id: '', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const branches = Object.values(
        (options ?? []).reduce<
            Record<
                string,
                { branch: MoveOption['branch']; accounts: MoveOption[] }
            >
        >((groups, option) => {
            groups[option.branch.id] ??= {
                branch: option.branch,
                accounts: [],
            };
            groups[option.branch.id].accounts.push(option);

            return groups;
        }, {}),
    );

    return (
        <FormDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Move ${txn.reference} to another branch?`}
            description={`Now with ${txn.branch?.code ?? '—'}${txn.account ? ` · ${txn.account.label}` : ''}. Pick the account the customer’s money actually reached. The deposit goes back to Pending there, today’s capacity moves with it, and that branch is alerted.`}
            submitLabel="Move deposit"
            processing={form.processing}
            onSubmit={() =>
                form.post(url, { preserveScroll: true, onSuccess: onClose })
            }
        >
            <Field
                label="Branch · account the money reached"
                error={errors.payment_account_id ?? errors.status}
            >
                <SelectInput
                    required
                    disabled={options === undefined}
                    value={form.data.payment_account_id}
                    onChange={(event) =>
                        form.setData('payment_account_id', event.target.value)
                    }
                >
                    <option value="">
                        {options === undefined
                            ? 'Loading accounts…'
                            : options.length === 0
                              ? 'No other account of a mapped branch'
                              : 'Choose…'}
                    </option>
                    {branches.map(({ branch, accounts }) => (
                        <optgroup
                            key={branch.id}
                            label={`${branch.code} · ${branch.name}`}
                        >
                            {accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.label}
                                    {account.number
                                        ? ` · ${account.number}`
                                        : ''}
                                    {account.upi ? ` · ${account.upi}` : ''}
                                    {account.status !== 'active'
                                        ? ` (${account.status})`
                                        : ''}
                                </option>
                            ))}
                        </optgroup>
                    ))}
                </SelectInput>
            </Field>
            <Field label="Reason (kept in the history)" error={errors.reason}>
                <TextInput
                    required
                    value={form.data.reason}
                    placeholder="e.g. Customer paid BR2’s HDFC; UTR in its statement"
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
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
