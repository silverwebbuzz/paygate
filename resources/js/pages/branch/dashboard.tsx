import {
    LiveDashboard,
    NameCell,
    PercentCell,
} from '@/components/pg/live-dashboard';
import type { DashboardProps } from '@/components/pg/live-dashboard';
import { formatLimit, formatPaise } from '@/lib/money';

export default function BranchDashboard(props: DashboardProps) {
    return (
        <LiveDashboard
            {...props}
            title={props.organisation ?? 'Branch overview'}
            description="Your branch operations. Data scoped to your branch only."
            tableCell={(row, column) =>
                [
                    <NameCell key="n" row={row} />,
                    formatPaise(Number(row.a), 0),
                    formatLimit(row.b === null ? null : Number(row.b)),
                    <PercentCell
                        key="p"
                        invert
                        value={row.rate === null ? null : Number(row.rate)}
                    />,
                    row.value === null
                        ? 'No limit'
                        : formatPaise(Number(row.value)),
                ][column]
            }
        />
    );
}
