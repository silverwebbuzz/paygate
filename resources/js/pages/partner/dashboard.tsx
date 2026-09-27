import { PortalDashboard } from '@/components/pg/portal-dashboard';

export default function PartnerDashboard() {
    return (
        <PortalDashboard
            title="Partner dashboard"
            description="Your customers' pay-ins and pay-outs, balance and settlements."
            upcoming={[
                { phase: 10, label: 'Settlements' },
                { phase: 11, label: 'Reports' },
            ]}
        />
    );
}
