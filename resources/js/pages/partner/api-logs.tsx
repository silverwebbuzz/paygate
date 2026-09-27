import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DataTable, Panel } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { ViewTabs } from '@/components/pg/filter-bar';
import { PageHeader } from '@/components/pg/page-header';
import { formatDateTime } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { apiLogs } from '@/routes/partner';

type Log = {
    id: string;
    method: string;
    path: string;
    status_code: number;
    duration_ms: number;
    ip: string | null;
    request_id: string | null;
    order_id: string | null;
    at: string;
};

type Props = {
    logs: {
        data: Log[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { result: string | null; search: string };
};

export default function ApiLogs({ logs, filters }: Props) {
    const [search, setSearch] = useState(filters.search);

    const visit = (next: Partial<Props['filters']>) =>
        router.get(
            apiLogs({
                query: Object.fromEntries(
                    Object.entries({ ...filters, search, ...next }).filter(
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

    return (
        <>
            <Head title="API Logs" />
            <PageHeader
                title="API Logs"
                description="Every call your servers made to the PayGate API, newest first. Request bodies are not stored."
            />
            <Panel className="overflow-hidden">
                <ViewTabs
                    views={[
                        { key: 'all', label: 'All' },
                        { key: 'ok', label: 'Succeeded' },
                        { key: 'client', label: 'Refused (4xx)' },
                        { key: 'server', label: 'Server errors (5xx)' },
                    ]}
                    active={filters.result ?? 'all'}
                    onChange={(key) =>
                        visit({ result: key === 'all' ? null : key })
                    }
                />
                <div className="flex items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] w-[300px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Order id, request id or path"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">{logs.total} calls</span>
                </div>
                <DataTable
                    columns={[
                        {
                            key: 'at',
                            header: 'Time',
                            cell: (l: Log) => (
                                <span className="text-xs whitespace-nowrap">
                                    {formatDateTime(l.at)}
                                </span>
                            ),
                        },
                        {
                            key: 'call',
                            header: 'Call',
                            cell: (l: Log) => (
                                <span className="font-mono text-xs">
                                    <b>{l.method}</b> {l.path}
                                </span>
                            ),
                        },
                        {
                            key: 'status',
                            header: 'Result',
                            cell: (l: Log) => (
                                <span
                                    className={cn(
                                        'rounded-md px-2 py-0.5 font-mono text-xs font-medium',
                                        l.status_code < 400
                                            ? 'bg-okb text-ok'
                                            : l.status_code < 500
                                              ? 'bg-wnb text-wn'
                                              : 'bg-erb text-er',
                                    )}
                                >
                                    {l.status_code}
                                </span>
                            ),
                        },
                        {
                            key: 'order',
                            header: 'Order id',
                            cell: (l: Log) => (
                                <span className="font-mono text-xs">
                                    {l.order_id ?? '—'}
                                </span>
                            ),
                        },
                        {
                            key: 'ms',
                            header: 'Time taken',
                            align: 'right',
                            cell: (l: Log) => `${l.duration_ms} ms`,
                        },
                        {
                            key: 'ip',
                            header: 'From IP',
                            cell: (l: Log) => (
                                <span className="font-mono text-xs">
                                    {l.ip ?? '—'}
                                </span>
                            ),
                        },
                        {
                            key: 'req',
                            header: 'Request id',
                            cell: (l: Log) => (
                                <span className="font-mono text-[11px] text-tx3">
                                    {l.request_id}
                                </span>
                            ),
                        },
                    ]}
                    rows={logs.data}
                    rowKey={(l) => l.id}
                    empty={
                        <EmptyState
                            title="No API calls yet"
                            description="Calls appear here as soon as your servers use the API."
                        />
                    }
                />
                {(logs.prev_page_url || logs.next_page_url) && (
                    <div className="flex justify-end gap-2 px-3 py-2.5 text-xs">
                        {logs.prev_page_url && (
                            <Link
                                href={logs.prev_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                Newer
                            </Link>
                        )}
                        {logs.next_page_url && (
                            <Link
                                href={logs.next_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                Older
                            </Link>
                        )}
                    </div>
                )}
            </Panel>
        </>
    );
}
