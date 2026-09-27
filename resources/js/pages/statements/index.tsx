import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, History, Search, Upload } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
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
import { PageHeader } from '@/components/pg/page-header';
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
import {
    accountLabel,
    StatementImportDialog,
} from '@/components/pg/statement-import-dialog';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
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
    branch: string | null;
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
    items: {
        data: StatementLine[];
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Filters;
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
        unsettled: number;
    };
    accounts: Account[];
    branches: { id: string; code: string; name: string }[];
    can: { create: boolean; resolve: boolean };
    selected: string | null;
    detail?: Detail | null;
};

const TABS: [string, string][] = [
    ['all', 'All'],
    ['pending', 'Pending'],
    ['approved', 'Approved'],
    ['unsettled', 'Unsettled'],
    ['ignored', 'Closed'],
];

type Decision = null | { kind: 'approve' | 'hold'; line: StatementLine };

/**
 * Manual A/C Statement (Admin) / A/C Statement Entry (branch), as designed:
 * KPIs, a one-line entry form, filters, the dense lines table and the line
 * drawer with the branch compare block.
 */
export default function Statements(props: Props) {
    const { portal, items, filters, counts, kpis, accounts, branches, can } =
        props;
    const routes = portal === 'admin' ? adminStatements : branchStatements;
    const deposits = portal === 'admin' ? adminDeposits : branchDeposits;
    const cases = portal === 'admin' ? adminCases : branchCases;
    const imports = portal === 'admin' ? adminImports : branchImports;
    const [search, setSearch] = useState(filters.search);
    const [openId, setOpenId] = useState<string | null>(props.selected);
    const [importing, setImporting] = useState(false);
    const [decision, setDecision] = useState<Decision>(null);
    const open = items.data.find((line) => line.id === openId) ?? null;

    const query = (next: Partial<Filters> = {}) =>
        Object.fromEntries(
            Object.entries({ ...filters, search, ...next }).filter(
                ([, value]) => value,
            ),
        ) as Record<string, string>;
    const visit = (next: Partial<Filters>) =>
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
        {
            key: 'debit',
            header: 'Debit',
            align: 'right',
            cell: (line) =>
                line.direction === 'debit' ? (
                    <LineAmount line={line} />
                ) : (
                    <span className="text-tx3">—</span>
                ),
        },
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
                        ['Bank entry', line.branch.code],
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
                </div>
            ),
        },
    ];

    return (
        <>
            <Head
                title={
                    portal === 'admin'
                        ? 'Manual A/C Statement'
                        : 'A/C Statement Entry'
                }
            />
            <PageHeader
                title={
                    portal === 'admin'
                        ? 'Manual A/C Statement Entry'
                        : 'A/C Statement Entry'
                }
                description="Record bank statement lines and reconcile them against pay-in transactions and branches."
                actions={
                    <>
                        <Link
                            href={imports.index().url}
                            className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                        >
                            <History className="size-3.5" /> History
                        </Link>
                        {can.create && (
                            <PgButton onClick={() => setImporting(true)}>
                                <Upload className="size-3.5" /> Import statement
                            </PgButton>
                        )}
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
                    label="Unsettled · no linked transaction"
                    value={formatPaise(kpis.unsettled)}
                    icon="!"
                    tone="hd"
                />
            </KpiGrid>

            {can.create && (
                <NewLine
                    portal={portal}
                    accounts={accounts}
                    branches={branches}
                />
            )}

            <Panel className="overflow-hidden">
                <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] items-center gap-1.5 rounded-[7px] border border-ln px-2.5 text-[12.5px]">
                        <span className="text-tx3">Period</span>
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
                                visit({
                                    branch: event.target.value || null,
                                    account: null,
                                })
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
                                    !filters.branch ||
                                    account.branch_id === filters.branch,
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
                            onRowClick={(line) => openLine(line.id)}
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
                                    className="inline-flex h-8 items-center rounded-[7px] bg-ac px-3 text-[13px] font-medium text-white"
                                >
                                    Match to transaction
                                </Link>
                            )}
                            {decisionButtons(open)}
                        </>
                    }
                    onClose={() => setOpenId(null)}
                />
            )}

            <StatementImportDialog
                portal={portal}
                accounts={accounts}
                open={importing}
                onOpenChange={setImporting}
            />

            {decision?.kind === 'approve' && decision.line.transaction && (
                <ApproveFromLine
                    line={decision.line}
                    url={deposits.approve(decision.line.transaction.id).url}
                    onClose={() => setDecision(null)}
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

/** Design "New statement line": credit or debit, one value per line. */
function NewLine({
    portal,
    accounts,
    branches,
}: {
    portal: UserType;
    accounts: Account[];
    branches: Props['branches'];
}) {
    const routes = portal === 'admin' ? adminStatements : branchStatements;
    const today = useMemo(
        () =>
            new Intl.DateTimeFormat('en-CA', {
                timeZone: 'Asia/Kolkata',
            }).format(new Date()),
        [],
    );
    const [branch, setBranch] = useState('');
    const form = useForm({
        payment_account_id: accounts.length === 1 ? accounts[0].id : '',
        value_date: today,
        utr: '',
        description: '',
        credit: '',
        debit: '',
    });
    const shown = accounts.filter(
        (account) => !branch || account.branch_id === branch,
    );

    return (
        <Panel className="px-3.5 py-3">
            <div className="mb-2.5 flex items-center gap-2">
                <span className="text-[13.5px] font-semibold">
                    New statement line
                </span>
                <span className="text-xs text-tx3">
                    Credit or debit — one value per line
                </span>
            </div>
            <form
                className="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] items-end gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(routes.store().url, {
                        preserveScroll: true,
                        onSuccess: () =>
                            form.reset('utr', 'description', 'credit', 'debit'),
                    });
                }}
            >
                {portal === 'admin' && (
                    <Field label="Branch">
                        <SelectInput
                            className="h-[34px] text-[13px]"
                            value={branch}
                            onChange={(event) => {
                                setBranch(event.target.value);
                                form.setData('payment_account_id', '');
                            }}
                        >
                            <option value="">All</option>
                            {branches.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.code}
                                </option>
                            ))}
                        </SelectInput>
                    </Field>
                )}
                <Field
                    label="Bank account"
                    error={form.errors.payment_account_id}
                >
                    <SelectInput
                        required
                        className="h-[34px] text-[13px]"
                        value={form.data.payment_account_id}
                        onChange={(event) =>
                            form.setData(
                                'payment_account_id',
                                event.target.value,
                            )
                        }
                    >
                        <option value="">Choose…</option>
                        {shown.map((account) => (
                            <option key={account.id} value={account.id}>
                                {accountLabel(account)}
                            </option>
                        ))}
                    </SelectInput>
                </Field>
                <Field label="Date" error={form.errors.value_date}>
                    <TextInput
                        type="date"
                        required
                        max={today}
                        className="h-[34px] text-[13px]"
                        value={form.data.value_date}
                        onChange={(event) =>
                            form.setData('value_date', event.target.value)
                        }
                    />
                </Field>
                <Field label="UTR / reference" error={form.errors.utr}>
                    <TextInput
                        className="h-[34px] font-mono text-[13px]"
                        placeholder="e.g. 626812820491"
                        value={form.data.utr}
                        onChange={(event) =>
                            form.setData('utr', event.target.value)
                        }
                    />
                </Field>
                <Field label="Description" error={form.errors.description}>
                    <TextInput
                        className="h-[34px] text-[13px]"
                        placeholder="As on the statement"
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                    />
                </Field>
                <Field label="Credit ₹" error={form.errors.credit}>
                    <TextInput
                        inputMode="decimal"
                        className="h-[34px] text-[13px]"
                        placeholder="0.00"
                        value={form.data.credit}
                        disabled={form.data.debit !== ''}
                        onChange={(event) =>
                            form.setData('credit', event.target.value)
                        }
                    />
                </Field>
                <Field label="Debit ₹" error={form.errors.debit}>
                    <TextInput
                        inputMode="decimal"
                        className="h-[34px] text-[13px]"
                        placeholder="0.00"
                        value={form.data.debit}
                        disabled={form.data.credit !== ''}
                        onChange={(event) =>
                            form.setData('debit', event.target.value)
                        }
                    />
                </Field>
                <PgButton
                    type="submit"
                    variant="primary"
                    className="h-[34px] justify-center"
                    disabled={form.processing}
                >
                    Add line
                </PgButton>
            </form>
        </Panel>
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
                        lineBranch={line.branch.code}
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
                                        branch: line.branch.code,
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
