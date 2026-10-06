import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { formatRelative } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { TONE_CLASSES, statusStyle } from '@/lib/status';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import branch from '@/routes/branch';
import partner from '@/routes/partner';
import type { UserType } from '@/types';
import { Panel } from './data-table';
import { StatusBadge } from './status-badge';

/** Shapes from App\Domain\Reporting\DashboardMetrics. */
type Kpi = {
    key: string;
    label: string;
    value: number | null;
    format: 'money' | 'count' | 'signed';
    sub: { text: string; tone: 'up' | 'down' | 'warn' | 'neutral' } | null;
};

type Metrics = {
    kpis: Kpi[];
    chart: {
        bucket: 'hour' | 'day';
        points: { at: string; payin: number; payout: number }[];
        payin_total: number;
        payout_total: number;
    };
    outcome: {
        success: number;
        pending: number;
        failed: number;
        total: number;
        rate: number | null;
        avg_approval_seconds: number | null;
        avg_ticket: number | null;
    };
    attention: {
        key: string;
        label: string;
        sub: string;
        value: string;
        tone: string;
        target: string | null;
    }[];
    table: {
        title: string;
        columns: string[];
        rows: Record<string, string | number | null>[];
    };
    generated_at: string;
};

export type DashboardProps = {
    portal: UserType;
    period: { range: string; from: string; to: string; label: string };
    metrics: Metrics;
    organisation: string | null;
};

const RANGES: [string, string][] = [
    ['today', 'Today'],
    ['yesterday', 'Yesterday'],
    ['1h', '1h'],
    ['24h', '24h'],
    ['7d', '7d'],
    ['30d', '30d'],
    ['this_month', 'This month'],
    ['prev_month', 'Prev month'],
    ['custom', 'Custom'],
];

const SUB_TONES = {
    up: 'text-ok',
    down: 'text-er',
    warn: 'text-wn',
    neutral: 'text-tx3',
};

/** Payouts in amber: validated against each portal accent (Phase 11). */
const PAYOUT_COLOR = '#d97706';

/** Where each "needs attention" item leads, per portal. */
function targetUrl(portal: UserType, target: string | null): string | null {
    const links: Record<UserType, Record<string, () => { url: string }>> = {
        admin: {
            deposits: admin.deposits.index,
            cases: admin.cases.index,
            payouts: admin.payouts.index,
            accounts: admin.accounts.index,
            adjustments: admin.adjustments.index,
        },
        branch: {
            deposits: branch.deposits.index,
            cases: branch.cases.index,
            payouts: branch.payouts.index,
            accounts: branch.accounts.index,
        },
        partner: {
            payins: partner.payins.index,
            payout_history: partner.payouts.index,
            developers: partner.developers.show,
        },
    };

    const route = target ? links[portal][target] : undefined;

    return route ? route().url : null;
}

/** "₹4.82 Cr", "₹38.42 L", "₹9,400" for tiles. */
function compact(paise: number): string {
    const rupees = Math.abs(paise) / 100;
    const sign = paise < 0 ? '−' : '';

    if (rupees >= 1e7) return `${sign}₹${(rupees / 1e7).toFixed(2)} Cr`;
    if (rupees >= 1e5) return `${sign}₹${(rupees / 1e5).toFixed(2)} L`;

    return sign + formatPaise(Math.abs(paise), rupees >= 1000 ? 0 : 2);
}

function kpiValue(kpi: Kpi): string {
    if (kpi.value === null) return '—';
    if (kpi.format === 'count') return kpi.value.toLocaleString('en-IN');
    if (kpi.format === 'signed')
        return (kpi.value > 0 ? '+' : '') + compact(kpi.value);

    return compact(kpi.value);
}

function duration(seconds: number | null): string {
    if (seconds === null) return '—';
    const minutes = Math.floor(seconds / 60);

    return minutes >= 60
        ? `${Math.floor(minutes / 60)}h ${minutes % 60}m`
        : `${minutes}m ${seconds % 60}s`;
}

/**
 * The live dashboard of every portal (design: range buttons, KPI tiles,
 * pay-in vs payout volume, outcome, needs attention, a table). Figures
 * refresh every 30 seconds.
 */
export function LiveDashboard({
    portal,
    period,
    metrics,
    title,
    description,
    tableCell,
}: DashboardProps & {
    title: string;
    description: string;
    tableCell: (
        row: Record<string, string | number | null>,
        column: number,
    ) => ReactNode;
}) {
    const home = { admin, branch, partner }[portal].dashboard;
    const [custom, setCustom] = useState({
        from: period.from.slice(0, 10),
        to: period.to.slice(0, 10),
    });
    const [showCustom, setShowCustom] = useState(period.range === 'custom');
    const [, tick] = useState(0);

    useEffect(() => {
        const refresh = setInterval(
            () => router.reload({ only: ['metrics'] }),
            30000,
        );
        const clock = setInterval(() => tick((n) => n + 1), 5000);

        return () => {
            clearInterval(refresh);
            clearInterval(clock);
        };
    }, []);

    const go = (query: Record<string, string>) =>
        router.get(home({ query }).url, {}, { preserveState: true });

    const o = metrics.outcome;
    const decided = o.success + o.failed + o.pending;
    const pct = (n: number) => (decided === 0 ? 0 : (n / decided) * 100);

    return (
        <>
            <Head title={title} />
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <div className="flex items-center gap-1.5 text-xs font-medium text-ok">
                        <span className="size-1.5 animate-pulse rounded-full bg-ok" />
                        Live · refreshed{' '}
                        {formatRelative(metrics.generated_at).toLowerCase()}
                    </div>
                    <h1 className="mt-1 text-[22px] font-semibold tracking-[-.015em]">
                        {title}
                    </h1>
                    <p className="mt-1 text-[13px] text-tx2">{description}</p>
                </div>
                <div className="flex flex-col items-end gap-2">
                    <div className="flex flex-wrap gap-0.5 rounded-lg border border-ln bg-sf p-0.5">
                        {RANGES.map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() =>
                                    key === 'custom'
                                        ? setShowCustom(true)
                                        : (setShowCustom(false),
                                          go({ range: key }))
                                }
                                className={cn(
                                    'h-7 rounded-md px-2.5 text-[12.5px] font-medium',
                                    (showCustom ? 'custom' : period.range) ===
                                        key
                                        ? 'bg-linear-to-r from-ac to-ac2 text-white shadow-sm shadow-ac/30'
                                        : 'text-tx2 hover:bg-sf2',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    {showCustom && (
                        <form
                            className="flex items-center gap-1.5 text-[12.5px]"
                            onSubmit={(event) => {
                                event.preventDefault();
                                go({ range: 'custom', ...custom });
                            }}
                        >
                            <input
                                type="date"
                                value={custom.from}
                                onChange={(event) =>
                                    setCustom({
                                        ...custom,
                                        from: event.target.value,
                                    })
                                }
                                className="h-7 rounded-md border border-ln bg-sf px-2"
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
                                className="h-7 rounded-md border border-ln bg-sf px-2"
                            />
                            <button
                                type="submit"
                                className="h-7 rounded-md bg-linear-to-r from-ac to-ac2 px-2.5 font-medium text-white"
                            >
                                Show
                            </button>
                        </form>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-[repeat(auto-fill,minmax(190px,1fr))] gap-2.5">
                {metrics.kpis.map((kpi) => (
                    <div
                        key={kpi.key}
                        className="rounded-[10px] border border-ln bg-sf px-3.5 py-3"
                    >
                        <div className="text-xs text-tx2">{kpi.label}</div>
                        <div
                            className={cn(
                                'mt-1 text-[19px] font-semibold tracking-[-.02em]',
                                kpi.format === 'signed' &&
                                    (kpi.value ?? 0) < 0 &&
                                    'text-er',
                            )}
                            title={
                                kpi.format === 'count' || kpi.value === null
                                    ? undefined
                                    : formatPaise(kpi.value)
                            }
                        >
                            {kpiValue(kpi)}
                        </div>
                        {kpi.sub && (
                            <div
                                className={cn(
                                    'mt-0.5 truncate text-xs',
                                    SUB_TONES[kpi.sub.tone],
                                )}
                                title={kpi.sub.text}
                            >
                                {kpi.sub.text}
                            </div>
                        )}
                    </div>
                ))}
            </div>

            <div className="grid gap-3 lg:grid-cols-[1fr_340px]">
                <VolumeChart chart={metrics.chart} period={period} />

                <Panel className="flex flex-col gap-3.5 px-[18px] py-4">
                    <div>
                        <div className="text-sm font-semibold">
                            Transaction outcome
                        </div>
                        <div className="text-xs text-tx3">
                            {o.total.toLocaleString('en-IN')} created in the
                            period
                        </div>
                    </div>
                    <div className="flex items-center gap-[18px]">
                        <div
                            className="grid size-32 flex-none place-items-center rounded-full"
                            role="img"
                            aria-label={`${o.success} successful, ${o.pending} pending, ${o.failed} failed`}
                            style={{
                                background:
                                    decided === 0
                                        ? 'var(--pg-sf2)'
                                        : `conic-gradient(var(--pg-ok) 0 ${pct(o.success)}%, var(--pg-wn) ${pct(o.success)}% ${pct(o.success + o.pending)}%, var(--pg-er) ${pct(o.success + o.pending)}% 100%)`,
                            }}
                        >
                            <div className="grid size-[94px] place-items-center rounded-full bg-sf text-center">
                                <div>
                                    <div className="text-[21px] font-semibold tracking-[-.02em]">
                                        {o.rate === null ? '—' : `${o.rate}%`}
                                    </div>
                                    <div className="text-[11px] text-tx3">
                                        success
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="flex flex-1 flex-col gap-2 text-[13px]">
                            {(
                                [
                                    ['success', 'Success', o.success],
                                    ['pending', 'Pending', o.pending],
                                    ['failed', 'Failed', o.failed],
                                ] as const
                            ).map(([status, label, value]) => (
                                <div
                                    key={status}
                                    className="flex items-center justify-between"
                                >
                                    <span className="flex items-center gap-1.5">
                                        <span
                                            className={cn(
                                                'grid size-[18px] place-items-center rounded text-[10px]',
                                                TONE_CLASSES[
                                                    statusStyle(status).tone
                                                ],
                                            )}
                                        >
                                            {statusStyle(status).icon}
                                        </span>
                                        {label}
                                    </span>
                                    <b>{value.toLocaleString('en-IN')}</b>
                                </div>
                            ))}
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-2 border-t border-ln2 pt-3 text-xs text-tx3">
                        <div>
                            <div>Avg. approval time</div>
                            <div className="text-[15px] font-semibold text-tx">
                                {duration(o.avg_approval_seconds)}
                            </div>
                        </div>
                        <div>
                            <div>Avg. ticket size</div>
                            <div className="text-[15px] font-semibold text-tx">
                                {o.avg_ticket === null
                                    ? '—'
                                    : formatPaise(o.avg_ticket, 0)}
                            </div>
                        </div>
                    </div>
                </Panel>
            </div>

            <div className="grid gap-3 lg:grid-cols-[380px_1fr]">
                <Panel className="flex flex-col overflow-hidden">
                    <div className="flex items-center justify-between border-b border-ln2 px-4 py-3">
                        <span className="text-sm font-semibold">
                            Needs attention
                        </span>
                        <span className="text-xs text-tx3">
                            Operational queue
                        </span>
                    </div>
                    {metrics.attention.map((item) => {
                        const url = targetUrl(portal, item.target);
                        const style = statusStyle(item.tone);
                        const body = (
                            <>
                                <span
                                    className={cn(
                                        'grid size-7 flex-none place-items-center rounded-md text-xs',
                                        TONE_CLASSES[style.tone],
                                    )}
                                >
                                    {style.icon}
                                </span>
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="text-[13px] font-medium">
                                        {item.label}
                                    </span>
                                    <span className="truncate text-xs text-tx3">
                                        {item.sub}
                                    </span>
                                </span>
                                <span className="text-[13px] font-semibold whitespace-nowrap">
                                    {item.value}
                                </span>
                                {url && <span className="text-tx3">›</span>}
                            </>
                        );

                        return url ? (
                            <Link
                                key={item.key}
                                href={url}
                                className="flex items-center gap-3 border-b border-ln2 px-4 py-2.5 last:border-0 hover:bg-sf2"
                            >
                                {body}
                            </Link>
                        ) : (
                            <div
                                key={item.key}
                                className="flex items-center gap-3 border-b border-ln2 px-4 py-2.5 last:border-0"
                            >
                                {body}
                            </div>
                        );
                    })}
                </Panel>

                <Panel className="overflow-hidden">
                    <div className="flex items-center justify-between border-b border-ln2 px-4 py-3">
                        <span className="text-sm font-semibold">
                            {metrics.table.title}
                        </span>
                        <Link
                            href={
                                { admin, branch, partner }[
                                    portal
                                ].reports.index().url
                            }
                            className="text-xs font-medium text-ac"
                        >
                            View reports →
                        </Link>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-[13px]">
                            <thead>
                                <tr className="bg-sf2 text-left text-xs text-tx3">
                                    {metrics.table.columns.map(
                                        (column, index) => (
                                            <th
                                                key={column}
                                                className={cn(
                                                    'px-4 py-2 font-medium',
                                                    index > 0 && 'text-right',
                                                )}
                                            >
                                                {column}
                                            </th>
                                        ),
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {metrics.table.rows.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                metrics.table.columns.length
                                            }
                                            className="px-4 py-8 text-center text-xs text-tx3"
                                        >
                                            Nothing in this period yet.
                                        </td>
                                    </tr>
                                )}
                                {metrics.table.rows.map((row, rowIndex) => (
                                    <tr
                                        key={rowIndex}
                                        className="border-t border-ln2"
                                    >
                                        {metrics.table.columns.map(
                                            (_, index) => (
                                                <td
                                                    key={index}
                                                    className={cn(
                                                        'px-4 py-2.5',
                                                        index > 0 &&
                                                            'text-right whitespace-nowrap',
                                                    )}
                                                >
                                                    {tableCell(row, index)}
                                                </td>
                                            ),
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            </div>
        </>
    );
}

/** A table cell: name with a muted second line. */
export function NameCell({
    row,
}: {
    row: Record<string, string | number | null>;
}) {
    return (
        <div>
            <div className="font-medium">{row.name}</div>
            <div className="text-xs text-tx3">{row.sub}</div>
        </div>
    );
}

/** A utilisation / success bar with its percentage. */
export function PercentCell({
    value,
    invert = false,
}: {
    value: number | null;
    invert?: boolean;
}) {
    if (value === null) return <span className="text-tx3">—</span>;

    const good = invert ? value <= 75 : value >= 90;
    const warn = invert ? value <= 95 : value >= 80;

    return (
        <div className="flex items-center justify-end gap-2">
            <div className="h-1.5 w-20 overflow-hidden rounded-full bg-sf2">
                <div
                    className={cn(
                        'h-full rounded-full',
                        good ? 'bg-ok' : warn ? 'bg-wn' : 'bg-er',
                    )}
                    style={{ width: `${Math.min(value, 100)}%` }}
                />
            </div>
            <span className="w-12 text-xs">{value}%</span>
        </div>
    );
}

export function StatusCell({ status }: { status: string }) {
    return <StatusBadge status={status} />;
}

/**
 * Pay-in vs payout volume per hour or day: paired bars from a shared
 * baseline, a legend with totals, a tooltip on each bucket.
 */
function VolumeChart({
    chart,
    period,
}: {
    chart: Metrics['chart'];
    period: DashboardProps['period'];
}) {
    const [hover, setHover] = useState<number | null>(null);
    const max = Math.max(
        1,
        ...chart.points.map((point) => Math.max(point.payin, point.payout)),
    );
    const every = Math.max(1, Math.ceil(chart.points.length / 12));
    const label = (at: string) =>
        chart.bucket === 'hour' ? at.slice(11, 13) : at.slice(8, 10);
    const active = hover === null ? null : chart.points[hover];

    return (
        <Panel className="flex flex-col gap-3 px-[18px] py-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div className="text-sm font-semibold">
                        Pay-in vs Pay-out volume
                    </div>
                    <div className="text-xs text-tx3">
                        {chart.bucket === 'hour' ? 'Hourly' : 'Daily'} ·{' '}
                        {period.label} · INR, successful only
                    </div>
                </div>
                <div className="flex gap-3 text-xs text-tx2">
                    <span className="flex items-center gap-1.5">
                        <span className="size-2.5 rounded-sm bg-ac" />
                        Pay-in{' '}
                        <b className="text-tx">{compact(chart.payin_total)}</b>
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span
                            className="size-2.5 rounded-sm"
                            style={{ background: PAYOUT_COLOR }}
                        />
                        Pay-out{' '}
                        <b className="text-tx">{compact(chart.payout_total)}</b>
                    </span>
                </div>
            </div>
            <div className="relative">
                <div
                    className="flex h-[180px] items-end gap-[3px] border-b border-ln"
                    onMouseLeave={() => setHover(null)}
                >
                    {chart.points.map((point, index) => (
                        <div
                            key={point.at}
                            onMouseEnter={() => setHover(index)}
                            className={cn(
                                'flex h-full flex-1 items-end gap-[2px] rounded-t-sm',
                                hover === index && 'bg-sf2',
                            )}
                        >
                            <div
                                className="flex-1 rounded-t bg-ac"
                                style={{
                                    height: `${(point.payin / max) * 100}%`,
                                    minHeight: point.payin > 0 ? 2 : 0,
                                }}
                            />
                            <div
                                className="flex-1 rounded-t"
                                style={{
                                    height: `${(point.payout / max) * 100}%`,
                                    minHeight: point.payout > 0 ? 2 : 0,
                                    background: PAYOUT_COLOR,
                                }}
                            />
                        </div>
                    ))}
                </div>
                {active && (
                    <div className="pointer-events-none absolute top-0 left-1/2 -translate-x-1/2 rounded-md border border-ln bg-sf px-2.5 py-1.5 text-xs shadow-sm">
                        <div className="font-medium">
                            {chart.bucket === 'hour'
                                ? `${active.at.slice(0, 10)} ${active.at.slice(11, 16)}`
                                : active.at.slice(0, 10)}
                        </div>
                        <div>Pay-in {formatPaise(active.payin)}</div>
                        <div>Pay-out {formatPaise(active.payout)}</div>
                    </div>
                )}
            </div>
            <div className="flex gap-[3px] text-center text-[10.5px] text-tx3">
                {chart.points.map((point, index) => (
                    <div key={point.at} className="flex-1">
                        {index % every === 0 ? label(point.at) : ''}
                    </div>
                ))}
            </div>
        </Panel>
    );
}
