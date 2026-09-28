import { Head, router } from '@inertiajs/react';
import { Download, FileSpreadsheet } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { SelectInput } from '@/components/pg/field';
import { PageHeader } from '@/components/pg/page-header';
import { SimpleTable } from '@/components/pg/simple-table';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import branch from '@/routes/branch';
import { show as fileUrl } from '@/routes/files';
import partner from '@/routes/partner';
import type { UserType } from '@/types';

type ColumnDef = { key: string; label: string; type: string };
type Option = { id: string; code: string; name: string };
type Row = Record<string, string | number | null>;

type Props = {
    portal: UserType;
    catalog: { key: string; title: string; description: string }[];
    report: {
        key: string;
        title: string;
        description: string;
        uses_period: boolean;
        columns: ColumnDef[];
        filters: Record<string, string>;
    };
    period: { range: string; from: string; to: string; label: string };
    filters: Record<string, string | null>;
    rows: Row[];
    preview_limit: number;
    totals: Record<string, number>;
    options: { partner: Option[]; branch: Option[] };
    exports: {
        id: string;
        report: string;
        format: 'csv' | 'xlsx';
        status: string;
        rows: number | null;
        file_id: string | null;
        error: string | null;
        created_at: string;
        expires_at: string | null;
    }[];
    can: { export: boolean };
};

const RANGES: [string, string][] = [
    ['today', 'Today'],
    ['yesterday', 'Yesterday'],
    ['7d', 'Last 7 days'],
    ['30d', 'Last 30 days'],
    ['this_month', 'This month'],
    ['prev_month', 'Previous month'],
    ['custom', 'Custom…'],
];

const STATUSES: Record<string, [string, string][]> = {
    payins: [
        ['success', 'Success'],
        ['payment_submitted', 'Pending'],
        ['under_review', 'Payment hold'],
        ['awaiting_payment', 'Awaiting payment'],
        ['rejected', 'Declined'],
        ['expired', 'Expired'],
        ['cancelled', 'Cancelled'],
    ],
    payouts: [
        ['success', 'Paid'],
        ['assigned', 'Assigned'],
        ['processing', 'Processing'],
        ['failed', 'Failed'],
        ['cancelled', 'Cancelled'],
    ],
};

const EXPORT_LABELS: Record<string, string> = {
    queued: 'Waiting',
    running: 'Preparing',
    ready: 'Ready',
    failed: 'Failed',
    expired: 'Deleted after 7 days',
};

function cell(type: string, value: Row[string]) {
    if (value === null || value === undefined || value === '') return '—';

    switch (type) {
        case 'money':
            return formatPaise(Number(value));
        case 'signed': {
            const number = Number(value);

            return (
                <span
                    className={
                        number < 0 ? 'text-er' : number > 0 ? 'text-ok' : ''
                    }
                >
                    {number > 0 ? '+' : number < 0 ? '−' : ''}
                    {formatPaise(Math.abs(number))}
                </span>
            );
        }
        case 'count':
            return Number(value).toLocaleString('en-IN');
        case 'date':
            return (
                <span className="whitespace-nowrap">
                    {formatDateTime(String(value))}
                </span>
            );
        case 'status':
            return <StatusBadge status={String(value)} />;
        default:
            return <span className="whitespace-nowrap">{String(value)}</span>;
    }
}

/**
 * Reports: pick a report, a period and filters; preview the first rows with
 * totals; export the full report as CSV or Excel (prepared in the
 * background, kept 7 days).
 */
export default function Reports(props: Props) {
    const { portal, catalog, report, period, filters, rows, totals, options } =
        props;
    const routes = { admin, branch, partner }[portal].reports;
    const [custom, setCustom] = useState({
        from: period.from.slice(0, 10),
        to: period.to.slice(0, 10),
    });
    const [range, setRange] = useState(period.range);
    const pending = props.exports.some((item) =>
        ['queued', 'running'].includes(item.status),
    );

    const query = (next: Record<string, string | null> = {}) =>
        Object.fromEntries(
            Object.entries({
                report: report.key,
                range: period.range,
                ...(period.range === 'custom' ? custom : {}),
                ...filters,
                ...next,
            }).filter(([, value]) => value),
        ) as Record<string, string>;

    const visit = (next: Record<string, string | null>) =>
        router.get(
            routes.index({ query: query(next) }).url,
            {},
            { preserveState: true, preserveScroll: true },
        );

    // Exports in progress: check again every 5 seconds.
    useEffect(() => {
        if (!pending) return;
        const timer = setInterval(
            () => router.reload({ only: ['exports'] }),
            5000,
        );

        return () => clearInterval(timer);
    }, [pending]);

    const exportAs = (format: 'csv' | 'xlsx') =>
        router.post(
            routes.export().url,
            { ...query(), format },
            { preserveScroll: true, preserveState: true },
        );

    const columns: Column<Row>[] = report.columns.map((column) => ({
        key: column.key,
        header: column.label,
        align: ['money', 'signed', 'count'].includes(column.type)
            ? 'right'
            : 'left',
        cell: (row: Row) => cell(column.type, row[column.key]),
    }));

    const summed = report.columns.filter(
        (column) =>
            ['money', 'signed'].includes(column.type) &&
            totals[column.key] !== undefined,
    );

    return (
        <>
            <Head title="Reports" />
            <PageHeader
                title="Reports"
                description="Choose a report and a period. The preview shows the first rows; export the full report as CSV or Excel."
            />

            <div className="grid gap-3 lg:grid-cols-[240px_1fr]">
                <Panel className="flex h-fit flex-col p-1.5">
                    {catalog.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() =>
                                router.get(
                                    routes.index({
                                        query: {
                                            report: item.key,
                                            range: period.range,
                                        },
                                    }).url,
                                )
                            }
                            title={item.description}
                            className={cn(
                                'rounded-md px-3 py-2 text-left text-[13px]',
                                item.key === report.key
                                    ? 'bg-acs font-semibold text-act'
                                    : 'text-tx2 hover:bg-sf2',
                            )}
                        >
                            {item.title}
                        </button>
                    ))}
                </Panel>

                <div className="flex min-w-0 flex-col gap-3">
                    <Panel className="flex flex-col gap-3 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="text-base font-semibold">
                                    {report.title}
                                </div>
                                <p className="text-[13px] text-tx2">
                                    {report.description}
                                </p>
                            </div>
                            {props.can.export && (
                                <div className="flex gap-2">
                                    <PgButton onClick={() => exportAs('csv')}>
                                        <Download className="size-3.5" /> CSV
                                    </PgButton>
                                    <PgButton onClick={() => exportAs('xlsx')}>
                                        <FileSpreadsheet className="size-3.5" />{' '}
                                        Excel
                                    </PgButton>
                                </div>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {report.uses_period ? (
                                <>
                                    <SelectInput
                                        className="h-[30px] w-[170px] text-[12.5px]"
                                        value={range}
                                        onChange={(event) => {
                                            setRange(event.target.value);

                                            if (
                                                event.target.value !== 'custom'
                                            ) {
                                                visit({
                                                    range: event.target.value,
                                                });
                                            }
                                        }}
                                    >
                                        {RANGES.map(([key, label]) => (
                                            <option key={key} value={key}>
                                                {label}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    {range === 'custom' && (
                                        <form
                                            className="flex items-center gap-1.5 text-[12.5px]"
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                visit({
                                                    range: 'custom',
                                                    ...custom,
                                                });
                                            }}
                                        >
                                            <input
                                                type="date"
                                                value={custom.from}
                                                onChange={(event) =>
                                                    setCustom({
                                                        ...custom,
                                                        from: event.target
                                                            .value,
                                                    })
                                                }
                                                className="h-[30px] rounded-md border border-ln bg-sf px-2"
                                            />
                                            –
                                            <input
                                                type="date"
                                                value={custom.to}
                                                onChange={(event) =>
                                                    setCustom({
                                                        ...custom,
                                                        to: event.target.value,
                                                    })
                                                }
                                                className="h-[30px] rounded-md border border-ln bg-sf px-2"
                                            />
                                            <PgButton
                                                type="submit"
                                                variant="primary"
                                                className="h-[30px]"
                                            >
                                                Show
                                            </PgButton>
                                        </form>
                                    )}
                                    <span className="text-xs text-tx3">
                                        {period.label}
                                    </span>
                                </>
                            ) : (
                                <span className="text-xs text-tx3">
                                    As of now
                                </span>
                            )}
                            {Object.entries(report.filters).map(
                                ([key, label]) => (
                                    <SelectInput
                                        key={key}
                                        className="h-[30px] w-[180px] text-[12.5px]"
                                        value={filters[key] ?? ''}
                                        onChange={(event) =>
                                            visit({
                                                [key]:
                                                    event.target.value || null,
                                            })
                                        }
                                    >
                                        <option value="">{`${label}: all`}</option>
                                        {key === 'status' &&
                                            (STATUSES[report.key] ?? []).map(
                                                ([value, text]) => (
                                                    <option
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {text}
                                                    </option>
                                                ),
                                            )}
                                        {key === 'party_type' && (
                                            <>
                                                <option value="partner">
                                                    Partners
                                                </option>
                                                <option value="branch">
                                                    Branches
                                                </option>
                                            </>
                                        )}
                                        {(key === 'partner' ||
                                            key === 'branch') &&
                                            options[key].map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.code} ·{' '}
                                                    {option.name}
                                                </option>
                                            ))}
                                    </SelectInput>
                                ),
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Total
                                label="Rows"
                                value={(totals._count ?? 0).toLocaleString(
                                    'en-IN',
                                )}
                            />
                            {summed.map((column) => (
                                <Total
                                    key={column.key}
                                    label={column.label}
                                    value={formatPaise(totals[column.key])}
                                />
                            ))}
                        </div>
                    </Panel>

                    <Panel className="overflow-hidden">
                        <div className="overflow-x-auto">
                            <DataTable
                                columns={columns}
                                rows={rows}
                                rowKey={(row) =>
                                    String(
                                        row.reference ??
                                            row.day ??
                                            `${row.partner ?? ''}-${row.branch ?? ''}-${rows.indexOf(row)}`,
                                    )
                                }
                                empty={
                                    <EmptyState
                                        title="Nothing in this period"
                                        description="Try a longer period or other filters."
                                    />
                                }
                            />
                        </div>
                        {(totals._count ?? 0) > rows.length && (
                            <div className="border-t border-ln2 px-3 py-2.5 text-xs text-tx3">
                                Showing the first {rows.length} of{' '}
                                {totals._count.toLocaleString('en-IN')} rows.
                                Export the report for all of them.
                            </div>
                        )}
                    </Panel>

                    {props.exports.length > 0 && (
                        <Panel className="flex flex-col gap-2 p-4">
                            <div className="text-sm font-semibold">
                                Your exports
                            </div>
                            <p className="text-xs text-tx3">
                                Prepared in the background. Files are deleted
                                after 7 days; export again if you need it later.
                            </p>
                            <SimpleTable
                                headers={[
                                    'Report',
                                    'Asked',
                                    'Rows',
                                    'Status',
                                    '',
                                ]}
                                rows={props.exports.map((item) => [
                                    `${item.report} · ${item.format === 'csv' ? 'CSV' : 'Excel'}`,
                                    formatDateTime(item.created_at),
                                    item.rows === null
                                        ? '—'
                                        : item.rows.toLocaleString('en-IN'),
                                    <span
                                        key="s"
                                        title={item.error ?? undefined}
                                    >
                                        <StatusBadge
                                            status={
                                                item.status === 'ready'
                                                    ? 'success'
                                                    : item.status === 'failed'
                                                      ? 'failed'
                                                      : item.status ===
                                                          'expired'
                                                        ? 'cancelled'
                                                        : 'processing'
                                            }
                                            label={EXPORT_LABELS[item.status]}
                                        />
                                    </span>,
                                    item.file_id ? (
                                        <a
                                            key="d"
                                            href={fileUrl(item.file_id).url}
                                            className="text-xs font-medium text-ac"
                                        >
                                            Download
                                        </a>
                                    ) : (
                                        ''
                                    ),
                                ])}
                            />
                        </Panel>
                    )}
                </div>
            </div>
        </>
    );
}

function Total({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border border-ln2 bg-sf2 px-3 py-1.5">
            <div className="text-[11px] text-tx3">{label}</div>
            <div className="text-[13px] font-semibold">{value}</div>
        </div>
    );
}
