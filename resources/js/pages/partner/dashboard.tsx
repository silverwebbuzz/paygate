import {
    LiveDashboard,
    NameCell,
    StatusCell,
} from '@/components/pg/live-dashboard';
import type { DashboardProps } from '@/components/pg/live-dashboard';
import { METHOD_LABELS } from '@/components/pg/transaction-drawer';
import { formatPaise } from '@/lib/money';

export default function PartnerDashboard(props: DashboardProps) {
    return (
        <LiveDashboard
            {...props}
            title={`${props.organisation ?? 'Your business'} · Merchant overview`}
            description="Your collections, payouts and balance. Updated in real time."
            tableCell={(row, column) =>
                [
                    <NameCell key="n" row={row} />,
                    formatPaise(Number(row.a)),
                    row.method ? METHOD_LABELS[String(row.method)] : '—',
                    <StatusCell key="s" status={String(row.status)} />,
                ][column]
            }
        />
    );
}
