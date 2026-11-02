import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { EmptyState } from './empty-state';

export type Column<T> = {
    key: string;
    header: ReactNode;
    cell: (row: T) => ReactNode;
    align?: 'left' | 'right';
    valign?: 'top' | 'middle';
    className?: string;
};

/**
 * Small "View details" link that opens a row's drawer. Rows themselves are
 * not clickable, so their text can be selected and copied.
 */
export function ViewDetails({ onClick }: { onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={(event) => {
                event.stopPropagation();
                onClick();
            }}
            className="text-xs font-medium whitespace-nowrap text-ac hover:underline"
        >
            View details
        </button>
    );
}

/**
 * Dense data table following the design (row padding follows the
 * compact/comfortable density via --pg-rp). When `onView` is given
 * (typically to open a drawer), a last column holds a "View details" link.
 */
export function DataTable<T>({
    columns,
    rows,
    rowKey,
    onView,
    empty,
}: {
    columns: Column<T>[];
    rows: T[];
    rowKey: (row: T) => string;
    onView?: (row: T) => void;
    empty?: ReactNode;
}) {
    if (rows.length === 0) {
        return <>{empty ?? <EmptyState title="Nothing here yet" />}</>;
    }

    const shown: Column<T>[] = onView
        ? [
              ...columns,
              {
                  key: '__view',
                  header: '',
                  align: 'right',
                  cell: (row) => <ViewDetails onClick={() => onView(row)} />,
              },
          ]
        : columns;

    return (
        <div className="overflow-x-auto">
            <table className="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        {shown.map((column) => (
                            <th
                                key={column.key}
                                className={cn(
                                    'border-b border-ln bg-sf2 px-2.5 py-2 text-left text-[11.5px] font-semibold tracking-[.03em] whitespace-nowrap text-tx3 uppercase',
                                    column.align === 'right' && 'text-right',
                                )}
                            >
                                {column.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={rowKey(row)} className="hover:bg-sf2">
                            {shown.map((column) => (
                                <td
                                    key={column.key}
                                    className={cn(
                                        'border-b border-ln2 px-2.5 py-rp',
                                        column.valign === 'top'
                                            ? 'align-top'
                                            : 'align-middle',
                                        column.align === 'right' &&
                                            'text-right tabular-nums',
                                        column.className,
                                    )}
                                >
                                    {column.cell(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** White card that wraps tables, tabs and filter bars. */
export function Panel({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'relative rounded-[10px] border border-ln bg-sf',
                className,
            )}
        >
            {children}
        </div>
    );
}
