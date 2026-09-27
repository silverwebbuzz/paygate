import { PortalDashboard } from '@/components/pg/portal-dashboard';

export default function PartnerDashboard() {
    return (
        <PortalDashboard
            title="Partner dashboard"
            description="Your customers' pay-ins and pay-outs, balance and settlements."
            upcoming={[
                { phase: 6, label: 'Create payment & API logs' },
                { phase: 7, label: 'Pay-in history' },
                { phase: 8, label: 'Pay-out & balance' },
                { phase: 10, label: 'Settlements' },
                { phase: 11, label: 'Reports' },
            ]}
        />
    );
}
