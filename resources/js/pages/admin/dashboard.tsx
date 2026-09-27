import { Building2, Landmark, ReceiptText } from 'lucide-react';
import { PortalOverview } from '@/components/portal-overview';
import { dashboard } from '@/routes/admin';

export default function AdminDashboard() {
    return (
        <PortalOverview
            title="Admin dashboard"
            cards={[
                {
                    title: 'Partners',
                    description: 'Create partners and manage their settings.',
                    icon: Building2,
                },
                {
                    title: 'Branches & accounts',
                    description:
                        'Verify bank/UPI accounts and assign them to partners.',
                    icon: Landmark,
                },
                {
                    title: 'Transactions',
                    description: 'Every payment across all partners.',
                    icon: ReceiptText,
                },
            ]}
        />
    );
}

AdminDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
