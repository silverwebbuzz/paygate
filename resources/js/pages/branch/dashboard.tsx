import { PortalDashboard } from '@/components/pg/portal-dashboard';

export default function BranchDashboard() {
    return (
        <PortalDashboard
            title="Branch dashboard"
            description="Deposits to approve, payouts to process and your bank accounts at a glance."
            upcoming={[
                { phase: 9, label: 'A/C statements & unsettled UTR' },
                { phase: 10, label: 'Branch balance & settlement' },
                { phase: 11, label: 'Reports' },
            ]}
        />
    );
}
