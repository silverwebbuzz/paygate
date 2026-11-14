import { Link, router, useForm } from '@inertiajs/react';
import { Download, History, Info, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { PgButton } from '@/components/pg/button';
import { ConfirmDialog } from '@/components/pg/confirm-dialog';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { Drawer, KeyValues } from '@/components/pg/drawer';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { ViewTabs } from '@/components/pg/filter-bar';
import { FormDialog } from '@/components/pg/form-dialog';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { MultiSelect } from '@/components/pg/multi-select';
import { LiveIndicator } from '@/components/pg/page-header';
import { PaymentHubHeader } from '@/components/pg/payment-hub';
import { PeriodFilter, periodQuery } from '@/components/pg/period-filter';
import type { PeriodQuery, PeriodValue } from '@/components/pg/period-filter';
import {
    CompareBlock,
    DISPLAY_LABELS,
    LineAmount,
    MATCH_LABELS,
} from '@/components/pg/reconciliation';
import type {
    CaseSummary,
    StatementLine,
} from '@/components/pg/reconciliation';
import { SHOW_DEBIT } from '@/components/pg/statement-entry';
import { accountLabel } from '@/components/pg/statement-import-dialog';
import { StatusBadge } from '@/components/pg/status-badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import type { HubProps } from '@/lib/payment-hub';
import adminCases from '@/routes/admin/cases';
import adminDeposits from '@/routes/admin/deposits';
import adminImports from '@/routes/admin/statement-imports';
import adminStatements from '@/routes/admin/statements';
import branchCases from '@/routes/branch/cases';
import branchDeposits from '@/routes/branch/deposits';
import branchImports from '@/routes/branch/statement-imports';
import branchStatements from '@/routes/branch/statements';
import type { UserType } from '@/types';

type Filters = {
    from: string;
    to: string;
    branches: string[];
    account: string | null;
    status: string | null;
    match: string | null;
    search: string;
};

type Account = {
    id: string;
    label: string;
    bank: string | null;
    number: string | null;
    upi: string | null;
    branch_id: string;
    branch: string;
};

type Detail = {
    id: string;
    cases: (CaseSummary & {
        resolved_by: string | null;
        resolved_at: string | null;
        created_at: string | null;
    })[];
    audit: {
        action: string;
        actor: string;
        new: Record<string, unknown> | null;
        at: string;
    }[];
};

type Props = {
    portal: UserType;
    hub: HubProps;
    items: {
        data: StatementLine[];
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Filters;
    period: PeriodValue;
    counts: Record<
        'all' | 'pending' | 'approved' | 'unsettled' | 'ignored',
        number
    >;
    kpis: {
        credit: number;
        debit: number;
        credit_lines: number;
        debit_lines: number;
        pending: number;
        approved: number;
        unsettled: number;
    };
    accounts: Account[];
    branches: { id: string; code: string; name: string }[];
    can: { create: boolean; resolve: boolean; edit_line: boolean };
    reasons: Record<string, string>;
    selected: string | null;
    detail?: Detail | null;
};

/** Deposit statuses that can still be declined. */
const WAITING = ['payment_submitted', 'payment_detected', 'under_review'];

const TABS: [string, string][] = [
    ['all', 'All'],
    ['pending', 'Pending'],
    ['approved', 'Approved'],
    ['unsettled', 'Unsettled'],
    ['ignored', 'Closed'],
];

type Decision = null | {
    kind: 'approve' | 'hold' | 'decline' | 'edit';
    line: StatementLine;
};

/**
 * Manual A/C Statement (Admin and branch), as designed:
 * KPIs, a one-line entry form, filters, the dense lines table and the line
 * drawer with the branch compare block.
 */
export default function Statements(props: Props) {
    const { portal, items, filters, counts, kpis, accounts, branches } = props;
    const routes = portal === 'admin' ? adminStatements : branchStatements;
    const deposits = portal === 'admin' ? adminDeposits : branchDeposits;
    const cases = portal === 'admin' ? adminCases : branchCases;
    const imports = portal === 'admin' ? adminImports : branchImports;
    const [search, setSearch] = useState(filters.search);
    const [openId, setOpenId] = useState<string | null>(props.selected);
    const [decision, setDecision] = useState<Decision>(null);
    const open = items.data.find((line) => line.id === openId) ?? null;
    // Admin only: an unsettled line's UTR / amount can be corrected.
    const canEdit = (line: StatementLine) =>
        props.can.edit_line && line.case?.status === 'open';

    // from / to are the business dates the period covers; the URL keeps the period.
    const query = (next: Partial<Filters & PeriodQuery> = {}) =>
        Object.fromEntries(
            Object.entries({
                ...filters,
                from: null,
                to: null,
                ...('range' in next ? {} : periodQuery(props.period)),
                search,
                ...next,
            }).filter(([, value]) =>
                Array.isArray(value) ? value.length > 0 : value,
            ),
        ) as Record<string, string | string[]>;
    const visit = (next: Partial<Filters & PeriodQuery>) =>
        router.get(
            routes.index({ query: query(next) }).url,
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
        if (props.selected && !props.detail)
            router.reload({ only: ['detail'] });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Live: lines get matched and approved in the background (auto-approve,
    // the branch deciding), so pick up their new status every 15 s. Skipped
    // while the tab is hidden; the open line's details refresh too.
    useEffect(() => {
        const timer = setInterval(() => {
            if (document.hidden) return;
            router.reload({
                only: openId
                    ? ['items', 'counts', 'kpis', 'hub', 'detail']
                    : ['items', 'counts', 'kpis', 'hub'],
                ...(openId ? { data: { line: openId } } : {}),
            });
        }, 15000);

        return () => clearInterval(timer);
    }, [openId]);

    const openLine = (id: string) => {
        setOpenId(id);
        router.reload({ only: ['detail', 'selected'], data: { line: id } });
    };

    const decisionButtons = (line: StatementLine) =>
        line.transaction?.can_decide &&
        (line.display === 'pending' || line.display === 'hold') && (
            <div
                className="flex gap-1.5"
                onClick={(event) => event.stopPropagation()}
            >
                {line.display !== 'hold' && (
                    <PgButton
                        className="h-7 px-2 text-xs text-hd"
                        onClick={() => setDecision({ kind: 'hold', line })}
                    >
                        Hold
                    </PgButton>
                )}
                <PgButton
                    variant="primary"
                    className="h-7 px-2 text-xs"
                    onClick={() => setDecision({ kind: 'approve', line })}
                >
                    Approve
                </PgButton>
            </div>
        );

    // The line's UTR matches a waiting deposit but the amount doesn't:
    // decline the deposit. The line stays unsettled until the customer
    // submits the UTR on a deposit of the amount the bank shows.
    const canDecline = (line: StatementLine) =>
        !line.linked &&
        line.case?.status === 'open' &&
        line.case.type === 'amount_mismatch' &&
        !!line.transaction?.can_decide &&
        WAITING.includes(line.transaction.status);

    const columns: Column<StatementLine>[] = [
        {
            key: 'sr',
            header: 'Sr',
            cell: (line) => (
                <span className="text-xs text-tx3">
                    {(items.from ?? 1) + items.data.indexOf(line)}
                </span>
            ),
        },
        {
            key: 'doc',
            header: 'Doc date · Description',
            cell: (line) => (
                <div className="max-w-[220px]">
                    <div className="text-xs whitespace-nowrap">
                        {formatDate(line.value_date)}
                    </div>
                    <div className="font-mono text-xs font-medium">
                        {line.utr ?? '—'}
                    </div>
                    <div className="truncate text-xs text-tx3">
                        {line.description}
                    </div>
                </div>
            ),
        },
        ...(SHOW_DEBIT
            ? [
                  {
                      key: 'debit',
                      header: 'Debit',
                      align: 'right' as const,
                      cell: (line: StatementLine) =>
                          line.direction === 'debit' ? (
                              <LineAmount line={line} />
                          ) : (
                              <span className="text-tx3">—</span>
                          ),
                  },
              ]
            : []),
        {
            key: 'credit',
            header: 'Credit',
            align: 'right',
            cell: (line) =>
                line.direction === 'credit' ? (
                    <LineAmount line={line} />
                ) : (
                    <span className="text-tx3">—</span>
                ),
        },
        {
            key: 'txn',
            header: 'Transaction details',
            cell: (line) => (
                <Pairs
                    rows={[
                        ['Bank', line.account.bank ?? line.account.label],
                        [
                            'Txn ID',
                            line.transaction ? (
                                <span className="font-mono">
                                    {line.transaction.reference}
                                    {!line.linked && (
                                        <span className="font-sans text-tx3">
                                            {' '}
                                            (suggested)
                                        </span>
                                    )}
                                </span>
                            ) : (
                                <span className="text-tx3">not linked</span>
                            ),
                        ],
                        [
                            'Bank UTR',
                            <span key="u" className="font-mono">
                                {line.transaction?.bank_utr ?? '—'}
                            </span>,
                        ],
                        ['Action by', line.transaction?.decided_by ?? '—'],
                    ]}
                />
            ),
        },
        {
            key: 'amount',
            header: 'Txn amount',
            align: 'right',
            cell: (line) =>
                line.transaction ? (
                    <span className="whitespace-nowrap">
                        {formatPaise(line.transaction.amount)}
                    </span>
                ) : (
                    <span className="text-tx3">—</span>
                ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (line) => (
                <div className="flex flex-col items-start gap-1.5">
                    <StatusBadge
                        status={line.display}
                        label={DISPLAY_LABELS[line.display]}
                    />
                    {line.case?.status === 'open' && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span className="inline-flex cursor-help items-center gap-1 text-[11.5px] font-semibold whitespace-nowrap text-er">
                                    {line.case.type_label}
                                    {line.case.notes && (
                                        <Info className="size-3.5" />
                                    )}
                                </span>
                            </TooltipTrigger>
                            {line.case.notes && (
                                <TooltipContent
                                    side="bottom"
                                    className="max-w-[300px] leading-snug"
                                >
                                    {line.case.notes}
                                </TooltipContent>
                            )}
                        </Tooltip>
                    )}
                    {decisionButtons(line)}
                </div>
            ),
        },
        {
            key: 'branches',
            header: 'Branch details',
            cell: (line) => (
                <Pairs
                    rows={[
                        ['Txn branch', line.transaction?.branch ?? '—'],
                        ['Bank entry', line.branch.name],
                        [
                            'Entry by',
                            line.source === 'import'
                                ? `${line.entered_by ?? '—'} (import)`
                                : (line.entered_by ?? '—'),
                        ],
                    ]}
                />
            ),
        },
        {
            key: 'match',
            header: 'Branch match',
            cell: (line) => (
                <StatusBadge
                    status={
                        line.branch_match === 'none'
                            ? 'none'
                            : line.branch_match
                    }
                    label={MATCH_LABELS[line.branch_match]}
                />
            ),
        },
        {
            key: 'dates',
            header: 'Request dates',
            cell: (line) => (
                <Pairs
                    rows={[
                        [
                            'Created',
                            formatDateTime(line.transaction?.created_at),
                        ],
                        [
                            'Submitted',
                            formatDateTime(line.transaction?.submitted_at),
                        ],
                        [
                            'Decided',
                            formatDateTime(line.transaction?.decided_at),
                        ],
                        ['Entered', formatDateTime(line.created_at)],
                    ]}
                />
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            cell: (line) => (
                <div
                    className="flex gap-1.5"
                    onClick={(event) => event.stopPropagation()}
                >
                    {line.case?.status === 'open' ? (
                        <Link
                            href={
                                cases.index({ query: { case: line.case.id } })
                                    .url
                            }
                            className="inline-flex h-7 items-center rounded-[7px] border border-ln px-2 text-xs font-medium text-ac"
                        >
                            Match
                        </Link>
                    ) : (
                        <PgButton
                            className="h-7 px-2 text-xs"
                            onClick={() => openLine(line.id)}
                        >
                            Details
                        </PgButton>
                    )}
                    {canEdit(line) && (
                        <PgButton
                            className="h-7 px-2 text-xs"
                            onClick={() => setDecision({ kind: 'edit', line })}
                        >
                            Edit
                        </PgButton>
                    )}
                    {canDecline(line) && (
                        <PgButton
                            className="h-7 px-2 text-xs text-er"
                            onClick={() =>
                                setDecision({ kind: 'decline', line })
                            }
                        >
                            Decline
                        </PgButton>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <PaymentHubHeader
                hub={props.hub}
                view="statement"
                live
                description="Record bank statement lines and reconcile them against pay-in transactions and branches."
                eyebrow={
                    <LiveIndicator>Live · refreshes every 15 s</LiveIndicator>
                }
                actions={
                    <>
                        <Link
                            href={imports.index().url}
                            className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                        >
                            <History className="size-3.5" /> History
                        </Link>
                        <a
                            href={routes.export({ query: query() }).url}
                            className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                        >
                            <Download className="size-3.5" /> Export CSV
                        </a>
                    </>
                }
            />

            <KpiGrid>
                <StatTile
                    label="Total records"
                    value={String(counts.all)}
                    icon="≡"
                    tone="nt"
                />
                <StatTile
                    label={`Total credit · ${kpis.credit_lines} lines`}
                    value={formatPaise(kpis.credit)}
                    icon="+"
                    tone="ok"
                />
                <StatTile
                    label={`Total debit · ${kpis.debit_lines} lines`}
                    value={formatPaise(kpis.debit)}
                    icon="−"
                    tone="er"
                />
                <StatTile
                    label="Pending · awaiting approval"
                    value={formatPaise(kpis.pending)}
                    icon="◷"
                    tone="wn"
                />
                <StatTile
                    label="Success · approved"
                    value={formatPaise(kpis.approved)}
                    icon="✓"
                    tone="ok"
                />
                <StatTile
                    label="Unsettled · no linked transaction"
                    value={formatPaise(kpis.unsettled)}
                    icon="!"
                    tone="hd"
                />
            </KpiGrid>

            <Panel className="overflow-hidden">
                <PeriodFilter
                    period={props.period}
                    onChange={visit}
                    className="border-b border-ln2 px-3 py-2.5"
                />
                <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    {portal === 'admin' && (
                        <MultiSelect
                            noun="branches"
                            items={branches}
                            selected={filters.branches}
                            onChange={(ids) =>
                                visit({ branches: ids, account: null })
                            }
                            className="h-[30px] w-[220px] flex-none rounded-[7px] text-[12.5px]"
                        />
                    )}
                    <SelectInput
                        className="h-[30px] w-[230px] text-[12.5px]"
                        value={filters.account ?? ''}
                        onChange={(event) =>
                            visit({ account: event.target.value || null })
                        }
                    >
                        <option value="">All accounts</option>
                        {accounts
                            .filter(
                                (account) =>
                                    filters.branches.length === 0 ||
                                    filters.branches.includes(
                                        account.branch_id,
                                    ),
                            )
                            .map((account) => (
                                <option key={account.id} value={account.id}>
                                    {accountLabel(account)}
                                </option>
                            ))}
                    </SelectInput>
                    <SelectInput
                        className="h-[30px] w-[170px] text-[12.5px]"
                        value={filters.match ?? ''}
                        onChange={(event) =>
                            visit({ match: event.target.value || null })
                        }
                    >
                        <option value="">Branch match: all</option>
                        <option value="matched">Matched</option>
                        <option value="mismatch">Mismatch</option>
                        <option value="none">Not matched</option>
                    </SelectInput>
                    <label className="flex h-[30px] w-[240px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="UTR, description or transaction id"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                </div>
                <ViewTabs
                    views={TABS.map(([key, label]) => ({
                        key,
                        label,
                        count: counts[key as keyof Props['counts']],
                    }))}
                    active={filters.status ?? 'all'}
                    onChange={(key) =>
                        visit({ status: key === 'all' ? null : key })
                    }
                />
                <div className="overflow-x-auto">
                    <div className="min-w-[1380px]">
                        <DataTable
                            columns={columns}
                            rows={items.data}
                            rowKey={(line) => line.id}
                            onView={(line) => openLine(line.id)}
                            empty={
                                <EmptyState
                                    title="No statement lines"
                                    description="Add a line above, or import the account’s statement. Change the period to see older lines."
                                />
                            }
                        />
                    </div>
                </div>
                <div className="flex items-center justify-between px-3 py-2.5 text-xs text-tx3">
                    <span>
                        {items.total === 0
                            ? 'No lines'
                            : `Showing ${items.from}–${items.to} of ${items.total} statement lines`}
                    </span>
                    <span className="flex gap-2">
                        {items.prev_page_url && (
                            <Link
                                href={items.prev_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1 text-tx"
                            >
                                ‹ Newer
                            </Link>
                        )}
                        {items.next_page_url && (
                            <Link
                                href={items.next_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1 text-tx"
                            >
                                Older ›
                            </Link>
                        )}
                    </span>
                </div>
            </Panel>

            {open && (
                <LineDrawer
                    line={open}
                    detail={
                        props.detail && props.detail.id === open.id
                            ? props.detail
                            : null
                    }
                    actions={
                        <>
                            {open.case?.status === 'open' && (
                                <Link
                                    href={
                                        cases.index({
                                            query: { case: open.case.id },
                                        }).url
                                    }
                                    className="inline-flex h-8 items-center rounded-[7px] bg-brand px-3 text-[13px] font-medium text-white"
                                >
                                    Match to transaction
                                </Link>
                            )}
                            {canEdit(open) && (
                                <PgButton
                                    className="h-8 px-3 text-[13px]"
                                    onClick={() =>
                                        setDecision({ kind: 'edit', line: open })
                                    }
                                >
                                    Edit UTR / amount
                                </PgButton>
                            )}
                            {canDecline(open) && (
                                <PgButton
                                    className="h-8 px-3 text-[13px] text-er"
                                    onClick={() =>
                                        setDecision({
                                            kind: 'decline',
                                            line: open,
                                        })
                                    }
                                >
                                    Decline
                                </PgButton>
                            )}
                            {decisionButtons(open)}
                        </>
                    }
                    onClose={() => setOpenId(null)}
                />
            )}

            {decision?.kind === 'edit' && decision.line.case && (
                <EditLine
                    line={decision.line}
                    url={adminCases.editLine(decision.line.case.id).url}
                    onClose={() => setDecision(null)}
                />
            )}
            {decision?.kind === 'approve' && decision.line.transaction && (
                <ApproveFromLine
                    line={decision.line}
                    url={deposits.approve(decision.line.transaction.id).url}
                    onClose={() => setDecision(null)}
                />
            )}
            {decision?.kind === 'decline' && decision.line.transaction && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setDecision(null)}
                    title={`Decline ${decision.line.transaction.reference}?`}
                    description={
                        <>
                            {decision.line.case?.notes} The deposit is declined
                            and the partner told. This bank line stays
                            unsettled: when the customer submits UTR{' '}
                            <b className="font-mono">{decision.line.utr}</b> on
                            a deposit of {formatPaise(decision.line.amount)}, it
                            is matched and approved.
                        </>
                    }
                    confirmLabel="Decline deposit"
                    tone="danger"
                    input={{ label: 'Details (optional, kept in the history)' }}
                    onConfirm={(details) =>
                        router.post(
                            deposits.decline(decision.line.transaction!.id).url,
                            {
                                reason_code:
                                    'wrong_amount' in props.reasons
                                        ? 'wrong_amount'
                                        : 'other',
                                note:
                                    [decision.line.case?.notes, details]
                                        .filter(Boolean)
                                        .join(' ')
                                        .slice(0, 500) || null,
                            },
                            {
                                preserveScroll: true,
                                onFinish: () => setDecision(null),
                            },
                        )
                    }
                />
            )}
            {decision?.kind === 'hold' && decision.line.transaction && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setDecision(null)}
                    title={`Hold ${decision.line.transaction.reference}?`}
                    description="Moves the deposit to Payment hold while you check. You can approve or decline it later."
                    confirmLabel="Hold"
                    tone="warning"
                    input={{
                        label: 'Reason (kept in the history)',
                        required: true,
                    }}
                    onConfirm={(reason) =>
                        router.post(
                            deposits.hold(decision.line.transaction!.id).url,
                            { reason },
                            {
                                preserveScroll: true,
                                onFinish: () => setDecision(null),
                            },
                        )
                    }
                />
            )}
        </>
    );
}

/**
 * Admin only (client request 2026-11-14): correct the UTR / amount of an
 * unsettled line; it is matched again straight away.
 */
function EditLine({
    line,
    url,
    onClose,
}: {
    line: StatementLine;
    url: string;
    onClose: () => void;
}) {
    const form = useForm({
        utr: line.utr ?? '',
        amount: (line.amount / 100).toFixed(2),
        note: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            title="Correct this bank line"
            description={`${line.account.bank ?? line.account.label} · ${formatDate(line.value_date)}. Change the UTR or the amount to what the bank statement shows. The line is matched again at once: if a waiting deposit has this UTR and amount, it is approved. A UTR already on another line is refused.`}
            submitLabel="Save and match"
            processing={form.processing}
            onSubmit={() =>
                form.post(url, { preserveScroll: true, onSuccess: onClose })
            }
        >
            <Field label="UTR / reference" error={errors.utr ?? errors.case}>
                <TextInput
                    className="font-mono"
                    value={form.data.utr}
                    onChange={(event) => form.setData('utr', event.target.value)}
                />
            </Field>
            <Field
                label={`${line.direction === 'credit' ? 'Credit' : 'Debit'} ₹`}
                error={errors.amount}
            >
                <TextInput
                    required
                    inputMode="decimal"
                    value={form.data.amount}
                    onChange={(event) =>
                        form.setData('amount', event.target.value)
                    }
                />
            </Field>
            <Field label="Why (kept in the history)" error={errors.note}>
                <TextInput
                    required
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                />
            </Field>
        </FormDialog>
    );
}

/** Approve the deposit a line matched, with the line's UTR as bank UTR. */
function ApproveFromLine({
    line,
    url,
    onClose,
}: {
    line: StatementLine;
    url: string;
    onClose: () => void;
}) {
    const txn = line.transaction!;
    const form = useForm({ bank_utr: line.utr ?? '', note: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            title={`Approve ${formatPaise(txn.amount)}?`}
            description={`${txn.reference} · ${txn.partner}. The bank statement shows this credit on ${formatDate(line.value_date)}. The partner is credited immediately and this can’t be undone.`}
            submitLabel="Approve"
            processing={form.processing}
            onSubmit={() =>
                form.post(url, { preserveScroll: true, onSuccess: onClose })
            }
        >
            <Field
                label="Bank UTR (from the statement line)"
                error={errors.bank_utr ?? errors.status}
            >
                <TextInput
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

function LineDrawer({
    line,
    detail,
    actions,
    onClose,
}: {
    line: StatementLine;
    detail: Detail | null;
    actions: ReactNode;
    onClose: () => void;
}) {
    const [tab, setTab] = useState('overview');
    const txn = line.transaction;

    return (
        <Drawer
            open
            onOpenChange={(next) => !next && onClose()}
            kind="A/C statement line"
            title={line.utr ?? line.description ?? 'Statement line'}
            monoTitle={line.utr !== null}
            status={
                <StatusBadge
                    status={line.display}
                    label={DISPLAY_LABELS[line.display]}
                />
            }
            subtitle={`Document date ${formatDate(line.value_date)} · ${line.account.bank ?? line.account.label} · ${line.source === 'import' ? 'imported' : 'entered'} ${formatDateTime(line.created_at)} by ${line.entered_by ?? '—'}`}
            actions={actions}
            summaries={[
                {
                    label: 'Credit',
                    value:
                        line.direction === 'credit' ? (
                            <LineAmount line={line} />
                        ) : (
                            '—'
                        ),
                },
                {
                    label: 'Debit',
                    value:
                        line.direction === 'debit' ? (
                            <LineAmount line={line} />
                        ) : (
                            '—'
                        ),
                },
                {
                    label: 'Transaction amount',
                    value: txn ? formatPaise(txn.amount) : 'Unlinked',
                },
            ]}
            tabs={[
                { key: 'overview', label: 'Overview' },
                { key: 'cases', label: 'Cases' },
                { key: 'audit', label: 'Audit' },
            ]}
            activeTab={tab}
            onTabChange={setTab}
        >
            {tab === 'overview' && (
                <>
                    <CompareBlock
                        transactionBranch={txn?.branch ?? null}
                        transactionSub={
                            txn
                                ? `${line.linked ? 'From' : 'Points at'} ${txn.direction === 'payout' ? 'payout' : 'pay-in'} ${txn.reference}`
                                : 'No linked transaction'
                        }
                        lineBranch={line.branch.name}
                        lineSub={`Entered by ${line.entered_by ?? '—'}`}
                        state={line.branch_match}
                    />
                    {line.case?.status === 'open' && (
                        <div className="rounded-lg bg-hdb px-3 py-2.5 text-[12.5px] text-hd">
                            <b>{line.case.type_label}</b> · {line.case.notes}
                        </div>
                    )}
                    <Section title="Statement information">
                        <KeyValues
                            items={[
                                {
                                    label: 'UTR / reference',
                                    value: line.utr,
                                    mono: true,
                                },
                                {
                                    label: 'Description',
                                    value: line.description,
                                },
                                {
                                    label: 'Document date',
                                    value: formatDate(line.value_date),
                                },
                                {
                                    label: 'Account',
                                    value: accountLabel({
                                        ...line.account,
                                        branch: line.branch.name,
                                    }),
                                },
                                {
                                    label: 'Source',
                                    value:
                                        line.source === 'import'
                                            ? `Import · ${line.file ?? '—'}`
                                            : 'Typed in',
                                },
                            ]}
                        />
                    </Section>
                    <Section title="Bank & transaction">
                        <KeyValues
                            items={[
                                {
                                    label: 'Transaction',
                                    value: txn?.reference,
                                    mono: true,
                                },
                                {
                                    label: 'Status',
                                    value: txn && (
                                        <StatusBadge status={txn.status} />
                                    ),
                                },
                                {
                                    label: 'Customer UTR',
                                    value: txn?.customer_utr,
                                    mono: true,
                                },
                                {
                                    label: 'Bank UTR (verified)',
                                    value: txn?.bank_utr,
                                    mono: true,
                                },
                                {
                                    label: 'Action by',
                                    value: txn?.decided_by,
                                },
                                {
                                    label: 'Matched',
                                    value: formatDateTime(line.matched_at),
                                },
                            ]}
                        />
                    </Section>
                </>
            )}
            {tab === 'cases' &&
                (!detail ? (
                    <Loading />
                ) : detail.cases.length === 0 ? (
                    <p className="text-xs text-tx3">
                        No cases: the line matched cleanly.
                    </p>
                ) : (
                    detail.cases.map((item) => (
                        <div
                            key={item.id}
                            className="flex flex-col gap-1 rounded-lg border border-ln p-3 text-[12.5px]"
                        >
                            <div className="flex justify-between gap-2">
                                <span>
                                    <span className="font-mono text-xs">
                                        {item.reference}
                                    </span>{' '}
                                    · <b>{item.type_label}</b>
                                </span>
                                <StatusBadge status={item.status} />
                            </div>
                            <div className="whitespace-pre-line text-tx2">
                                {item.notes}
                            </div>
                            <div className="text-xs text-tx3">
                                Opened {formatDateTime(item.created_at)}
                                {item.resolution_label &&
                                    ` · ${item.resolution_label} by ${item.resolved_by} ${formatDateTime(item.resolved_at)}`}
                            </div>
                        </div>
                    ))
                ))}
            {tab === 'audit' &&
                (!detail ? (
                    <Loading />
                ) : (
                    detail.audit.map((entry, index) => (
                        <div
                            key={index}
                            className="rounded-lg border border-ln p-3 text-xs"
                        >
                            <div className="flex justify-between">
                                <span>
                                    <b>{entry.actor}</b> —{' '}
                                    <span className="font-mono">
                                        {entry.action}
                                    </span>
                                </span>
                                <span className="text-tx3">
                                    {formatDateTime(entry.at)}
                                </span>
                            </div>
                            <div className="mt-1 font-mono text-[11px] break-all text-tx3">
                                {JSON.stringify(entry.new)}
                            </div>
                        </div>
                    ))
                ))}
        </Drawer>
    );
}

function Pairs({ rows }: { rows: [string, ReactNode][] }) {
    return (
        <dl className="grid grid-cols-[auto_1fr] gap-x-2.5 gap-y-0.5 text-xs whitespace-nowrap">
            {rows.map(([label, value]) => (
                <div key={label} className="contents">
                    <dt className="text-tx3">{label}</dt>
                    <dd>{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-2.5">
            <h3 className="text-[12px] font-semibold tracking-[.04em] text-tx3 uppercase">
                {title}
            </h3>
            {children}
        </section>
    );
}

function Loading() {
    return <div className="py-10 text-center text-xs text-tx3">Loading…</div>;
}
