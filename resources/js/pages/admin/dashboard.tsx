import { PortalDashboard } from '@/components/pg/portal-dashboard';

export default function AdminDashboard() {
    return (
        <PortalDashboard
            title="Admin dashboard"
            description="Collections, payouts and reconciliation across every partner and branch."
            upcoming={[
                { phase: 3, label: 'Users, roles & permissions' },
                { phase: 4, label: 'Partners & API credentials' },
                { phase: 5, label: 'Branches, mapping & bank accounts' },
                { phase: 7, label: 'Transactions & manual deposits' },
                { phase: 9, label: 'Statements & reconciliation' },
                { phase: 10, label: 'Settlement & commissions' },
            ]}
        />
    );
}
