import { router } from '@inertiajs/react';
import { Banknote, ChevronDown, ListOrdered } from 'lucide-react';
import { Component, Suspense, lazy, useState } from 'react';
import type { ReactNode } from 'react';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { UserType } from '@/types';
import { Panel } from './data-table';

const ApexChart = lazy(() => import('./apex-chart'));

/** Shapes from App\Domain\Reporting\DashboardOverview. */
type Figures = {
    total_count: number;
    total_amount: number;
    success_count: number;
    success_amount: number;
    failed_count: number;
    failed_amount: number;
};

export type Overview = {
    direction: 'payin' | 'payout';
    summary: Figures;
    bucket: 'hour' | 'day';
    points: (Figures & { at: string })[];
    methods: { method: string; count: number; amount: number }[];
};

type Org = { id: string; code: string; name: string };

export type OverviewFilters = {
    direction: 'payin' | 'payout';
    partners: string[];
    branches: string[];
};

export type OverviewOptions = { partners: Org[]; branches: Org[] } | null;

type Period = { range: string; from: string; to: string; label: string };

const RANGES: [string, string][] = [
    ['1h', '1h'],
    ['24h', '24h'],
    ['7d', '7 Days'],
    ['30d', '30 Days'],
    ['today', 'Today'],
    ['yesterday', 'Yesterday'],
    ['this_month', 'This Month'],
    ['prev_month', 'Previous Month'],
];

const METHODS: Record<string, string> = {
    upi: 'UPI',
    qr: 'QR',
    upi_intent: 'UPI Intent',
    bank_transfer: 'Bank transfer',
    other: 'Other',
};

const COLORS = {
    total: '#2563eb',
    success: '#16a34a',
    failed: '#dc2626',
};

/** "2026-09-25T14:30" in business time, for the date-time pickers. */
const local = (iso: string) => iso.slice(0, 16);

/**
 * The top of the dashboard, laid out like the client's existing system:
 * range buttons, filters (admin: partners and branches) with a date-time
 * range, an Amount / Count switch, total / successful / failed cards and
 * four charts.
 */
export function DashboardOverview({
    portal,
    home,
    period,
    filters,
    options,
    overview,
}: {
    portal: UserType;
    home: string;
    period: Period;
    filters: OverviewFilters;
    options: OverviewOptions;
    overview: Overview;
}) {
    const [measure, setMeasure] = useState<'amount' | 'count'>('amount');
    const [draft, setDraft] = useState({
        partners: filters.partners,
        branches: filters.branches,
        from: local(period.from),
        to: local(period.to),
    });

    const visit = (query: Record<string, string | string[]>) =>
        router.get(
            home,
            {
                direction: filters.direction,
                partners: filters.partners,
                branches: filters.branches,
                ...query,
            },
            { preserveState: true, preserveScroll: true },
        );

    const isAmount = measure === 'amount';
    const format = (value: number) =>
        isAmount
            ? compactRupees(value)
            : Math.round(value).toLocaleString('en-IN');
    const pick = (figures: Figures, key: 'total' | 'success' | 'failed') =>
        figures[`${key}_${measure}`];
    const slot = (at: string) =>
        overview.bucket === 'hour'
            ? `${at.slice(8, 10)}-${monthName(at)} ${at.slice(11, 16)}`
            : `${at.slice(8, 10)}-${monthName(at)}`;
    const noun = filters.direction === 'payin' ? 'Pay-in' : 'Pay-out';

    return (
        <div className="flex flex-col gap-3">
            {/* Range buttons */}
            <div className="flex flex-wrap items-center gap-2">
                {RANGES.map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => visit({ range: key })}
                        className={cn(
                            'h-9 rounded-lg border px-4 text-[13px] font-medium transition-colors',
                            period.range === key
                                ? 'border-transparent bg-brand text-white'
                                : 'border-ln bg-sf text-tx2 hover:border-ac/40 hover:text-tx',
                        )}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {/* Filters */}
            <form
                className="flex flex-wrap items-center gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    visit({
                        range: 'custom',
                        from: draft.from,
                        to: draft.to,
                        partners: draft.partners,
                        branches: draft.branches,
                    });
                }}
            >
                {options && (
                    <>
                        <MultiSelect
                            noun="partners"
                            items={options.partners}
                            selected={draft.partners}
                            onChange={(partners) =>
                                setDraft({ ...draft, partners })
                            }
                        />
                        <MultiSelect
                            noun="branches"
                            items={options.branches}
                            selected={draft.branches}
                            onChange={(branches) =>
                                setDraft({ ...draft, branches })
                            }
                        />
                    </>
                )}
                <input
                    type="datetime-local"
                    aria-label="From"
                    value={draft.from}
                    onChange={(event) =>
                        setDraft({ ...draft, from: event.target.value })
                    }
                    className="h-10 min-w-[200px] flex-1 rounded-lg border border-ln bg-sf px-3 text-[13px]"
                />
                <input
                    type="datetime-local"
                    aria-label="To"
                    value={draft.to}
                    onChange={(event) =>
                        setDraft({ ...draft, to: event.target.value })
                    }
                    className="h-10 min-w-[200px] flex-1 rounded-lg border border-ln bg-sf px-3 text-[13px]"
                />
                <button
                    type="submit"
                    className="h-10 min-w-[140px] rounded-lg bg-brand px-6 text-[13px] font-semibold text-white hover:brightness-110"
                >
                    Submit
                </button>
            </form>

            {/* Pay-in / Pay-out and Amount / Count */}
            <div className="flex flex-wrap items-center gap-3">
                <Toggle
                    value={filters.direction}
                    options={[
                        ['payin', 'Pay-in'],
                        ['payout', 'Pay-out'],
                    ]}
                    onChange={(direction) =>
                        visit({
                            direction,
                            range: period.range,
                            ...(period.range === 'custom'
                                ? {
                                      from: local(period.from),
                                      to: local(period.to),
                                  }
                                : {}),
                        })
                    }
                />
                <Toggle
                    value={measure}
                    options={[
                        ['amount', 'Amount'],
                        ['count', 'Count'],
                    ]}
                    onChange={setMeasure}
                />
                <span className="text-xs text-tx3">{period.label}</span>
            </div>

            {/* Total / successful / failed */}
            <div className="grid gap-3 lg:grid-cols-3">
                <SummaryCard
                    title={`Total ${noun} Transactions`}
                    color={COLORS.total}
                    count={overview.summary.total_count}
                    amount={overview.summary.total_amount}
                />
                <SummaryCard
                    title="Successful Transactions"
                    color={COLORS.success}
                    count={overview.summary.success_count}
                    amount={overview.summary.success_amount}
                />
                <SummaryCard
                    title="Failed Transactions"
                    color={COLORS.failed}
                    count={overview.summary.failed_count}
                    amount={overview.summary.failed_amount}
                />
            </div>

            {/* Charts */}
            <div className="grid gap-3 xl:grid-cols-2">
                <ChartPanel title="Successful Transactions">
                    <ApexChart
                        type="area"
                        name={`Successful (${measure})`}
                        color={COLORS.success}
                        categories={overview.points.map((p) => slot(p.at))}
                        values={overview.points.map((p) => pick(p, 'success'))}
                        xTitle="Time"
                        yTitle={isAmount ? 'Amount' : 'Count'}
                        format={format}
                    />
                </ChartPanel>
                <ChartPanel title="Failed Transactions">
                    <ApexChart
                        type="area"
                        name={`Failed (${measure})`}
                        color={COLORS.failed}
                        categories={overview.points.map((p) => slot(p.at))}
                        values={overview.points.map((p) => pick(p, 'failed'))}
                        xTitle="Time"
                        yTitle={isAmount ? 'Amount' : 'Count'}
                        format={format}
                    />
                </ChartPanel>
                <ChartPanel title={`Total ${noun} Transactions`}>
                    <ApexChart
                        type="area"
                        name={`Total (${measure})`}
                        color={COLORS.total}
                        categories={overview.points.map((p) => slot(p.at))}
                        values={overview.points.map((p) => pick(p, 'total'))}
                        xTitle="Time"
                        yTitle={isAmount ? 'Amount' : 'Count'}
                        format={format}
                    />
                </ChartPanel>
                <ChartPanel title="Payment Method (successful)">
                    {overview.methods.length === 0 ? (
                        <div className="grid h-[300px] place-items-center text-[13px] text-tx3">
                            No successful {noun.toLowerCase()}s in this period
                            {portal === 'admin' ? ' for this selection' : ''}.
                        </div>
                    ) : (
                        <ApexChart
                            type="bar"
                            name={`Successful (${measure})`}
                            color={COLORS.total}
                            categories={overview.methods.map(
                                (m) => METHODS[m.method] ?? m.method,
                            )}
                            values={overview.methods.map((m) => m[measure])}
                            yTitle={isAmount ? 'Amount' : 'Count'}
                            format={format}
                        />
                    )}
                </ChartPanel>
            </div>
        </div>
    );
}

/** "₹13.3 L" style for chart labels; exact amounts in the cards. */
function compactRupees(paise: number): string {
    const rupees = paise / 100;

    if (rupees >= 1e7) return `₹${(rupees / 1e7).toFixed(2)} Cr`;
    if (rupees >= 1e5) return `₹${(rupees / 1e5).toFixed(2)} L`;
    if (rupees >= 1e3) return `₹${(rupees / 1e3).toFixed(1)} K`;

    return `₹${Math.round(rupees)}`;
}

function monthName(at: string): string {
    return [
        'Jan',
        'Feb',
        'Mar',
        'Apr',
        'May',
        'Jun',
        'Jul',
        'Aug',
        'Sep',
        'Oct',
        'Nov',
        'Dec',
    ][Number(at.slice(5, 7)) - 1];
}

function Toggle<T extends string>({
    value,
    options,
    onChange,
}: {
    value: T;
    options: [T, string][];
    onChange: (value: T) => void;
}) {
    return (
        <div className="flex rounded-lg border border-ln bg-sf p-[3px]">
            {options.map(([key, label]) => (
                <button
                    key={key}
                    type="button"
                    onClick={() => onChange(key)}
                    className={cn(
                        'h-7 rounded-md px-3 text-xs font-semibold',
                        value === key
                            ? 'bg-[#1c0b36] text-white dark:bg-white dark:text-[#1c0b36]'
                            : 'text-tx2 hover:text-tx',
                    )}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}

/** A card with a coloured top edge: count and amount tiles. */
function SummaryCard({
    title,
    color,
    count,
    amount,
}: {
    title: string;
    color: string;
    count: number;
    amount: number;
}) {
    return (
        <Panel className="overflow-hidden">
            <div className="h-1.5" style={{ background: color }} />
            <div className="@container flex flex-col gap-2.5 px-4 pt-3 pb-4">
                <div className="flex items-center gap-2 text-[13px] font-semibold">
                    <span
                        className="size-2 rounded-full"
                        style={{ background: color }}
                    />
                    {title}
                </div>
                {/* Side by side when the card is wide enough, else stacked,
                    so exact amounts are never cut off. */}
                <div className="grid gap-2.5 @[460px]:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                    <Tile
                        color={color}
                        icon={<ListOrdered className="size-5" />}
                        label="Count"
                        value={count.toLocaleString('en-IN')}
                    />
                    <Tile
                        color={color}
                        icon={<Banknote className="size-5" />}
                        label="Amount"
                        value={formatPaise(amount)}
                    />
                </div>
            </div>
        </Panel>
    );
}

function Tile({
    color,
    icon,
    label,
    value,
}: {
    color: string;
    icon: ReactNode;
    label: string;
    value: string;
}) {
    return (
        <div className="flex min-w-0 items-center gap-3 rounded-lg border border-ln2 px-3 py-2.5">
            <span
                className="grid size-11 flex-none place-items-center rounded-lg"
                style={{ color, background: `${color}1a` }}
            >
                {icon}
            </span>
            <span className="min-w-0">
                <span className="block text-xs text-tx3">{label}</span>
                <span
                    className="block truncate text-[22px] leading-tight font-bold tracking-[-.02em]"
                    title={value}
                >
                    {value}
                </span>
            </span>
        </div>
    );
}

function ChartPanel({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <Panel className="px-3 pt-3 pb-1">
            <div className="px-1 text-[13px] font-semibold">{title}</div>
            <ChartBoundary>
                <Suspense
                    fallback={
                        <div className="grid h-[300px] place-items-center text-xs text-tx3">
                            Loading chart…
                        </div>
                    }
                >
                    {children}
                </Suspense>
            </ChartBoundary>
        </Panel>
    );
}

/**
 * If a chart can't load (e.g. its script failed to download), show a note in
 * its panel instead of taking the whole dashboard down.
 */
class ChartBoundary extends Component<
    { children: ReactNode },
    { failed: boolean }
> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    render() {
        return this.state.failed ? (
            <div className="grid h-[300px] place-items-center text-[13px] text-tx3">
                This chart couldn't load. Refresh the page to try again.
            </div>
        ) : (
            this.props.children
        );
    }
}

/** Pick some partners or branches: "103 HP +3" chip, checklist with search. */
function MultiSelect({
    noun,
    items,
    selected,
    onChange,
}: {
    noun: string;
    items: Org[];
    selected: string[];
    onChange: (ids: string[]) => void;
}) {
    const [search, setSearch] = useState('');
    const term = search.trim().toLowerCase();
    const visible = items.filter(
        (item) =>
            term === '' ||
            item.code.toLowerCase().includes(term) ||
            item.name.toLowerCase().includes(term),
    );
    const first = items.find((item) => item.id === selected[0]);
    const toggle = (id: string) =>
        onChange(
            selected.includes(id)
                ? selected.filter((value) => value !== id)
                : [...selected, id],
        );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="flex h-10 min-w-[220px] flex-1 items-center gap-2 rounded-lg border border-ln bg-sf px-3 text-left text-[13px]">
                {first ? (
                    <span className="flex min-w-0 flex-1 items-center gap-1.5">
                        <span className="truncate rounded-md bg-acs px-2 py-0.5 text-xs font-semibold text-act">
                            {first.code} {first.name}
                        </span>
                        {selected.length > 1 && (
                            <span className="text-xs text-tx3">
                                +{selected.length - 1}
                            </span>
                        )}
                    </span>
                ) : (
                    <span className="flex-1 text-tx3">All {noun}</span>
                )}
                <ChevronDown className="size-4 flex-none text-tx3" />
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-80 w-[var(--radix-dropdown-menu-trigger-width)] overflow-y-auto"
            >
                <div className="p-1">
                    <input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        onKeyDown={(event) => event.stopPropagation()}
                        placeholder={`Search ${noun}`}
                        className="h-8 w-full rounded-md border border-ln bg-sf px-2 text-[13px]"
                    />
                </div>
                <DropdownMenuLabel className="flex items-center justify-between text-xs text-tx3">
                    {selected.length === 0
                        ? `All ${noun}`
                        : `${selected.length} selected`}
                    {selected.length > 0 && (
                        <button
                            type="button"
                            onClick={() => onChange([])}
                            className="font-medium text-ac"
                        >
                            Clear
                        </button>
                    )}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {visible.map((item) => (
                    <DropdownMenuCheckboxItem
                        key={item.id}
                        checked={selected.includes(item.id)}
                        onCheckedChange={() => toggle(item.id)}
                        onSelect={(event) => event.preventDefault()}
                    >
                        <span className="font-medium">{item.code}</span>
                        <span className="truncate text-tx3">{item.name}</span>
                    </DropdownMenuCheckboxItem>
                ))}
                {visible.length === 0 && (
                    <div className="px-2 py-3 text-center text-xs text-tx3">
                        No {noun} match.
                    </div>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
