import { KeyRound, ReceiptText, Webhook } from 'lucide-react';
import { PortalOverview } from '@/components/portal-overview';
import { dashboard } from '@/routes/partner';

export default function PartnerDashboard() {
    return (
        <PortalOverview
            title="Partner dashboard"
            cards={[
                {
                    title: 'Transactions',
                    description: 'Payments made by your users.',
                    icon: ReceiptText,
                },
                {
                    title: 'API keys',
                    description: 'Credentials for creating payment sessions.',
                    icon: KeyRound,
                },
                {
                    title: 'Webhooks',
                    description: 'Where we notify you about payment results.',
                    icon: Webhook,
                },
            ]}
        />
    );
}

PartnerDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
