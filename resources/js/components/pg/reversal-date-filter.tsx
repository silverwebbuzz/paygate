import { SelectInput } from '@/components/pg/field';
import { PeriodFilter, periodQuery } from '@/components/pg/period-filter';
import type { PeriodQuery, PeriodValue } from '@/components/pg/period-filter';

/** App\Http\Shared\Transactions\ReversalDates::toArray(). */
export type ReversalDatesValue = {
    by: DateBasis;
    period: PeriodValue | null;
};

export type DateBasis = 'transaction' | 'bank' | 'entered' | 'decided';

/** What the page sends back: ?date_by= and the period (none = All). */
export type ReversalDatesQuery = { date_by?: DateBasis } & Partial<PeriodQuery>;

const LABELS: Record<'chargeback' | 'return', Record<DateBasis, string>> = {
    chargeback: {
        entered: 'Chargeback entered in system',
        bank: 'Chargeback date in bank',
        transaction: 'Deposit (pay-in) date',
        decided: 'Approved date (booked)',
    },
    return: {
        entered: 'Return entered in system',
        bank: 'Return date in bank',
        transaction: 'Payout date',
        decided: 'Confirmed date (booked)',
    },
};

/** The query that keeps the current date filter when other filters change. */
export function reversalDatesQuery(
    dates: ReversalDatesValue,
): ReversalDatesQuery {
    return {
        date_by: dates.by,
        ...(dates.period ? periodQuery(dates.period) : {}),
    };
}

/**
 * Date filter of the Chargebacks and Payout returns pages (client request
 * 2026-11-24): which date, then the period (All by default; 1h … Previous
 * Month; custom start and end).
 */
export function ReversalDateFilter({
    kind,
    value,
    onChange,
}: {
    kind: 'chargeback' | 'return';
    value: ReversalDatesValue;
    onChange: (next: ReversalDatesQuery) => void;
}) {
    const kept = value.period ? periodQuery(value.period) : {};

    return (
        <div className="flex flex-col gap-2">
            <label className="flex items-center gap-2 text-xs text-tx3">
                Filter by
                <SelectInput
                    aria-label="Date to filter by"
                    className="h-[30px] w-[240px] text-[12.5px]"
                    value={value.by}
                    onChange={(event) =>
                        onChange({
                            ...kept,
                            date_by: event.target.value as DateBasis,
                        })
                    }
                >
                    {(
                        Object.entries(LABELS[kind]) as [DateBasis, string][]
                    ).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </SelectInput>
            </label>
            <PeriodFilter
                allowAll
                period={value.period}
                onChange={(period) =>
                    onChange({ date_by: value.by, ...period })
                }
            />
            {value.by === 'bank' && kind === 'chargeback' && value.period && (
                <p className="text-[11.5px] text-tx3">
                    Chargebacks raised before 24 Nov 2026 have no bank date and
                    are left out.
                </p>
            )}
        </div>
    );
}
