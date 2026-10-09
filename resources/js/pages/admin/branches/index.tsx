import { Head, Link, router, useForm } from '@inertiajs/react';
import { History, Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { PgButton } from '@/components/pg/button';
import { ConfirmDialog } from '@/components/pg/confirm-dialog';
import { Panel } from '@/components/pg/data-table';
import { Drawer, KeyValues } from '@/components/pg/drawer';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { ViewTabs } from '@/components/pg/filter-bar';
import { FormDialog } from '@/components/pg/form-dialog';
import { LimitsDialog } from '@/components/pg/limits-dialog';
import type { LimitValues } from '@/components/pg/limits-dialog';
import { PageHeader } from '@/components/pg/page-header';
import { SimpleTable } from '@/components/pg/simple-table';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDateTime, formatRelative } from '@/lib/dates';
import { formatLimit, formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import branchesRoutes from '@/routes/admin/branches';
import topupsRoutes from '@/routes/admin/branches/topups';

type Row = {
    id: string;
    code: string;
    name: string;
    status: string;
    deposit_limit_type: string;
    deposit_topup_balance: number;
    is_deposit_enabled: boolean;
    is_withdrawal_enabled: boolean;
    deposit_daily_limit: number | null;
    withdrawal_daily_limit: number | null;
    withdrawal_min_amount: number | null;
    withdrawal_max_amount: number | null;
    limits: LimitValues;
    rates: { deposit?: string; withdrawal?: string };
    today: { deposit: number; withdrawal: number };
    active_accounts: number;
    partners_count: number;
    admins: string[];
};

type Detail = {
    id: string;
    limits: { deposit: (number | null)[]; withdrawal: (number | null)[] };
    verified_at: string | null;
    created_at: string | null;
    accounts: {
        label: string;
        holder: string;
        bank: string | null;
        upi: string | null;
        status: string;
    }[];
    partners: {
        code: string;
        name: string;
        status: string;
        deposit: boolean;
        withdrawal: boolean;
    }[];
    users: { name: string; role: string; status: string }[];
    topups: {
        amount: number;
        balance_after: number;
        reason: string;
        by: string;
        at: string;
    }[];
    rate_history: {
        direction: string;
        rate: string;
        from: string;
        to: string | null;
    }[];
    activity: {
        action: string;
        summary: string;
        changes: string[];
        actor: string;
        at: string;
        reason: string | null;
    }[];
    transitions: string[];
    blockers: string[];
};

type Props = {
    branches: {
        data: Row[];
        total: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { status: string | null; search: string };
    counts: Record<string, number>;
    selected: string | null;
    detail?: Detail | null;
    can: { create: boolean; update: boolean; users: boolean };
};

const TABS = [
    ['all', 'All'],
    ['active', 'Active'],
    ['draft', 'Draft'],
    ['suspended', 'Suspended'],
    ['offboarded', 'Offboarded'],
] as const;

const TRANSITIONS: Record<
    string,
    {
        label: string;
        tone: 'primary' | 'warning' | 'danger';
        description: string;
    }
> = {
    active: {
        label: 'Activate',
        tone: 'primary',
        description:
            'The branch’s active accounts start receiving customers of its mapped partners.',
    },
    suspended: {
        label: 'Suspend',
        tone: 'warning',
        description:
            'No new customers or payouts are sent to this branch until it is reactivated.',
    },
    offboarded: {
        label: 'Offboard',
        tone: 'danger',
        description:
            'The branch stops for good. History and balances are kept for settlement.',
    },
};

const pct = (rate?: string) => (rate ? `${Number(rate)}%` : '—');

export default function BranchesIndex({
    branches,
    filters,
    counts,
    selected,
    detail,
    can,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [openId, setOpenId] = useState<string | null>(selected);
    const [tab, setTab] = useState('overview');
    const [transition, setTransition] = useState<string | null>(null);
    const [topupOpen, setTopupOpen] = useState(false);
    const [limitsBranch, setLimitsBranch] = useState<Row | null>(null);
    const open = branches.data.find((b) => b.id === openId) ?? null;
    const loaded = detail && detail.id === openId ? detail : null;

    const query = (next: Record<string, string | null>) =>
        Object.fromEntries(
            Object.entries({ ...filters, search, ...next }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        );

    const openBranch = (id: string) => {
        setOpenId(id);
        setTab('overview');
        router.reload({ only: ['detail', 'selected'], data: { branch: id } });
    };

    useEffect(() => {
        if (selected && !detail) router.reload({ only: ['detail'] });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (search === filters.search) return;
        const timer = setTimeout(
            () =>
                router.get(
                    branchesRoutes.index({
                        query: query({ search, branch: null }),
                    }).url,
                    {},
                    { preserveState: true, replace: true },
                ),
            300,
        );

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <>
            <Head title="Branches" />

            <PageHeader
                title="Branches"
                description={`${counts.all ?? 0} branches · ${counts.active ?? 0} active. Limits, commissions and volumes for today (India time).`}
                actions={
                    <>
                        <Link
                            href={branchesRoutes.logs().url}
                            className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                        >
                            <History className="size-3.5" /> Activity
                        </Link>
                        {can.create && (
                            <Link
                                href={branchesRoutes.create().url}
                                className="inline-flex h-8 items-center gap-1.5 rounded-[7px] bg-brand px-3 text-[13px] font-medium text-white"
                            >
                                <Plus className="size-4" /> Create branch
                            </Link>
                        )}
                    </>
                }
            />

            <Panel className="overflow-hidden">
                <ViewTabs
                    views={TABS.map(([key, label]) => ({
                        key,
                        label,
                        count: counts[key] ?? 0,
                    }))}
                    active={filters.status ?? 'all'}
                    onChange={(key) =>
                        router.get(
                            branchesRoutes.index({
                                query: query({
                                    status: key === 'all' ? null : key,
                                }),
                            }).url,
                        )
                    }
                />
                <div className="flex items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] w-[260px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search name or code"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">
                        {branches.total} branches
                    </span>
                </div>
                {branches.data.length === 0 ? (
                    <EmptyState
                        title="No branches found"
                        description="Create a branch, then map it to partners and let it add its accounts."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-[13px]">
                            <thead>
                                <tr className="text-[11px] font-semibold tracking-[.03em] text-tx3 uppercase">
                                    <th colSpan={2} className="bg-sf2" />
                                    <th
                                        colSpan={3}
                                        className="border-b border-l border-ln2 bg-sf2 px-2.5 pt-2 text-left text-ok"
                                    >
                                        Deposit
                                    </th>
                                    <th
                                        colSpan={4}
                                        className="border-b border-l border-ln2 bg-sf2 px-2.5 pt-2 text-left text-in"
                                    >
                                        Withdrawal
                                    </th>
                                    <th
                                        colSpan={4}
                                        className="border-l border-ln2 bg-sf2"
                                    />
                                </tr>
                                <tr className="text-[11.5px] font-semibold tracking-[.03em] text-tx3 uppercase">
                                    {[
                                        'Branch',
                                        'Active accounts',
                                        'Daily limit',
                                        'Comm.',
                                        'Today',
                                        'Daily limit',
                                        'Comm.',
                                        'Per payout',
                                        'Today',
                                        'Partners',
                                        'Branch admin',
                                        'Status',
                                        '',
                                    ].map((header, index) => (
                                        <th
                                            key={index}
                                            className={cn(
                                                'border-b border-ln bg-sf2 px-2.5 py-2 text-left whitespace-nowrap',
                                                [2, 5, 9].includes(index) &&
                                                    'border-l border-ln2',
                                            )}
                                        >
                                            {header}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {branches.data.map((b) => (
                                    <tr
                                        key={b.id}
                                        onClick={() => openBranch(b.id)}
                                        className="cursor-pointer hover:bg-sf2"
                                    >
                                        <Td className="min-w-[210px]">
                                            <div className="font-medium whitespace-nowrap">
                                                {b.name}{' '}
                                                <span className="font-mono text-xs text-tx3">
                                                    {b.code}
                                                </span>
                                            </div>
                                            <div className="text-xs text-tx3">
                                                {b.deposit_limit_type ===
                                                'topup'
                                                    ? `Top-up balance · ${formatPaise(b.deposit_topup_balance, 0)} left`
                                                    : 'Daily limit · resets 00:00 IST'}
                                            </div>
                                        </Td>
                                        <Td>{b.active_accounts}</Td>
                                        <Td divider>
                                            {b.is_deposit_enabled ? (
                                                formatLimit(
                                                    b.deposit_daily_limit,
                                                )
                                            ) : (
                                                <Off />
                                            )}
                                        </Td>
                                        <Td>{pct(b.rates.deposit)}</Td>
                                        <Td className="font-medium">
                                            {formatPaise(b.today.deposit, 0)}
                                        </Td>
                                        <Td divider>
                                            {b.is_withdrawal_enabled ? (
                                                formatLimit(
                                                    b.withdrawal_daily_limit,
                                                )
                                            ) : (
                                                <Off />
                                            )}
                                        </Td>
                                        <Td>{pct(b.rates.withdrawal)}</Td>
                                        <Td className="text-xs whitespace-nowrap">
                                            {b.withdrawal_min_amount === null &&
                                            b.withdrawal_max_amount === null
                                                ? 'Any amount'
                                                : `${formatLimit(b.withdrawal_min_amount)} – ${formatLimit(b.withdrawal_max_amount)}`}
                                        </Td>
                                        <Td className="font-medium">
                                            {formatPaise(b.today.withdrawal, 0)}
                                        </Td>
                                        <Td divider>
                                            <span className="rounded-md bg-sf2 px-2 py-0.5 text-xs whitespace-nowrap">
                                                {b.partners_count} mapped
                                            </span>
                                        </Td>
                                        <Td>
                                            {b.admins.length ? (
                                                b.admins.join(', ')
                                            ) : (
                                                <span className="text-xs text-tx3">
                                                    None yet
                                                </span>
                                            )}
                                        </Td>
                                        <Td>
                                            <StatusBadge status={b.status} />
                                        </Td>
                                        <Td>
                                            <div className="flex justify-end gap-1.5">
                                                {can.users && (
                                                    <Link
                                                        href={
                                                            branchesRoutes.users.index(
                                                                b.id,
                                                            ).url
                                                        }
                                                        onClick={(event) =>
                                                            event.stopPropagation()
                                                        }
                                                        className="rounded-[7px] border border-ln px-2.5 py-1 text-xs font-medium hover:bg-sf2"
                                                    >
                                                        Users
                                                    </Link>
                                                )}
                                                {can.update && (
                                                    <button
                                                        type="button"
                                                        onClick={(event) => {
                                                            event.stopPropagation();
                                                            setLimitsBranch(b);
                                                        }}
                                                        className="rounded-[7px] border border-ln px-2.5 py-1 text-xs font-medium whitespace-nowrap hover:bg-sf2"
                                                    >
                                                        Edit limits
                                                    </button>
                                                )}
                                                {can.update && (
                                                    <Link
                                                        href={
                                                            branchesRoutes.edit(
                                                                b.id,
                                                            ).url
                                                        }
                                                        onClick={(event) =>
                                                            event.stopPropagation()
                                                        }
                                                        className="rounded-[7px] border border-ln px-2.5 py-1 text-xs font-medium hover:bg-sf2"
                                                    >
                                                        Edit
                                                    </Link>
                                                )}
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {branches.last_page > 1 && (
                    <div className="flex justify-end gap-2 px-3 py-2.5 text-xs">
                        {branches.prev_page_url && (
                            <Link
                                href={branches.prev_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                Previous
                            </Link>
                        )}
                        {branches.next_page_url && (
                            <Link
                                href={branches.next_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                Next
                            </Link>
                        )}
                    </div>
                )}
            </Panel>

            {open && (
                <Drawer
                    open
                    onOpenChange={(next) => !next && setOpenId(null)}
                    kind="Branch"
                    title={open.code}
                    status={<StatusBadge status={open.status} />}
                    subtitle={open.name}
                    actions={
                        <div className="flex gap-2">
                            {can.users && (
                                <Link
                                    href={
                                        branchesRoutes.users.index(open.id).url
                                    }
                                    className="inline-flex h-8 items-center rounded-[7px] border border-ln px-3 text-[13px] font-medium hover:bg-sf2"
                                >
                                    Users
                                </Link>
                            )}
                            {can.update && (
                                <button
                                    type="button"
                                    onClick={() => setLimitsBranch(open)}
                                    className="inline-flex h-8 items-center rounded-[7px] border border-ln px-3 text-[13px] font-medium hover:bg-sf2"
                                >
                                    Edit limits
                                </button>
                            )}
                            {can.update && (
                                <Link
                                    href={branchesRoutes.edit(open.id).url}
                                    className="inline-flex h-8 items-center rounded-[7px] border border-ln px-3 text-[13px] font-medium hover:bg-sf2"
                                >
                                    Edit
                                </Link>
                            )}
                        </div>
                    }
                    summaries={[
                        {
                            label: 'Deposit commission',
                            value: pct(open.rates.deposit),
                        },
                        {
                            label: 'Withdrawal commission',
                            value: pct(open.rates.withdrawal),
                        },
                        open.deposit_limit_type === 'topup'
                            ? {
                                  label: 'Deposit allowance left',
                                  value: formatPaise(
                                      open.deposit_topup_balance,
                                      0,
                                  ),
                              }
                            : {
                                  label: 'Deposits today',
                                  value: formatPaise(open.today.deposit, 0),
                              },
                    ]}
                    tabs={[
                        { key: 'overview', label: 'Overview' },
                        { key: 'accounts', label: `Accounts` },
                        { key: 'partners', label: 'Partners' },
                        { key: 'users', label: 'Users' },
                        ...(open.deposit_limit_type === 'topup'
                            ? [{ key: 'topups', label: 'Top-ups' }]
                            : []),
                        { key: 'commission', label: 'Commission history' },
                        { key: 'activity', label: 'Activity' },
                    ]}
                    activeTab={tab}
                    onTabChange={setTab}
                >
                    {!loaded ? (
                        <div className="py-10 text-center text-xs text-tx3">
                            Loading…
                        </div>
                    ) : (
                        <>
                            {tab === 'overview' && (
                                <>
                                    {loaded.blockers.length > 0 && (
                                        <div className="rounded-lg bg-inb px-3 py-2.5 text-[12.5px] text-in">
                                            Not ready to go live:{' '}
                                            {loaded.blockers.join('; ')}.
                                        </div>
                                    )}
                                    <KeyValues
                                        items={[
                                            {
                                                label: 'Deposits',
                                                value: open.is_deposit_enabled
                                                    ? 'Enabled'
                                                    : 'Disabled',
                                            },
                                            {
                                                label: 'Deposit min / max',
                                                value: `${formatLimit(loaded.limits.deposit[0])} – ${formatLimit(loaded.limits.deposit[1])}`,
                                            },
                                            {
                                                label: 'Deposit limit',
                                                value:
                                                    open.deposit_limit_type ===
                                                    'topup'
                                                        ? `Top-up balance (${formatPaise(open.deposit_topup_balance, 0)} left)`
                                                        : `${formatLimit(loaded.limits.deposit[2])} per day`,
                                            },
                                            {
                                                label: 'Withdrawals',
                                                value: open.is_withdrawal_enabled
                                                    ? 'Enabled'
                                                    : 'Disabled',
                                            },
                                            {
                                                label: 'Withdrawal min / max',
                                                value: `${formatLimit(loaded.limits.withdrawal[0])} – ${formatLimit(loaded.limits.withdrawal[1])}`,
                                            },
                                            {
                                                label: 'Withdrawal daily limit',
                                                value: formatLimit(
                                                    loaded.limits.withdrawal[2],
                                                ),
                                            },
                                            {
                                                label: 'Went live',
                                                value: formatDateTime(
                                                    loaded.verified_at,
                                                ),
                                            },
                                            {
                                                label: 'Created',
                                                value: formatDateTime(
                                                    loaded.created_at,
                                                ),
                                            },
                                        ]}
                                    />
                                    {can.update && (
                                        <div className="flex flex-wrap gap-2 border-t border-ln2 pt-4">
                                            {open.deposit_limit_type ===
                                                'topup' && (
                                                <PgButton
                                                    onClick={() =>
                                                        setTopupOpen(true)
                                                    }
                                                >
                                                    Top up allowance
                                                </PgButton>
                                            )}
                                            {loaded.transitions.map(
                                                (status) => (
                                                    <PgButton
                                                        key={status}
                                                        variant={
                                                            status === 'active'
                                                                ? 'primary'
                                                                : status ===
                                                                    'offboarded'
                                                                  ? 'danger'
                                                                  : 'secondary'
                                                        }
                                                        disabled={
                                                            status ===
                                                                'active' &&
                                                            loaded.blockers
                                                                .length > 0
                                                        }
                                                        onClick={() =>
                                                            setTransition(
                                                                status,
                                                            )
                                                        }
                                                    >
                                                        {open.status ===
                                                            'suspended' &&
                                                        status === 'active'
                                                            ? 'Reactivate'
                                                            : TRANSITIONS[
                                                                  status
                                                              ].label}
                                                    </PgButton>
                                                ),
                                            )}
                                        </div>
                                    )}
                                </>
                            )}
                            {tab === 'accounts' && (
                                <SimpleTable
                                    empty="The branch hasn’t added accounts yet."
                                    headers={[
                                        'Account',
                                        'Bank',
                                        'UPI',
                                        'Status',
                                    ]}
                                    rows={loaded.accounts.map((a) => [
                                        <span key="a">
                                            {a.holder}
                                            <span className="block text-xs text-tx3">
                                                {a.label}
                                            </span>
                                        </span>,
                                        a.bank ?? '—',
                                        <span
                                            key="u"
                                            className="font-mono text-xs"
                                        >
                                            {a.upi ?? '—'}
                                        </span>,
                                        <StatusBadge
                                            key="s"
                                            status={a.status}
                                            label={
                                                a.status === 'rejected'
                                                    ? 'Rejected'
                                                    : undefined
                                            }
                                        />,
                                    ])}
                                />
                            )}
                            {tab === 'partners' && (
                                <SimpleTable
                                    empty="Not mapped to any partner yet."
                                    headers={[
                                        'Partner',
                                        'Deposits',
                                        'Withdrawals',
                                        'Mapping',
                                    ]}
                                    rows={loaded.partners.map((p) => [
                                        <span key="p">
                                            <span className="font-mono text-xs text-tx3">
                                                {p.code}
                                            </span>{' '}
                                            {p.name}
                                        </span>,
                                        p.deposit ? 'On' : 'Off',
                                        p.withdrawal ? 'On' : 'Off',
                                        <StatusBadge
                                            key="s"
                                            status={p.status}
                                        />,
                                    ])}
                                />
                            )}
                            {tab === 'users' && (
                                <SimpleTable
                                    empty="No users yet. Add them with the Users button above."
                                    headers={['Username', 'Role', 'Status']}
                                    rows={loaded.users.map((u) => [
                                        u.name,
                                        u.role,
                                        <StatusBadge
                                            key="s"
                                            status={u.status}
                                        />,
                                    ])}
                                />
                            )}
                            {tab === 'topups' && (
                                <SimpleTable
                                    empty="No top-ups yet."
                                    headers={[
                                        'Amount',
                                        'Balance after',
                                        'Reason',
                                        'By',
                                        'When',
                                    ]}
                                    rows={loaded.topups.map((t) => [
                                        <span
                                            key="a"
                                            className={
                                                t.amount < 0
                                                    ? 'text-er'
                                                    : 'text-ok'
                                            }
                                        >
                                            {t.amount < 0 ? '−' : '+'}
                                            {formatPaise(Math.abs(t.amount))}
                                        </span>,
                                        formatPaise(t.balance_after),
                                        t.reason,
                                        t.by,
                                        formatDateTime(t.at),
                                    ])}
                                />
                            )}
                            {tab === 'commission' && (
                                <SimpleTable
                                    empty="No commission set yet."
                                    headers={[
                                        'Direction',
                                        'Rate',
                                        'From',
                                        'Until',
                                    ]}
                                    rows={loaded.rate_history.map((r) => [
                                        r.direction === 'deposit'
                                            ? 'Deposit'
                                            : 'Withdrawal',
                                        `${Number(r.rate)}%`,
                                        formatDateTime(r.from),
                                        r.to ? (
                                            formatDateTime(r.to)
                                        ) : (
                                            <span className="text-ok">
                                                Current
                                            </span>
                                        ),
                                    ])}
                                />
                            )}
                            {tab === 'activity' && (
                                <SimpleTable
                                    empty="No activity yet."
                                    headers={['What', 'Who', 'When']}
                                    rows={loaded.activity.map((a) => [
                                        <span key="a">
                                            <span className="text-xs font-medium">
                                                {a.summary}
                                            </span>
                                            {a.changes.map((change) => (
                                                <span
                                                    key={change}
                                                    className="block text-xs text-tx3"
                                                >
                                                    {change}
                                                </span>
                                            ))}
                                            {a.reason && (
                                                <span className="block text-xs text-tx3">
                                                    {a.reason}
                                                </span>
                                            )}
                                        </span>,
                                        a.actor,
                                        formatRelative(a.at),
                                    ])}
                                />
                            )}
                        </>
                    )}
                </Drawer>
            )}

            {open && transition && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setTransition(null)}
                    title={`${open.status === 'suspended' && transition === 'active' ? 'Reactivate' : TRANSITIONS[transition].label} ${open.name}?`}
                    description={TRANSITIONS[transition].description}
                    confirmLabel={
                        open.status === 'suspended' && transition === 'active'
                            ? 'Reactivate'
                            : TRANSITIONS[transition].label
                    }
                    tone={TRANSITIONS[transition].tone}
                    input={{
                        label: 'Reason (kept in the audit log)',
                        required: true,
                    }}
                    onConfirm={(reason) =>
                        router.put(
                            branchesRoutes.status(open.id).url,
                            { status: transition, reason },
                            {
                                preserveScroll: true,
                                onFinish: () => setTransition(null),
                            },
                        )
                    }
                />
            )}

            {limitsBranch && (
                <LimitsDialog
                    key={limitsBranch.id}
                    title={`Limits for ${limitsBranch.name}`}
                    description="Only deposit and withdrawal limits change. Everything else stays as it is. Amounts are in rupees."
                    limits={limitsBranch.limits}
                    url={branchesRoutes.limits(limitsBranch.id).url}
                    showDailyDeposit={
                        limitsBranch.deposit_limit_type !== 'topup'
                    }
                    onClose={() => setLimitsBranch(null)}
                    onSaved={() => {
                        if (openId === limitsBranch.id) {
                            router.reload({
                                only: ['detail'],
                                data: { branch: limitsBranch.id },
                            });
                        }
                    }}
                />
            )}

            {open && topupOpen && (
                <TopupDialog
                    branch={open}
                    onClose={() => {
                        setTopupOpen(false);
                        router.reload({ only: ['detail', 'branches'] });
                    }}
                />
            )}
        </>
    );
}

function TopupDialog({
    branch,
    onClose,
}: {
    branch: Row;
    onClose: () => void;
}) {
    const form = useForm({ kind: 'topup', amount: '', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Deposit allowance of ${branch.code}`}
            description={`Currently ${formatPaise(branch.deposit_topup_balance)}. Each successful deposit uses it up; the branch stops receiving customers at zero.`}
            submitLabel={form.data.kind === 'topup' ? 'Top up' : 'Reduce'}
            processing={form.processing}
            onSubmit={() =>
                form.post(topupsRoutes.store(branch.id).url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
        >
            <Field label="Change">
                <SelectInput
                    value={form.data.kind}
                    onChange={(event) =>
                        form.setData('kind', event.target.value)
                    }
                >
                    <option value="topup">Add to the allowance</option>
                    <option value="correction">Reduce (correction)</option>
                </SelectInput>
            </Field>
            <Field label="Amount (₹)" error={errors.amount}>
                <TextInput
                    required
                    autoFocus
                    inputMode="decimal"
                    pattern="\d{1,11}(\.\d{1,2})?"
                    value={form.data.amount}
                    invalid={!!errors.amount}
                    onChange={(event) =>
                        form.setData(
                            'amount',
                            event.target.value.replace(/[,₹\s]/g, ''),
                        )
                    }
                />
            </Field>
            <Field label="Reason (kept in the history)" error={errors.reason}>
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

function Td({
    children,
    className,
    divider,
}: {
    children: ReactNode;
    className?: string;
    divider?: boolean;
}) {
    return (
        <td
            className={cn(
                'border-b border-ln2 px-2.5 py-rp align-middle',
                divider && 'border-l',
                className,
            )}
        >
            {children}
        </td>
    );
}

function Off() {
    return <span className="text-xs text-tx3">Off</span>;
}
