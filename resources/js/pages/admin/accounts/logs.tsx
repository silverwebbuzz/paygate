import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Download, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { SelectInput } from '@/components/pg/field';
import { PageHeader } from '@/components/pg/page-header';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDateTime } from '@/lib/dates';
import { statusStyle } from '@/lib/status';
import accountsRoutes from '@/routes/admin/accounts';

type Row = {
    id: string;
    at: string;
    event: string;
    event_label: string;
    from: string | null;
    to: string | null;
    fields: string[];
    reason: string | null;
    ip: string | null;
    account: {
        id: string;
        label: string;
        holder: string | null;
        bank_name: string | null;
        account_number: string | null;
        upi_id: string | null;
    } | null;
    branch: { code: string; name: string } | null;
    who: {
        name: string;
        username: string | null;
        role: string | null;
        portal: string | null;
    };
};

type Filters = {
    from: string;
    to: string;
    event: string | null;
    branch: string | null;
    account: string | null;
    search: string;
};

type Props = {
    logs: {
        data: Row[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Filters;
    account: { id: string; label: string; holder: string | null } | null;
    events: { value: string; label: string }[];
    branches: { id: string; code: string; name: string }[];
};

const EVENT_STATUS: Record<string, string> = {
    created: 'verification_pending',
    updated: 'pending_review',
    verified: 'verified',
    rejected: 'rejected',
    activated: 'active',
    deactivated: 'inactive',
    paused: 'paused',
    disabled: 'disabled',
    verification_changed: 'pending',
    revealed: 'pending_review',
};

const PORTAL_LABEL: Record<string, string> = {
    admin: 'PayGate',
    partner: 'Partner',
    branch: 'Branch',
};

export default function AccountLogs({
    logs,
    filters,
    account,
    events,
    branches,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    const query = (next: Partial<Filters>) =>
        Object.fromEntries(
            Object.entries({ ...filters, search, ...next }).filter(
                ([, value]) => value !== null && value !== '',
            ),
        ) as Record<string, string>;

    const visit = (next: Partial<Filters>) =>
        router.get(
            accountsRoutes.logs.index({ query: query(next) }).url,
            {},
            { preserveState: true, replace: true },
        );

    useEffect(() => {
        if (search === filters.search) return;
        const timer = setTimeout(() => visit({ search }), 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const columns: Column<Row>[] = [
        {
            key: 'at',
            header: 'When',
            cell: (row) => (
                <span className="text-xs whitespace-nowrap">
                    {formatDateTime(row.at)}
                </span>
            ),
        },
        {
            key: 'account',
            header: 'Account',
            cell: (row) =>
                row.account ? (
                    <button
                        type="button"
                        onClick={() => visit({ account: row.account!.id })}
                        className="text-left"
                        title="Show only this account"
                    >
                        <div className="font-medium">
                            {row.account.holder ?? row.account.label}
                        </div>
                        <div className="text-xs text-tx3">
                            {[
                                row.account.bank_name,
                                row.account.account_number,
                                row.account.upi_id,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </div>
                    </button>
                ) : (
                    '—'
                ),
        },
        {
            key: 'change',
            header: 'Change',
            cell: (row) => (
                <div className="space-y-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <StatusBadge
                            status={EVENT_STATUS[row.event] ?? 'pending'}
                            label={row.event_label}
                        />
                    </div>
                    {(row.from || row.to) && row.from !== row.to && (
                        <div className="text-xs whitespace-nowrap text-tx3">
                            {row.from ? statusStyle(row.from).label : '—'} →{' '}
                            {row.to ? statusStyle(row.to).label : '—'}
                        </div>
                    )}
                    {row.fields.length > 0 && (
                        <div className="max-w-[260px] text-xs text-tx3">
                            Changed: {row.fields.join(', ')}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'who',
            header: 'Who',
            cell: (row) => (
                <div className="whitespace-nowrap">
                    <div className="font-medium">{row.who.name}</div>
                    <div className="text-xs text-tx3">
                        {[
                            row.who.username && `@${row.who.username}`,
                            row.who.role,
                            row.who.portal && PORTAL_LABEL[row.who.portal],
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </div>
                </div>
            ),
        },
        {
            key: 'reason',
            header: 'Reason',
            cell: (row) => (
                <span className="block max-w-[280px] text-xs text-tx2">
                    {row.reason ?? '—'}
                </span>
            ),
        },
        {
            key: 'branch',
            header: 'Branch',
            cell: (row) =>
                row.branch ? (
                    <div className="whitespace-nowrap">
                        <div className="font-mono text-xs">
                            {row.branch.code}
                        </div>
                        <div className="text-xs text-tx3">
                            {row.branch.name}
                        </div>
                    </div>
                ) : (
                    '—'
                ),
        },
        {
            key: 'ip',
            header: 'IP',
            cell: (row) => (
                <span className="font-mono text-xs whitespace-nowrap text-tx3">
                    {row.ip ?? '—'}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title="Account log" />
            <PageHeader
                title="Account log"
                description="Every change to branch Bank & UPI accounts: added, edited, verified, rejected, activated, paused, disabled and full numbers viewed, by branch users and PayGate staff. Entries can’t be changed or deleted."
                actions={
                    <>
                        <Link
                            href={accountsRoutes.index().url}
                            className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                        >
                            <ArrowLeft className="size-3.5" /> Accounts
                        </Link>
                        <a
                            href={
                                accountsRoutes.logs.export({ query: query({}) })
                                    .url
                            }
                            className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                        >
                            <Download className="size-3.5" /> Export CSV
                        </a>
                    </>
                }
            />
            <Panel className="overflow-hidden">
                <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] items-center gap-1.5 rounded-[7px] border border-ln px-2.5 text-[12.5px]">
                        <input
                            type="date"
                            aria-label="From"
                            value={filters.from}
                            onChange={(event) =>
                                event.target.value &&
                                visit({ from: event.target.value })
                            }
                            className="bg-transparent outline-none"
                        />
                        <span className="text-tx3">–</span>
                        <input
                            type="date"
                            aria-label="To"
                            value={filters.to}
                            onChange={(event) =>
                                event.target.value &&
                                visit({ to: event.target.value })
                            }
                            className="bg-transparent outline-none"
                        />
                    </label>
                    <SelectInput
                        aria-label="Event"
                        className="h-[30px] w-[180px] text-[12.5px]"
                        value={filters.event ?? ''}
                        onChange={(event) =>
                            visit({ event: event.target.value || null })
                        }
                    >
                        <option value="">All events</option>
                        {events.map((event) => (
                            <option key={event.value} value={event.value}>
                                {event.label}
                            </option>
                        ))}
                    </SelectInput>
                    <SelectInput
                        aria-label="Branch"
                        className="h-[30px] w-[200px] text-[12.5px]"
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
                    <label className="flex h-[30px] w-[280px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Holder, bank, last 4, person, reason or IP"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                    {account && (
                        <button
                            type="button"
                            onClick={() => visit({ account: null })}
                            className="flex h-[30px] items-center gap-1.5 rounded-[7px] border border-ac px-2.5 text-[12.5px] text-ac"
                        >
                            {account.holder ?? account.label}
                            <X className="size-3.5" />
                        </button>
                    )}
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">
                        {logs.total.toLocaleString('en-IN')} entries · newest
                        first
                    </span>
                </div>
                <div className="overflow-x-auto">
                    <DataTable
                        columns={columns}
                        rows={logs.data}
                        rowKey={(row) => row.id}
                        empty={
                            <EmptyState title="No account changes in this period" />
                        }
                    />
                </div>
                {(logs.prev_page_url || logs.next_page_url) && (
                    <div className="flex justify-end gap-2 px-3 py-2.5 text-xs">
                        {logs.prev_page_url && (
                            <Link
                                href={logs.prev_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                ‹ Newer
                            </Link>
                        )}
                        {logs.next_page_url && (
                            <Link
                                href={logs.next_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                Older ›
                            </Link>
                        )}
                    </div>
                )}
            </Panel>
        </>
    );
}
