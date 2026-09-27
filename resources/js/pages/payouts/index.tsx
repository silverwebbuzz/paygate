import { Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { FormDialog } from '@/components/pg/form-dialog';
import { LiveIndicator, PageHeader } from '@/components/pg/page-header';
import { StatusBadge } from '@/components/pg/status-badge';
import { TransactionDrawer } from '@/components/pg/transaction-drawer';
import type { TxnDetail, TxnRow } from '@/components/pg/transaction-drawer';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatDateTime, formatRelative } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { TONE_CLASSES } from '@/lib/status';
import { cn } from '@/lib/utils';
import adminPayouts from '@/routes/admin/payouts';
import branchPayouts from '@/routes/branch/payouts';
import type { UserType } from '@/types';

type Tab = 'to_pay' | 'paying' | 'paid' | 'failed';

type PayTo = {
    type: 'bank' | 'upi';
    name: string;
    account_number: string | null;
    ifsc: string | null;
    bank_name: string | null;
    upi_id: string | null;
};

type Row = TxnRow & { pay_to: PayTo | null };

type Props = {
    portal: UserType;
    tab: Tab;
    tabs: Record<Tab, { count: number; amount: number }>;
    items: {
        data: Row[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { search: string };
    reasons: Record<string, string>;
    branches: { id: string; code: string; name: string }[];
    selected: string | null;
    detail?: TxnDetail | null;
};

const TAB_INFO: Record<
    Tab,
    { label: string; icon: string; tone: keyof typeof TONE_CLASSES }
> = {
    to_pay: { label: 'To pay', icon: '◷', tone: 'wn' },
    paying: { label: 'Being paid', icon: '→', tone: 'in' },
    paid: { label: 'Paid today', icon: '✓', tone: 'ok' },
    failed: { label: 'Failed today', icon: '✕', tone: 'er' },
};

type Dialog = null | { kind: 'complete' | 'fail' | 'reassign'; payout: Row };

/**
 * Manual Payout: the branch pays each customer from its bank, then records
 * the transfer UTR (or that it couldn't pay). Admin sees all branches and
 * can move a waiting payout.
 */
export default function Payouts(props: Props) {
    const { portal, tab, tabs, items, filters } = props;
    const routes = portal === 'admin' ? adminPayouts : branchPayouts;
    const [search, setSearch] = useState(filters.search);
    const [openId, setOpenId] = useState<string | null>(props.selected);
    const [dialog, setDialog] = useState<Dialog>(null);
    const open = items.data.find((row) => row.id === openId) ?? null;

    const visit = (next: Record<string, string | null>) =>
        router.get(
            routes.index({
                query: Object.fromEntries(
                    Object.entries({ tab, search, ...next }).filter(
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
        if (tab !== 'to_pay' && tab !== 'paying') return;
        const timer = setInterval(
            () => router.reload({ only: ['items', 'tabs'] }),
            20000,
        );

        return () => clearInterval(timer);
    }, [tab]);

    const openPayout = (id: string) => {
        setOpenId(id);
        router.reload({ only: ['detail', 'selected'], data: { txn: id } });
    };

    const actions = (payout: Row) =>
        payout.can.process &&
        ['assigned', 'processing'].includes(payout.status) && (
            <div
                className="flex flex-wrap gap-1.5"
                onClick={(event) => event.stopPropagation()}
            >
                {portal === 'admin' && payout.status === 'assigned' && (
                    <PgButton
                        className="h-7 text-xs"
                        onClick={() => setDialog({ kind: 'reassign', payout })}
                    >
                        Move
                    </PgButton>
                )}
                <PgButton
                    variant="danger"
                    className="h-7 text-xs"
                    onClick={() => setDialog({ kind: 'fail', payout })}
                >
                    Can’t pay
                </PgButton>
                {payout.status === 'assigned' && (
                    <PgButton
                        className="h-7 text-xs"
                        onClick={() =>
                            router.post(
                                routes.start(payout.id).url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Start
                    </PgButton>
                )}
                <PgButton
                    variant="primary"
                    className="h-7 text-xs"
                    onClick={() => setDialog({ kind: 'complete', payout })}
                >
                    Paid
                </PgButton>
            </div>
        );

    return (
        <>
            <Head title="Manual Payout" />
            <PageHeader
                title="Manual Payout"
                description="Pay each customer the exact amount from your bank, then record the transfer UTR. If you can’t pay, mark it so the partner can retry."
                eyebrow={
                    (tab === 'to_pay' || tab === 'paying') && (
                        <LiveIndicator>
                            Live · refreshes every 20 s
                        </LiveIndicator>
                    )
                }
            />

            <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
                {(Object.keys(TAB_INFO) as Tab[]).map((key) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => visit({ tab: key })}
                        className={cn(
                            'flex flex-col gap-1 rounded-[10px] border bg-sf px-3.5 py-3 text-left',
                            key === tab
                                ? 'border-ac shadow-[0_0_0_3px_var(--color-acs)]'
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

            <Panel className="flex items-center gap-2 px-3 py-2.5">
                <label className="flex h-[30px] w-[300px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                    <Search className="size-3.5" />
                    <input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Payout id, order id, UTR or customer"
                        className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                    />
                </label>
                <div className="flex-1" />
                <span className="text-xs text-tx3">
                    {items.total}{' '}
                    {tab === 'to_pay' ? 'waiting · oldest first' : ''}
                </span>
            </Panel>

            {items.data.length === 0 ? (
                <Panel>
                    <EmptyState
                        title={
                            tab === 'to_pay'
                                ? 'Nothing to pay right now'
                                : 'Nothing here'
                        }
                        description={
                            tab === 'to_pay'
                                ? 'New withdrawals appear here as soon as partners request them.'
                                : undefined
                        }
                    />
                </Panel>
            ) : (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {items.data.map((payout) => (
                        <div
                            key={payout.id}
                            onClick={() => openPayout(payout.id)}
                            className="flex cursor-pointer flex-col gap-3 rounded-[12px] border border-ln bg-sf p-4 hover:border-ac"
                        >
                            <div className="flex items-start gap-2.5">
                                <div className="min-w-0 flex-1">
                                    <div className="truncate font-medium">
                                        {payout.partner?.name}{' '}
                                        <span className="font-mono text-xs text-tx3">
                                            {payout.partner?.code}
                                        </span>
                                    </div>
                                    <div className="truncate text-xs text-tx3">
                                        {portal === 'admin' &&
                                            `${payout.branch?.code ?? '—'} · `}
                                        <span className="font-mono">
                                            {payout.reference}
                                        </span>{' '}
                                        · {formatRelative(payout.created_at)}
                                    </div>
                                </div>
                                <StatusBadge status={payout.status} />
                            </div>
                            <div className="text-[24px] font-semibold tracking-[-.02em]">
                                {formatPaise(payout.amount)}
                            </div>
                            {payout.pay_to ? (
                                <div
                                    className="overflow-hidden rounded-lg border border-ln"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    <CopyLine
                                        label="Pay to"
                                        value={payout.pay_to.name}
                                    />
                                    {payout.pay_to.type === 'bank' ? (
                                        <>
                                            <CopyLine
                                                label="Account number"
                                                value={
                                                    payout.pay_to
                                                        .account_number ?? ''
                                                }
                                                mono
                                            />
                                            <CopyLine
                                                label="IFSC"
                                                value={payout.pay_to.ifsc ?? ''}
                                                mono
                                            />
                                            {payout.pay_to.bank_name && (
                                                <CopyLine
                                                    label="Bank"
                                                    value={
                                                        payout.pay_to.bank_name
                                                    }
                                                    copy={false}
                                                />
                                            )}
                                        </>
                                    ) : (
                                        <CopyLine
                                            label="UPI ID"
                                            value={payout.pay_to.upi_id ?? ''}
                                            mono
                                        />
                                    )}
                                    <CopyLine
                                        label="Amount"
                                        value={formatPaise(payout.amount)}
                                        copyValue={(
                                            payout.amount / 100
                                        ).toFixed(2)}
                                    />
                                </div>
                            ) : (
                                <div className="text-xs text-tx3">
                                    {payout.beneficiary?.name} ·{' '}
                                    <span className="font-mono">
                                        {payout.beneficiary?.masked}
                                    </span>
                                    {payout.bank_utr && (
                                        <div>
                                            UTR{' '}
                                            <span className="font-mono">
                                                {payout.bank_utr}
                                            </span>{' '}
                                            ·{' '}
                                            {formatDateTime(payout.decided_at)}
                                        </div>
                                    )}
                                    {payout.status === 'failed' && (
                                        <div className="text-er">
                                            {payout.reason_code?.replaceAll(
                                                '_',
                                                ' ',
                                            )}
                                            {payout.note && ` — ${payout.note}`}
                                        </div>
                                    )}
                                </div>
                            )}
                            <div className="flex items-center justify-between gap-2 border-t border-ln2 pt-3">
                                <span className="text-xs font-medium text-ac">
                                    History ›
                                </span>
                                {actions(payout)}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {(items.prev_page_url || items.next_page_url) && (
                <div className="flex justify-end gap-2">
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
                    actions={actions(open)}
                />
            )}

            {dialog?.kind === 'complete' && (
                <CompleteDialog
                    payout={dialog.payout}
                    url={routes.complete(dialog.payout.id).url}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog?.kind === 'fail' && (
                <FailDialog
                    payout={dialog.payout}
                    reasons={props.reasons}
                    url={routes.fail(dialog.payout.id).url}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog?.kind === 'reassign' && (
                <ReassignDialog
                    payout={dialog.payout}
                    branches={props.branches}
                    url={adminPayouts.reassign(dialog.payout.id).url}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

function CopyLine({
    label,
    value,
    copyValue,
    mono,
    copy = true,
}: {
    label: string;
    value: string;
    copyValue?: string;
    mono?: boolean;
    copy?: boolean;
}) {
    const [copied, doCopy] = useClipboard();
    const target = copyValue ?? value;

    return (
        <div className="flex items-center gap-2 border-b border-ln2 px-3 py-1.5 last:border-b-0">
            <div className="min-w-0 flex-1">
                <div className="text-[11px] text-tx3">{label}</div>
                <div
                    className={cn(
                        'truncate text-[13px] font-medium',
                        mono && 'font-mono',
                    )}
                >
                    {value}
                </div>
            </div>
            {copy && (
                <button
                    type="button"
                    onClick={() => doCopy(target)}
                    className="grid size-7 place-items-center rounded-md border border-ln text-tx3 hover:text-tx"
                    title={`Copy ${label.toLowerCase()}`}
                >
                    {copied === target ? (
                        <Check className="size-3.5 text-ok" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                </button>
            )}
        </div>
    );
}

function CompleteDialog({
    payout,
    url,
    onClose,
}: {
    payout: Row;
    url: string;
    onClose: () => void;
}) {
    const form = useForm({ bank_utr: '', note: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Mark ${formatPaise(payout.amount)} as paid?`}
            description={`${payout.reference} to ${payout.pay_to?.name ?? payout.beneficiary?.name}. Only after the transfer has left your bank. The partner is told at once and this can’t be undone.`}
            submitLabel="Mark paid"
            processing={form.processing}
            onSubmit={() =>
                form.post(url, { preserveScroll: true, onSuccess: onClose })
            }
        >
            <Field
                label="Transfer UTR (from your bank)"
                error={errors.bank_utr ?? errors.status ?? errors.commission}
            >
                <TextInput
                    autoFocus
                    required
                    className="font-mono"
                    value={form.data.bank_utr}
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

function FailDialog({
    payout,
    reasons,
    url,
    onClose,
}: {
    payout: Row;
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
            title={`Can’t pay ${payout.reference}?`}
            description="The payout fails, the partner’s balance hold is released and the partner is told, so they can send a new request."
            submitLabel="Mark failed"
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

function ReassignDialog({
    payout,
    branches,
    url,
    onClose,
}: {
    payout: Row;
    branches: Props['branches'];
    url: string;
    onClose: () => void;
}) {
    const form = useForm({ branch_id: '', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Move ${payout.reference} to another branch?`}
            description={`Now with ${payout.branch?.code}. The new branch must be mapped to ${payout.partner?.name} for withdrawals and hold enough of its balance; the hold moves with it.`}
            submitLabel="Move payout"
            processing={form.processing}
            onSubmit={() =>
                form.post(url, { preserveScroll: true, onSuccess: onClose })
            }
        >
            <Field label="Branch" error={errors.branch_id ?? errors.status}>
                <SelectInput
                    required
                    value={form.data.branch_id}
                    onChange={(event) =>
                        form.setData('branch_id', event.target.value)
                    }
                >
                    <option value="">Choose…</option>
                    {branches
                        .filter((branch) => branch.code !== payout.branch?.code)
                        .map((branch) => (
                            <option key={branch.id} value={branch.id}>
                                {branch.code} · {branch.name}
                            </option>
                        ))}
                </SelectInput>
            </Field>
            <Field label="Reason (kept in the audit log)" error={errors.reason}>
                <TextInput
                    required
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}
