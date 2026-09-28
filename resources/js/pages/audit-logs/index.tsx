import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { ViewTabs } from '@/components/pg/filter-bar';
import { PageHeader } from '@/components/pg/page-header';
import { formatDateTime } from '@/lib/dates';
import admin from '@/routes/admin';
import branch from '@/routes/branch';
import type { UserType } from '@/types';

type Row = {
    id: string;
    action?: string;
    event?: string;
    who: string | null;
    subject?: string | null;
    old?: unknown;
    new?: unknown;
    context?: unknown;
    ip: string | null;
    request_id: string | null;
    at: string;
};

type Props = {
    portal: UserType;
    tab: 'activity' | 'security';
    items: {
        data: Row[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { search: string; from: string; to: string };
};

/**
 * Audit Logs (read-only): who did what, with before → after, IP and request
 * id. Admin also sees sign-in and security events; a branch sees what its
 * own users did.
 */
export default function AuditLogs({ portal, tab, items, filters }: Props) {
    const routes = portal === 'admin' ? admin : branch;
    const [search, setSearch] = useState(filters.search);
    const [open, setOpen] = useState<string | null>(null);

    const visit = (next: Record<string, string | null>) =>
        router.get(
            routes.auditLogs.index({
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

    const json = (value: unknown) =>
        value === null || value === undefined ? '—' : JSON.stringify(value);

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
            key: 'who',
            header: 'Who',
            cell: (row) => (
                <span className="whitespace-nowrap">{row.who ?? '—'}</span>
            ),
        },
        {
            key: 'what',
            header: tab === 'security' ? 'Event' : 'Action',
            cell: (row) => (
                <div>
                    <div className="font-mono text-xs font-medium">
                        {row.action ?? row.event}
                    </div>
                    {row.subject && (
                        <div className="text-xs text-tx3">{row.subject}</div>
                    )}
                </div>
            ),
        },
        {
            key: 'change',
            header: tab === 'security' ? 'Details' : 'Before → after',
            cell: (row) => (
                <button
                    type="button"
                    onClick={() => setOpen(open === row.id ? null : row.id)}
                    className="max-w-[460px] text-left font-mono text-[11px] break-all text-tx2"
                >
                    {tab === 'security'
                        ? json(row.context)
                        : open === row.id
                          ? `${json(row.old)} → ${json(row.new)}`
                          : `${json(row.old).slice(0, 60)} → ${json(row.new).slice(0, 80)}${json(row.new).length > 80 ? '…' : ''}`}
                </button>
            ),
        },
        {
            key: 'ip',
            header: 'IP · Request',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap text-tx3">
                    <div>{row.ip ?? '—'}</div>
                    <div className="font-mono">
                        {row.request_id?.slice(0, 12) ?? '—'}
                    </div>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Audit Logs" />
            <PageHeader
                title="Audit Logs"
                description={
                    portal === 'admin'
                        ? 'Every recorded action and security event. Logs can’t be changed or deleted.'
                        : 'What your branch’s users did. Logs can’t be changed or deleted.'
                }
            />
            <Panel className="overflow-hidden">
                {portal === 'admin' && (
                    <ViewTabs
                        views={[
                            { key: 'activity', label: 'Activity' },
                            { key: 'security', label: 'Sign-ins & security' },
                        ]}
                        active={tab}
                        onChange={(key) => visit({ tab: key })}
                    />
                )}
                <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] items-center gap-1.5 rounded-[7px] border border-ln px-2.5 text-[12.5px]">
                        <input
                            type="date"
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
                            value={filters.to}
                            onChange={(event) =>
                                event.target.value &&
                                visit({ to: event.target.value })
                            }
                            className="bg-transparent outline-none"
                        />
                    </label>
                    <label className="flex h-[30px] w-[300px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={
                                tab === 'security'
                                    ? 'Email, IP or event'
                                    : 'Action, person or request id'
                            }
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">
                        {items.total.toLocaleString('en-IN')} entries · newest
                        first
                    </span>
                </div>
                <div className="overflow-x-auto">
                    <DataTable
                        columns={columns}
                        rows={items.data}
                        rowKey={(row) => row.id}
                        empty={
                            <EmptyState title="Nothing logged in this period" />
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
        </>
    );
}
