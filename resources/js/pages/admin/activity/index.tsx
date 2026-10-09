import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { SelectInput } from '@/components/pg/field';
import { PageHeader } from '@/components/pg/page-header';
import { formatDateTime } from '@/lib/dates';
import branchesRoutes from '@/routes/admin/branches';
import partnersRoutes from '@/routes/admin/partners';

type Change = { field: string; before: string | null; now: string };

type Row = {
    id: string;
    at: string;
    summary: string;
    who: string;
    party: { code: string; name: string } | null;
    changes: Change[];
    reason: string | null;
};

type Props = {
    kind: 'partner' | 'branch';
    logs: {
        data: Row[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: {
        from: string;
        to: string;
        event: string | null;
        party: string | null;
        search: string;
    };
    events: { value: string; label: string }[];
    parties: { id: string; code: string; name: string }[];
};

function ChangeValues({
    changes,
    side,
}: {
    changes: Change[];
    side: 'before' | 'now';
}) {
    if (changes.length === 0) {
        return <span className="text-tx3">—</span>;
    }

    return (
        <div className="max-w-[220px] space-y-2">
            {changes.map((change, index) => (
                <div key={`${change.field}-${index}`}>
                    <div className="text-[11px] text-tx3">{change.field}</div>
                    <div className="text-xs font-medium break-all">
                        {side === 'before'
                            ? (change.before ?? '—')
                            : change.now}
                    </div>
                </div>
            ))}
        </div>
    );
}

export default function Activity({
    kind,
    logs,
    filters,
    events,
    parties,
}: Props) {
    const routes = kind === 'partner' ? partnersRoutes : branchesRoutes;
    const [search, setSearch] = useState(filters.search);
    const visit = (next: Partial<Props['filters']>) => {
        router.get(
            routes.logs().url,
            {
                from: next.from ?? filters.from,
                to: next.to ?? filters.to,
                event: next.event === undefined ? filters.event : next.event,
                party: next.party === undefined ? filters.party : next.party,
                search:
                    next.search === undefined ? filters.search : next.search,
            },
            { preserveState: true, replace: true },
        );
    };

    useEffect(() => {
        const timer = window.setTimeout(() => {
            if (search !== filters.search) {
                visit({ search });
            }
        }, 300);

        return () => window.clearTimeout(timer);
    }, [search]);

    const columns: Column<Row>[] = [
        {
            key: 'when',
            header: 'When',
            cell: (row) => (
                <span className="text-xs whitespace-nowrap text-tx2">
                    {formatDateTime(row.at)}
                </span>
            ),
        },
        {
            key: 'party',
            header: kind === 'partner' ? 'Partner' : 'Branch',
            cell: (row) =>
                row.party ? (
                    <div>
                        <div className="font-medium">{row.party.name}</div>
                        <div className="text-xs text-tx3">{row.party.code}</div>
                    </div>
                ) : (
                    <span className="text-tx3">—</span>
                ),
        },
        {
            key: 'what',
            header: 'What',
            cell: (row) => (
                <div>
                    <div className="font-medium">{row.summary}</div>
                    {row.reason && (
                        <div className="text-xs text-tx3">{row.reason}</div>
                    )}
                </div>
            ),
        },
        {
            key: 'who',
            header: 'Who',
            cell: (row) => <span className="text-xs">{row.who}</span>,
        },
        {
            key: 'before',
            header: 'Before',
            cell: (row) => <ChangeValues changes={row.changes} side="before" />,
        },
        {
            key: 'now',
            header: 'Now',
            cell: (row) => <ChangeValues changes={row.changes} side="now" />,
        },
    ];

    return (
        <>
            <Head
                title={
                    kind === 'partner' ? 'Partner activity' : 'Branch activity'
                }
            />
            <PageHeader
                title={
                    kind === 'partner' ? 'Partner activity' : 'Branch activity'
                }
                description="Who changed what, with the value before and the value now."
                actions={
                    <Link
                        href={routes.index().url}
                        className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                    >
                        <ArrowLeft className="size-3.5" />
                        {kind === 'partner' ? 'Partners' : 'Branches'}
                    </Link>
                }
            />
            <Panel>
                <div className="flex flex-wrap items-center gap-2 border-b border-ln px-3 py-2.5">
                    <input
                        type="date"
                        aria-label="From"
                        value={filters.from}
                        onChange={(event) =>
                            visit({ from: event.target.value })
                        }
                        className="h-[30px] rounded-[7px] border border-ln bg-transparent px-2 text-[12.5px]"
                    />
                    <input
                        type="date"
                        aria-label="To"
                        value={filters.to}
                        onChange={(event) => visit({ to: event.target.value })}
                        className="h-[30px] rounded-[7px] border border-ln bg-transparent px-2 text-[12.5px]"
                    />
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
                        aria-label={kind === 'partner' ? 'Partner' : 'Branch'}
                        className="h-[30px] w-[220px] text-[12.5px]"
                        value={filters.party ?? ''}
                        onChange={(event) =>
                            visit({ party: event.target.value || null })
                        }
                    >
                        <option value="">
                            {kind === 'partner'
                                ? 'All partners'
                                : 'All branches'}
                        </option>
                        {parties.map((party) => (
                            <option key={party.id} value={party.id}>
                                {party.code} · {party.name}
                            </option>
                        ))}
                    </SelectInput>
                    <label className="flex h-[30px] w-[240px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Name, code or person"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
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
                            <EmptyState title="No activity in this period" />
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
