import { CircleCheck, Landmark, QrCode } from 'lucide-react';
import { PortalOverview } from '@/components/portal-overview';
import { dashboard } from '@/routes/branch';

export default function BranchDashboard() {
    return (
        <PortalOverview
            title="Branch dashboard"
            cards={[
                {
                    title: 'Bank accounts',
                    description: 'Add bank accounts for admin verification.',
                    icon: Landmark,
                },
                {
                    title: 'UPI IDs',
                    description: 'Add UPI IDs shown to paying users.',
                    icon: QrCode,
                },
                {
                    title: 'Deposits',
                    description: 'Confirm or reject payments made to you.',
                    icon: CircleCheck,
                },
            ]}
        />
    );
}

BranchDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
