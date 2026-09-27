import { PortalDashboard } from '@/components/pg/portal-dashboard';

export default function AdminDashboard() {
    return (
        <PortalDashboard
            title="Admin dashboard"
            description="Collections, payouts and reconciliation across every partner and branch."
            upcoming={[
                { phase: 7, label: 'Transactions & manual deposits' },
                { phase: 9, label: 'Statements & reconciliation' },
                { phase: 10, label: 'Settlement & commissions' },
            ]}
        />
    );
}
