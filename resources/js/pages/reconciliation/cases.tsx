import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { ConfirmDialog } from '@/components/pg/confirm-dialog';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { Drawer } from '@/components/pg/drawer';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { ViewTabs } from '@/components/pg/filter-bar';
import { FormDialog } from '@/components/pg/form-dialog';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import {
    CompareBlock,
    LineAmount,
    LineSummary,
    MATCH_LABELS,
} from '@/components/pg/reconciliation';
import type {
    CaseSummary,
    LineTxn,
    StatementLine,
} from '@/components/pg/reconciliation';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDate, formatDateTime, formatRelative } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import adminCases from '@/routes/admin/cases';
import adminStatements from '@/routes/admin/statements';
import branchCases from '@/routes/branch/cases';
import branchStatements from '@/routes/branch/statements';
import type { UserType } from '@/types';

type CaseRow = CaseSummary & {
    branch: { code: string; name: string };
    entry: StatementLine | null;
    resolved_by: string | null;
    resolved_at: string | null;
    created_at: string | null;
    can: { resolve: boolean; approve_late: boolean };
};

type Candidate = LineTxn & { late: boolean; same_amount: boolean };

type Props = {
    portal: UserType;
    tab: 'open' | 'resolved';
    cases: {
        data: CaseRow[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { type: string | null; branch: string | null; search: string };
    counts: { open: number; resolved: number; types: Record<string, number> };
    kpis: {
        credit: number;
        credit_count: number;
        debit: number;
        debit_count: number;
        resolved_today: number;
    };
    types: Record<string, string>;
    branches: { id: string; code: string; name: string }[];
    selected: string | null;
    candidates?: Candidate[];
};

type Action =
    | null
    | { kind: 'link'; txn: Candidate }
    | { kind: 'late'; txn: Candidate }
    | { kind: 'close'; resolution: 'rejected' | 'refunded' };

/**
 * Unsettled UTR (Admin) / Deposit Unsettled (branch): the reconciliation
 * case queue. Each open case is a bank line that didn't match cleanly;
 * the drawer offers the transactions it could belong to.
 */
export default function Cases(props: Props) {
    const { portal, tab, cases, filters, counts, kpis, types, branches } =
        props;
    const routes = portal === 'admin' ? adminCases : branchCases;
    const statements = portal === 'admin' ? adminStatements : branchStatements;
    const [search, setSearch] = useState(filters.search);
    const [openId, setOpenId] = useState<string | null>(props.selected);
    const open = cases.data.find((item) => item.id === openId) ?? null;

    const visit = (next: Record<string, string | null>) =>
        router.get(
            routes.index({
                query: Object.fromEntries(
                    Object.entries({ tab, ...filters, search, ...next }).filter(
                        ([, value]) => value,
                    ),
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

    useEffect(() => {
        if (props.selected && !props.candidates)
            router.reload({ only: ['candidates'] });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const openCase = (id: string) => {
        setOpenId(id);
        router.reload({
            only: ['candidates', 'selected'],
            data: { case: id, find: null },
        });
    };

    const columns: Column<CaseRow>[] = [
        {
            key: 'case',
            header: 'Case',
            cell: (item) => (
                <div>
                    <div className="font-mono text-xs">{item.reference}</div>
                    <span className="mt-0.5 inline-block rounded-md bg-hdb px-1.5 py-px text-[11.5px] font-medium text-hd">
                        {item.type_label}
                    </span>
                </div>
            ),
        },
        {
            key: 'line',
            header: 'Bank line',
            cell: (item) =>
                item.entry && (
                    <div className="max-w-[240px]">
                        <LineAmount line={item.entry} />
                        <div className="font-mono text-xs">
                            {item.entry.utr ?? 'no UTR'}
                        </div>
                        {item.entry.description && (
                            <div className="truncate text-xs">
                                {item.entry.description}
                            </div>
                        )}
                        <div className="truncate text-xs text-tx3">
                            {formatDate(item.entry.value_date)} ·{' '}
                            {item.entry.account.label}
                        </div>
                    </div>
                ),
        },
        {
            key: 'txn',
            header: 'Transaction',
            cell: (item) =>
                item.entry?.transaction ? (
                    <div>
                        <div className="font-mono text-xs">
                            {item.entry.transaction.reference}
                        </div>
                        <div className="text-xs text-tx3">
                            {formatPaise(item.entry.transaction.amount)} ·{' '}
                            {item.entry.transaction.branch}
                        </div>
                    </div>
                ) : (
                    <span className="text-xs text-tx3">none found</span>
                ),
        },
        ...(portal === 'admin'
            ? [
                  {
                      key: 'branch',
                      header: 'Bank entry branch',
                      cell: (item: CaseRow) => (
                          <span className="rounded-md bg-sf2 px-2 py-0.5 text-xs">
                              {item.branch.code}
                          </span>
                      ),
                  },
              ]
            : []),
        {
            key: 'match',
            header: 'Branch match',
            cell: (item) =>
                item.entry && (
                    <StatusBadge
                        status={item.entry.branch_match}
                        label={MATCH_LABELS[item.entry.branch_match]}
                    />
                ),
        },
        {
            key: 'notes',
            header: 'Why',
            cell: (item) => (
                <div className="max-w-[300px] text-xs whitespace-pre-line text-tx2">
                    {item.notes}
                </div>
            ),
        },
        {
            key: 'when',
            header: tab === 'open' ? 'Waiting' : 'Resolution',
            cell: (item) =>
                tab === 'open' ? (
                    <span className="text-xs whitespace-nowrap">
                        {formatRelative(item.created_at)}
                    </span>
                ) : (
                    <div className="text-xs">
                        <div className="font-medium">
                            {item.resolution_label}
                        </div>
                        <div className="text-tx3">
                            {item.resolved_by} ·{' '}
                            {formatDateTime(item.resolved_at)}
                        </div>
                    </div>
                ),
        },
        {
            key: 'action',
            header: '',
            align: 'right',
            cell: (item) =>
                item.can.resolve && (
                    <PgButton
                        variant="primary"
                        className="h-7 px-2 text-xs"
                        onClick={(event) => {
                            event.stopPropagation();
                            openCase(item.id);
                        }}
                    >
                        Resolve
                    </PgButton>
                ),
        },
    ];

    return (
        <>
            <Head
                title={
                    portal === 'admin' ? 'Unsettled UTR' : 'Deposit Unsettled'
                }
            />
            <PageHeader
                title={
                    portal === 'admin' ? 'Unsettled UTR' : 'Deposit Unsettled'
                }
                description="Bank statement lines that didn’t match a transaction exactly (same UTR, amount and account). Link each to its transaction, or close it with the reason."
                actions={
                    <Link
                        href={statements.index().url}
                        className="inline-flex h-8 items-center rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                    >
                        Statement lines
                    </Link>
                }
            />

            <KpiGrid>
                <StatTile
                    label="Open cases"
                    value={String(counts.open)}
                    icon="!"
                    tone="hd"
                />
                <StatTile
                    label={`Money received · ${kpis.credit_count} lines`}
                    value={formatPaise(kpis.credit)}
                    icon="+"
                    tone="ok"
                />
                <StatTile
                    label={`Money paid out · ${kpis.debit_count} lines`}
                    value={formatPaise(kpis.debit)}
                    icon="−"
                    tone="er"
                />
                <StatTile
                    label="Resolved today"
                    value={String(kpis.resolved_today)}
                    icon="✓"
                    tone="ok"
                />
            </KpiGrid>

            <Panel className="overflow-hidden">
                <ViewTabs
                    views={[
                        { key: 'open', label: 'Open', count: counts.open },
                        {
                            key: 'resolved',
                            label: 'Resolved',
                            count: counts.resolved,
                        },
                    ]}
                    active={tab}
                    onChange={(key) => visit({ tab: key })}
                />
                <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <SelectInput
                        className="h-[30px] w-[240px] text-[12.5px]"
                        value={filters.type ?? ''}
                        onChange={(event) =>
                            visit({ type: event.target.value || null })
                        }
                    >
                        <option value="">All reasons</option>
                        {Object.entries(types).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                                {counts.types[key]
                                    ? ` (${counts.types[key]} open)`
                                    : ''}
                            </option>
                        ))}
                    </SelectInput>
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
                            placeholder="Case, UTR or transaction id"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">
                        {cases.total} {tab === 'open' ? '· oldest first' : ''}
                    </span>
                </div>
                <div className="overflow-x-auto">
                    <DataTable
                        columns={columns}
                        rows={cases.data}
                        rowKey={(item) => item.id}
                        onRowClick={(item) => openCase(item.id)}
                        empty={
                            <EmptyState
                                title={
                                    tab === 'open'
                                        ? 'Nothing unsettled'
                                        : 'No resolved cases'
                                }
                                description={
                                    tab === 'open'
                                        ? 'Every statement line matched its transaction.'
                                        : undefined
                                }
                            />
                        }
                    />
                </div>
                {(cases.prev_page_url || cases.next_page_url) && (
                    <div className="flex justify-end gap-2 px-3 py-2.5 text-xs">
                        {cases.prev_page_url && (
                            <Link
                                href={cases.prev_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                ‹ Previous
                            </Link>
                        )}
                        {cases.next_page_url && (
                            <Link
                                href={cases.next_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                Next ›
                            </Link>
                        )}
                    </div>
                )}
            </Panel>

            {open && (
                <CaseDrawer
                    portal={portal}
                    item={open}
                    candidates={props.candidates ?? null}
                    onClose={() => setOpenId(null)}
                />
            )}
        </>
    );
}

function CaseDrawer({
    portal,
    item,
    candidates,
    onClose,
}: {
    portal: UserType;
    item: CaseRow;
    candidates: Candidate[] | null;
    onClose: () => void;
}) {
    const routes = portal === 'admin' ? adminCases : branchCases;
    const [find, setFind] = useState('');
    const [action, setAction] = useState<Action>(null);
    const line = item.entry;
    const txn = line?.transaction ?? null;
    const isOpen = item.status !== 'resolved';
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const problem =
        errors.transaction ?? errors.case ?? errors.resolution ?? errors.note;

    const lookup = () =>
        router.reload({
            only: ['candidates'],
            data: { case: item.id, find: find || null },
        });

    return (
        <Drawer
            open
            onOpenChange={(next) => !next && onClose()}
            kind="Unsettled case"
            title={item.reference}
            monoTitle
            status={<StatusBadge status={item.status} />}
            subtitle={`${item.type_label} · ${item.branch.code} · opened ${formatDateTime(item.created_at)}`}
            summaries={[
                {
                    label: 'Bank line',
                    value: line ? <LineAmount line={line} /> : '—',
                },
                {
                    label: 'Transaction',
                    value: txn ? formatPaise(txn.amount) : 'None found',
                },
                { label: 'Reason', value: item.type_label },
            ]}
        >
            <div className="rounded-lg bg-hdb px-3 py-2.5 text-[12.5px] whitespace-pre-line text-hd">
                {item.notes}
            </div>
            {problem && (
                <div className="rounded-lg bg-erb px-3 py-2.5 text-[12.5px] text-er">
                    {problem}
                </div>
            )}
            {line && <LineSummary line={line} />}
            {line && (
                <CompareBlock
                    transactionBranch={txn?.branch ?? null}
                    transactionSub={
                        txn ? `Points at ${txn.reference}` : 'No transaction'
                    }
                    lineBranch={line.branch.code}
                    lineSub={line.account.label}
                    state={line.branch_match}
                />
            )}

            {!isOpen && (
                <div className="rounded-lg bg-okb px-3 py-2.5 text-[12.5px] text-ok">
                    {item.resolution_label} · {item.resolved_by} ·{' '}
                    {formatDateTime(item.resolved_at)}
                </div>
            )}

            {isOpen && item.can.resolve && line && (
                <>
                    <section className="flex flex-col gap-2.5">
                        <h3 className="text-[12px] font-semibold tracking-[.04em] text-tx3 uppercase">
                            Link to a{' '}
                            {line.direction === 'credit' ? 'deposit' : 'payout'}
                        </h3>
                        <p className="text-xs text-tx3">
                            {line.direction === 'credit'
                                ? 'Deposits on this account for exactly this amount, around the line’s date. Only an exact amount can be linked.'
                                : 'Paid payouts of this branch for exactly this amount, around the line’s date.'}
                        </p>
                        <form
                            className="flex gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                lookup();
                            }}
                        >
                            <TextInput
                                className="h-8 font-mono text-[13px]"
                                placeholder="Or find by transaction id, order id or UTR"
                                value={find}
                                onChange={(event) =>
                                    setFind(event.target.value)
                                }
                            />
                            <PgButton type="submit">Find</PgButton>
                        </form>
                        {candidates === null ? (
                            <p className="py-4 text-center text-xs text-tx3">
                                Loading…
                            </p>
                        ) : candidates.length === 0 ? (
                            <p className="rounded-lg border border-dashed border-ln px-3 py-4 text-center text-xs text-tx3">
                                No matching transaction found.
                            </p>
                        ) : (
                            candidates.map((candidate) => (
                                <div
                                    key={candidate.id}
                                    className="flex items-center justify-between gap-3 rounded-lg border border-ln p-3 text-[12.5px]"
                                >
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <span className="font-mono text-xs font-medium">
                                                {candidate.reference}
                                            </span>
                                            <StatusBadge
                                                status={candidate.status}
                                            />
                                        </div>
                                        <div className="text-xs text-tx3">
                                            <span
                                                className={
                                                    candidate.same_amount
                                                        ? ''
                                                        : 'text-er'
                                                }
                                            >
                                                {formatPaise(candidate.amount)}
                                            </span>{' '}
                                            · {candidate.partner} · created{' '}
                                            {formatDateTime(
                                                candidate.created_at,
                                            )}
                                        </div>
                                        <div className="font-mono text-xs text-tx3">
                                            UTR{' '}
                                            {candidate.bank_utr ??
                                                candidate.customer_utr ??
                                                '—'}
                                        </div>
                                    </div>
                                    {candidate.late ? (
                                        item.can.approve_late ? (
                                            <PgButton
                                                variant="primary"
                                                className="h-7 px-2 text-xs"
                                                onClick={() =>
                                                    setAction({
                                                        kind: 'late',
                                                        txn: candidate,
                                                    })
                                                }
                                            >
                                                Approve late
                                            </PgButton>
                                        ) : (
                                            <span className="text-right text-xs text-tx3">
                                                {candidate.status}: ask an
                                                administrator
                                            </span>
                                        )
                                    ) : (
                                        <PgButton
                                            variant="primary"
                                            className="h-7 px-2 text-xs"
                                            onClick={() =>
                                                setAction({
                                                    kind: 'link',
                                                    txn: candidate,
                                                })
                                            }
                                        >
                                            Link
                                        </PgButton>
                                    )}
                                </div>
                            ))
                        )}
                    </section>

                    <section className="flex flex-col gap-2.5">
                        <h3 className="text-[12px] font-semibold tracking-[.04em] text-tx3 uppercase">
                            Or close it
                        </h3>
                        <div className="flex flex-wrap gap-2">
                            <PgButton
                                onClick={() =>
                                    setAction({
                                        kind: 'close',
                                        resolution: 'rejected',
                                    })
                                }
                            >
                                Not a customer payment
                            </PgButton>
                            {line.direction === 'credit' && (
                                <PgButton
                                    onClick={() =>
                                        setAction({
                                            kind: 'close',
                                            resolution: 'refunded',
                                        })
                                    }
                                >
                                    Returned to customer
                                </PgButton>
                            )}
                        </div>
                    </section>
                </>
            )}

            {action?.kind === 'link' && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setAction(null)}
                    title={`Link this line to ${action.txn.reference}?`}
                    description={
                        action.txn.status === 'success'
                            ? 'The approved transaction becomes reconciled with this bank line.'
                            : 'The deposit is marked as found in the bank. It still has to be approved in Manual Deposit or from the statement line.'
                    }
                    confirmLabel="Link"
                    input={{ label: 'Note (optional)' }}
                    onConfirm={(note) =>
                        router.post(
                            routes.link(item.id).url,
                            { transaction_id: action.txn.id, note },
                            {
                                preserveScroll: true,
                                onFinish: () => setAction(null),
                                onSuccess: onClose,
                            },
                        )
                    }
                />
            )}
            {action?.kind === 'late' && line && (
                <ApproveLate
                    url={adminCases.approveLate(item.id).url}
                    txn={action.txn}
                    line={line}
                    onClose={() => setAction(null)}
                    onDone={onClose}
                />
            )}
            {action?.kind === 'close' && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setAction(null)}
                    title={
                        action.resolution === 'rejected'
                            ? 'Close as not a customer payment?'
                            : 'Close as returned to the customer?'
                    }
                    description={
                        action.resolution === 'rejected'
                            ? 'For bank charges, interest, own transfers or a line typed by mistake. The line stays in the statement, marked closed.'
                            : 'The branch sent this money back to the customer outside PayGate. Nothing is credited to any partner.'
                    }
                    confirmLabel="Close case"
                    tone="warning"
                    input={{ label: 'Note (required)', required: true }}
                    onConfirm={(note) =>
                        router.post(
                            routes.close(item.id).url,
                            { resolution: action.resolution, note },
                            {
                                preserveScroll: true,
                                onFinish: () => setAction(null),
                                onSuccess: onClose,
                            },
                        )
                    }
                />
            )}
        </Drawer>
    );
}

/** Admin: approve an expired / declined deposit whose money arrived (G-25). */
function ApproveLate({
    url,
    txn,
    line,
    onClose,
    onDone,
}: {
    url: string;
    txn: Candidate;
    line: StatementLine;
    onClose: () => void;
    onDone: () => void;
}) {
    const form = useForm({
        transaction_id: txn.id,
        bank_utr: line.utr ?? '',
        note: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            title={`Approve ${txn.reference} late?`}
            description={`The deposit is ${txn.status}, but the bank shows ${formatPaise(line.amount)} on ${formatDate(line.value_date)}. Approving credits the partner now and sends payin.success with “late”. This can’t be undone.`}
            submitLabel="Approve late"
            processing={form.processing}
            onSubmit={() =>
                form.post(url, {
                    preserveScroll: true,
                    onSuccess: () => {
                        onClose();
                        onDone();
                    },
                })
            }
        >
            {(errors.transaction || errors.status) && (
                <div className="rounded-lg bg-erb px-3 py-2.5 text-[12.5px] text-er">
                    {errors.transaction ?? errors.status}
                </div>
            )}
            <Field label="Bank UTR" error={errors.bank_utr}>
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
