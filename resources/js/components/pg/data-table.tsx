import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { EmptyState } from './empty-state';

export type Column<T> = {
    key: string;
    header: ReactNode;
    cell: (row: T) => ReactNode;
    align?: 'left' | 'right';
    className?: string;
};

/**
 * Dense data table following the design (row padding follows the
 * compact/comfortable density via --pg-rp). Rows are clickable when
 * `onRowClick` is given (typically to open a drawer).
 */
export function DataTable<T>({
    columns,
    rows,
    rowKey,
    onRowClick,
    empty,
}: {
    columns: Column<T>[];
    rows: T[];
    rowKey: (row: T) => string;
    onRowClick?: (row: T) => void;
    empty?: ReactNode;
}) {
    if (rows.length === 0) {
        return <>{empty ?? <EmptyState title="Nothing here yet" />}</>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        {columns.map((column) => (
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
                        <tr
                            key={rowKey(row)}
                            onClick={
                                onRowClick ? () => onRowClick(row) : undefined
                            }
                            className={cn(
                                onRowClick && 'cursor-pointer hover:bg-sf2',
                            )}
                        >
                            {columns.map((column) => (
                                <td
                                    key={column.key}
                                    className={cn(
                                        'border-b border-ln2 px-2.5 py-rp align-middle',
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
