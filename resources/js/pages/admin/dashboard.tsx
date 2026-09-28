import {
    LiveDashboard,
    NameCell,
    PercentCell,
} from '@/components/pg/live-dashboard';
import type { DashboardProps } from '@/components/pg/live-dashboard';
import { formatPaise } from '@/lib/money';

export default function AdminDashboard(props: DashboardProps) {
    return (
        <LiveDashboard
            {...props}
            title="Platform overview"
            description="All partners, branches and payment accounts in real time."
            tableCell={(row, column) =>
                [
                    <NameCell key="n" row={row} />,
                    formatPaise(Number(row.a), 0),
                    formatPaise(Number(row.b), 0),
                    <PercentCell
                        key="p"
                        value={row.rate === null ? null : Number(row.rate)}
                    />,
                    <span
                        key="v"
                        className={Number(row.value) < 0 ? 'text-er' : ''}
                    >
                        {Number(row.value) < 0 ? '−' : ''}
                        {formatPaise(Math.abs(Number(row.value)))}
                    </span>,
                ][column]
            }
        />
    );
}
