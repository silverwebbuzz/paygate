import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';

/** App\Domain\Reporting\Period::toArray(). */
export type PeriodValue = {
    range: string;
    from: string;
    to: string;
    label: string;
};

/** What a list sends back to the server: ?range= (and ?from=&to= for custom). */
export type PeriodQuery = { range: string; from?: string; to?: string };

export const PERIOD_RANGES: [string, string][] = [
    ['1h', '1h'],
    ['24h', '24h'],
    ['7d', '7 Days'],
    ['30d', '30 Days'],
    ['today', 'Today'],
    ['yesterday', 'Yesterday'],
    ['this_month', 'This Month'],
    ['prev_month', 'Previous Month'],
];

/** "2026-09-25T14:30" in business time, for the date-time pickers. */
const local = (iso: string) => iso.slice(0, 16);

/** The query that keeps the current period when other filters change. */
export function periodQuery(period: PeriodValue): PeriodQuery {
    return period.range === 'custom'
        ? { range: 'custom', from: local(period.from), to: local(period.to) }
        : { range: period.range };
}

/**
 * Period filter of the list pages, as on the Settlement Dashboard: range
 * buttons (Today by default), then a custom start / end and the period
 * shown in words.
 */
export function PeriodFilter({
    period,
    onChange,
    className,
}: {
    period: PeriodValue;
    onChange: (query: PeriodQuery) => void;
    className?: string;
}) {
    const [draft, setDraft] = useState({
        from: local(period.from),
        to: local(period.to),
    });

    useEffect(() => {
        setDraft({ from: local(period.from), to: local(period.to) });
    }, [period.from, period.to]);

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <div className="flex flex-wrap items-center gap-1.5">
                {PERIOD_RANGES.map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => onChange({ range: key })}
                        className={cn(
                            'h-[30px] rounded-[7px] border px-3 text-[12.5px] font-medium transition-colors',
                            period.range === key
                                ? 'border-transparent bg-brand text-white'
                                : 'border-ln bg-sf text-tx2 hover:border-ac/40 hover:text-tx',
                        )}
                    >
                        {label}
                    </button>
                ))}
            </div>
            <form
                className="flex flex-wrap items-center gap-2 text-xs text-tx3"
                onSubmit={(event) => {
                    event.preventDefault();
                    onChange({ range: 'custom', ...draft });
                }}
            >
                <input
                    type="datetime-local"
                    aria-label="Start date"
                    value={draft.from}
                    max={draft.to}
                    onChange={(event) =>
                        setDraft({ ...draft, from: event.target.value })
                    }
                    className="h-[30px] rounded-[7px] border border-ln bg-sf px-2 text-[12.5px] text-tx"
                />
                –
                <input
                    type="datetime-local"
                    aria-label="End date"
                    value={draft.to}
                    min={draft.from}
                    onChange={(event) =>
                        setDraft({ ...draft, to: event.target.value })
                    }
                    className="h-[30px] rounded-[7px] border border-ln bg-sf px-2 text-[12.5px] text-tx"
                />
                <button
                    type="submit"
                    disabled={!draft.from || !draft.to}
                    className={cn(
                        'h-[30px] rounded-[7px] border px-3 text-[12.5px] font-medium disabled:opacity-50',
                        period.range === 'custom'
                            ? 'border-transparent bg-brand text-white'
                            : 'border-ln bg-sf text-tx2 hover:text-tx',
                    )}
                >
                    Custom
                </button>
                <span>{period.label}</span>
            </form>
        </div>
    );
}

/** Open work from before the period (Period::olderOpen). */
export type OlderOpen = { count: number; from: string; to: string } | null;

/**
 * "Today" lists only today (client request 2026-11-18); open items from
 * before the period are counted here, with a button that widens the period
 * to show them, so nothing waiting is missed.
 */
export function OlderOpenBanner({
    older,
    noun,
    onShow,
}: {
    older: OlderOpen | undefined;
    /** e.g. ["deposit", "deposits"] */
    noun: [string, string];
    onShow: (query: PeriodQuery) => void;
}) {
    if (!older) return null;

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-lg bg-wnb px-3 py-2 text-[12.5px] text-wn">
            <span>
                <b>{older.count}</b>{' '}
                {older.count === 1
                    ? `older ${noun[0]} is`
                    : `older ${noun[1]} are`}{' '}
                still waiting (from before this period).
            </span>
            <button
                type="button"
                onClick={() =>
                    onShow({ range: 'custom', from: older.from, to: older.to })
                }
                className="font-medium underline underline-offset-2"
            >
                Show {older.count === 1 ? 'it' : 'them'}
            </button>
        </div>
    );
}
