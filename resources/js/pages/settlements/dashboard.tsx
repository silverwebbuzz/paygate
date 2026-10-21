import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Panel } from '@/components/pg/data-table';
import { MultiSelect } from '@/components/pg/multi-select';
import { PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import branch from '@/routes/branch';
import partner from '@/routes/partner';
import type { UserType } from '@/types';

type Side = 'admin' | 'branch' | 'partner';
type Org = { id: string; code: string; name: string };
type Flow = {
    count: number;
    amount: number;
    commission: number;
    rate: number | null;
};

/** Shape from App\Domain\Reporting\SettlementDashboard::figures(). */
type Figures = {
    deposit: Flow;
    withdraw: Flow;
    commission: number;
    margin: number;
    chargeback: { count: number; amount: number };
    adjustments: number;
    settlement: number;
    current: number;
    net: number;
    opening: number;
    closing: number;
};

type Pair = Figures & {
    partner: { code: string; name: string };
    branch: { code: string; name: string };
};

type Props = {
    portal: UserType;
    side: Side;
    period: { range: string; from: string; to: string; label: string };
    filters: { partners: string[]; branches: string[] };
    options: { partners: Org[]; branches: Org[] } | null;
    figures:
        | { admin: { partner: Figures; branch: Figures; margin: number } }
        | { total: Figures; pairs: Pair[] };
};

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

const TITLES: Record<Side, string> = {
    admin: 'Admin Settlement Dashboard',
    branch: 'Branch Settlement Dashboard',
    partner: 'Partner Settlement Dashboard',
};

/** "2026-09-25T14:30" in business time, for the date-time pickers. */
const local = (iso: string) => iso.slice(0, 16);

/**
 * Settlement Dashboard, as in the client's system: range buttons, dates and
 * filters, deposit / withdraw tiles with the commission, and the summary
 * (Deposit, Withdraw, Commission, Settlement, Chargeback, Current Balance,
 * Net Balance). Chargebacks count on the day Admin approved them.
 */
export default function SettlementDashboard({
    portal,
    side,
    period,
    filters,
    options,
    figures,
}: Props) {
    const [draft, setDraft] = useState({
        partners: filters.partners,
        branches: filters.branches,
        from: local(period.from),
        to: local(period.to),
    });

    const url = (to: Side = side) =>
        portal === 'admin'
            ? admin.settlementDashboard(to).url
            : portal === 'branch'
              ? branch.settlementDashboard().url
              : partner.settlementDashboard().url;

    const visit = (query: Record<string, string | string[]>, to?: Side) =>
        router.get(
            url(to),
            {
                partners: filters.partners,
                branches: filters.branches,
                ...query,
            },
            { preserveState: false, preserveScroll: true },
        );

    const keepPeriod: Record<string, string> =
        period.range === 'custom'
            ? {
                  range: 'custom',
                  from: local(period.from),
                  to: local(period.to),
              }
            : { range: period.range };

    return (
        <>
            <Head title={TITLES[side]} />
            <PageHeader
                title={TITLES[side]}
                description={
                    portal === 'partner'
                        ? 'What moved on your balance in the period. Chargebacks count on the day Admin approved them.'
                        : 'What moved on the positions in the period, as settlements calculate it. Chargebacks count on the day Admin approved them.'
                }
                actions={
                    portal === 'admin' && (
                        <Segmented
                            options={[
                                { value: 'admin', label: 'Admin' },
                                { value: 'branch', label: 'Branch' },
                                { value: 'partner', label: 'Partner' },
                            ]}
                            value={side}
                            onChange={(to) => visit(keepPeriod, to)}
                        />
                    )
                }
            />

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

            <Panel className="p-3">
                <form
                    className="flex flex-wrap items-end gap-2"
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
                    <label className="flex min-w-[200px] flex-1 flex-col gap-1 text-xs text-tx2">
                        Start Date
                        <input
                            type="datetime-local"
                            value={draft.from}
                            onChange={(event) =>
                                setDraft({ ...draft, from: event.target.value })
                            }
                            className="h-10 rounded-lg border border-ln bg-sf px-3 text-[13px] text-tx"
                        />
                    </label>
                    <label className="flex min-w-[200px] flex-1 flex-col gap-1 text-xs text-tx2">
                        End Date
                        <input
                            type="datetime-local"
                            value={draft.to}
                            onChange={(event) =>
                                setDraft({ ...draft, to: event.target.value })
                            }
                            className="h-10 rounded-lg border border-ln bg-sf px-3 text-[13px] text-tx"
                        />
                    </label>
                    {/* The dashboard's own side first: partners on the partner view. */}
                    {(side === 'partner'
                        ? (['partners', 'branches'] as const)
                        : (['branches', 'partners'] as const)
                    ).map(
                        (noun) =>
                            options &&
                            options[noun].length > 0 && (
                                <MultiSelect
                                    key={noun}
                                    noun={noun}
                                    items={options[noun]}
                                    selected={draft[noun]}
                                    onChange={(ids) =>
                                        setDraft({ ...draft, [noun]: ids })
                                    }
                                />
                            ),
                    )}
                    <button
                        type="submit"
                        className="h-10 min-w-[140px] rounded-lg bg-brand px-6 text-[13px] font-semibold text-white hover:brightness-110"
                    >
                        Search
                    </button>
                </form>
                <div className="mt-2 text-xs text-tx3">{period.label}</div>
            </Panel>

            {'admin' in figures ? (
                <AdminView figures={figures.admin} />
            ) : (
                <>
                    <PartyView
                        figures={figures.total}
                        side={side as 'branch' | 'partner'}
                        own={portal !== 'admin'}
                    />
                    {figures.pairs.length > 0 && (
                        <Pairs
                            pairs={figures.pairs}
                            side={side as 'branch' | 'partner'}
                            portal={portal}
                        />
                    )}
                </>
            )}
        </>
    );
}

function PartyView({
    figures,
    side,
    own,
}: {
    figures: Figures;
    side: 'branch' | 'partner';
    own: boolean;
}) {
    return (
        <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
            <FlowTiles figures={figures} />
            <Summary
                figures={figures}
                commissionLabel={
                    side === 'partner'
                        ? own
                            ? 'Fees'
                            : 'Partner fees'
                        : own
                          ? 'Commission (yours)'
                          : 'Branch commission'
                }
                party={own ? 'you' : side === 'partner' ? 'partner' : 'branch'}
            />
        </div>
    );
}

function AdminView({
    figures,
}: {
    figures: { partner: Figures; branch: Figures; margin: number };
}) {
    return (
        <>
            <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                <FlowTiles figures={figures.branch} />
                <Panel className="flex flex-col justify-center gap-1 p-4">
                    <div className="text-[13px] text-tx2">
                        PayGate margin in the period
                    </div>
                    <div className="text-[28px] font-bold">
                        {formatPaise(figures.margin)}
                    </div>
                    <div className="text-xs text-tx3">
                        Partner fees {formatPaise(figures.partner.commission)} −
                        branch commission{' '}
                        {formatPaise(figures.branch.commission)}
                    </div>
                </Panel>
            </div>
            <div className="grid gap-3 lg:grid-cols-2">
                <Summary
                    title="Partners"
                    figures={figures.partner}
                    commissionLabel="Partner fees"
                    party="partner"
                />
                <Summary
                    title="Branches"
                    figures={figures.branch}
                    commissionLabel="Branch commission"
                    party="branch"
                />
            </div>
        </>
    );
}

function FlowTiles({ figures }: { figures: Figures }) {
    const rate = (flow: Flow) =>
        flow.rate === null ? '—' : `${flow.rate.toFixed(2)}%`;

    return (
        <div className="grid grid-cols-2 gap-3">
            <Tile
                label={`Deposit (${figures.deposit.count})`}
                value={formatPaise(figures.deposit.amount)}
                tone="ok"
            />
            <Tile
                label={`Deposit (${rate(figures.deposit)})`}
                value={formatPaise(figures.deposit.commission)}
                tone="ok"
            />
            <Tile
                label={`Withdraw (${figures.withdraw.count})`}
                value={formatPaise(figures.withdraw.amount)}
                tone="er"
            />
            <Tile
                label={`Withdraw (${rate(figures.withdraw)})`}
                value={formatPaise(figures.withdraw.commission)}
                tone="er"
            />
        </div>
    );
}

function Tile({
    label,
    value,
    tone,
}: {
    label: string;
    value: string;
    tone: 'ok' | 'er';
}) {
    return (
        <Panel
            className={cn(
                'flex flex-col items-center justify-center gap-2 border-t-4 px-3 py-5 text-center',
                tone === 'ok' ? 'border-t-ok' : 'border-t-er',
            )}
        >
            <div className="text-[13px] font-medium text-tx2">{label}</div>
            <div className="text-[22px] font-bold">{value}</div>
        </Panel>
    );
}

function Summary({
    title,
    figures,
    commissionLabel,
    party,
}: {
    title?: string;
    figures: Figures;
    commissionLabel: string;
    party: 'you' | 'partner' | 'branch';
}) {
    const rows: [string, ReactNode][] = [
        ['Deposit', formatPaise(figures.deposit.amount)],
        ['Withdraw', formatPaise(figures.withdraw.amount)],
        [commissionLabel, formatPaise(figures.commission)],
        ['Settlement', <Signed key="s" value={figures.settlement} />],
        [
            `Chargeback (${figures.chargeback.count})`,
            <Signed key="c" value={figures.chargeback.amount} />,
        ],
        ...(figures.adjustments !== 0
            ? ([
                  [
                      'Adjustments & refunds',
                      <Signed key="a" value={figures.adjustments} />,
                  ],
              ] as [string, ReactNode][])
            : []),
    ];

    return (
        <Panel className="border-t-4 border-t-ok p-4">
            {title && <div className="mb-2 text-sm font-semibold">{title}</div>}
            <dl className="flex flex-col gap-1.5 text-[13.5px]">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex justify-between gap-3">
                        <dt className="text-tx2">{label}</dt>
                        <dd className="font-medium">{value}</dd>
                    </div>
                ))}
            </dl>
            <dl className="mt-3 flex flex-col gap-1.5 border-t border-ln2 pt-3">
                <Balance
                    label="Current Balance"
                    hint="the period before settlement payments"
                    value={figures.current}
                    party={party}
                />
                <Balance
                    label="Net Balance"
                    hint="after settlement payments in the period"
                    value={figures.net}
                    party={party}
                    strong
                />
            </dl>
            <div className="mt-3 border-t border-ln2 pt-2 text-xs text-tx3">
                Position at the start <Signed value={figures.opening} /> · at
                the end <Signed value={figures.closing} /> (includes anything
                unpaid from before). {sideNote(party)}
            </div>
        </Panel>
    );
}

function Balance({
    label,
    hint,
    value,
    party,
    strong = false,
}: {
    label: string;
    hint: string;
    value: number;
    party: 'you' | 'partner' | 'branch';
    strong?: boolean;
}) {
    return (
        <div className="flex items-start justify-between gap-3">
            <dt>
                <div
                    className={cn(
                        strong ? 'text-[16px] font-bold' : 'font-semibold',
                    )}
                >
                    {label}
                </div>
                <div className="text-[11.5px] text-tx3">{hint}</div>
            </dt>
            <dd className="text-right">
                <div
                    className={cn(
                        strong ? 'text-[18px] font-bold' : 'font-semibold',
                    )}
                >
                    {formatPaise(Math.abs(value))}
                </div>
                <div className="text-[11.5px] text-tx3">
                    {owes(value, party)}
                </div>
            </dd>
        </div>
    );
}

function owes(value: number, party: 'you' | 'partner' | 'branch'): string {
    if (value === 0) return 'nothing owed';
    const who =
        party === 'you' ? 'you' : party === 'partner' ? 'partners' : 'branches';

    return value > 0
        ? `PayGate owes ${who}`
        : `${party === 'you' ? 'You owe' : who === 'partners' ? 'Partners owe' : 'Branches owe'} PayGate`;
}

function sideNote(party: 'you' | 'partner' | 'branch'): string {
    return party === 'you'
        ? '+ means PayGate owes you; − means you owe PayGate.'
        : `+ means PayGate owes the ${party}; − means the ${party} owes PayGate.`;
}

function Signed({ value }: { value: number }) {
    return (
        <span
            className={cn(
                'whitespace-nowrap',
                value > 0 && 'text-ok',
                value < 0 && 'text-er',
            )}
        >
            {value > 0 ? '+' : value < 0 ? '−' : ''}
            {formatPaise(Math.abs(value))}
        </span>
    );
}

function Pairs({
    pairs,
    side,
    portal,
}: {
    pairs: Pair[];
    side: 'branch' | 'partner';
    portal: UserType;
}) {
    // The dashboard's own side first; a branch sees its partners only.
    const columns: ('partner' | 'branch')[] =
        portal !== 'admin'
            ? ['partner']
            : side === 'branch'
              ? ['branch', 'partner']
              : ['partner', 'branch'];

    return (
        <Panel className="overflow-x-auto">
            <table className="w-full text-[13px]">
                <thead>
                    <tr className="border-b border-ln2 text-left text-xs text-tx3">
                        {columns.map((column) => (
                            <th key={column} className="px-3 py-2">
                                {column === 'branch' ? 'Branch' : 'Partner'}
                            </th>
                        ))}
                        <th className="px-3 py-2 text-right">Deposit</th>
                        <th className="px-3 py-2 text-right">Withdraw</th>
                        <th className="px-3 py-2 text-right">Commission</th>
                        <th className="px-3 py-2 text-right">Chargeback</th>
                        <th className="px-3 py-2 text-right">Settlement</th>
                        <th className="px-3 py-2 text-right">Net Balance</th>
                        <th className="px-3 py-2 text-right">
                            Position at end
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {pairs.map((pair) => (
                        <tr
                            key={`${pair.partner.code}|${pair.branch.code}`}
                            className="border-b border-ln2 last:border-0"
                        >
                            {columns.map((column) => (
                                <td key={column} className="px-3 py-2">
                                    {pair[column].name}
                                </td>
                            ))}
                            <td className="px-3 py-2 text-right">
                                {formatPaise(pair.deposit.amount)}
                                <span className="text-xs text-tx3">
                                    {' '}
                                    ({pair.deposit.count})
                                </span>
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatPaise(pair.withdraw.amount)}
                                <span className="text-xs text-tx3">
                                    {' '}
                                    ({pair.withdraw.count})
                                </span>
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatPaise(pair.commission)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                <Signed value={pair.chargeback.amount} />
                            </td>
                            <td className="px-3 py-2 text-right">
                                <Signed value={pair.settlement} />
                            </td>
                            <td className="px-3 py-2 text-right font-medium">
                                <Signed value={pair.net} />
                            </td>
                            <td className="px-3 py-2 text-right">
                                <Signed value={pair.closing} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </Panel>
    );
}
