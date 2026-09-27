import { Head } from '@inertiajs/react';
import { Panel } from '@/components/pg/data-table';
import { KeyValues } from '@/components/pg/drawer';
import { PageHeader } from '@/components/pg/page-header';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDateTime } from '@/lib/dates';
import { formatLimit } from '@/lib/money';

type Props = {
    partner: {
        name: string;
        code: string;
        email: string;
        description: string | null;
        website_url: string;
        api_version: string;
        status: string;
        verified_at: string | null;
        is_payin_enabled: boolean;
        is_payout_enabled: boolean;
        allow_qr: boolean;
        allow_upi: boolean;
        allow_bank_transfer: boolean;
        is_h2h_enabled: boolean;
        manual_payment_type: string | null;
        session_ttl_minutes: number;
        is_auto_withdrawal: boolean;
        is_partial_withdrawal: boolean;
        deposit_min_amount: number | null;
        deposit_max_amount: number | null;
        deposit_daily_limit: number | null;
        withdrawal_min_amount: number | null;
        withdrawal_max_amount: number | null;
        withdrawal_daily_limit: number | null;
    };
    rates: { deposit: string | null; withdrawal: string | null } | null;
};

const percent = (rate: string | null) =>
    rate === null ? 'Not set' : `${Number(rate)}%`;

export default function BusinessProfile({ partner, rates }: Props) {
    const methods = [
        partner.allow_qr && 'QR',
        partner.allow_upi && 'UPI',
        partner.allow_bank_transfer && 'Bank transfer',
        partner.is_h2h_enabled && 'Host-to-host',
    ].filter(Boolean);

    return (
        <>
            <Head title="Business profile" />

            <PageHeader
                title="Business profile"
                description="How PayGate has set up your account. To change anything here, contact your PayGate account manager."
            />

            <Panel className="flex flex-col gap-5 p-5">
                <div className="flex items-center gap-2 text-base font-semibold">
                    {partner.name}{' '}
                    <span className="font-mono text-xs text-tx3">
                        {partner.code}
                    </span>
                    <StatusBadge status={partner.status} />
                </div>
                <KeyValues
                    items={[
                        { label: 'Email', value: partner.email },
                        { label: 'Website', value: partner.website_url },
                        { label: 'API version', value: partner.api_version },
                        {
                            label: 'Live since',
                            value: formatDateTime(partner.verified_at),
                        },
                        { label: 'Description', value: partner.description },
                    ]}
                />
            </Panel>

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel className="flex flex-col gap-4 p-5">
                    <div className="text-sm font-semibold">
                        Pay-in (deposits)
                    </div>
                    <KeyValues
                        items={[
                            {
                                label: 'Status',
                                value: partner.is_payin_enabled
                                    ? 'Enabled'
                                    : 'Disabled',
                            },
                            {
                                label: 'Methods',
                                value: methods.join(' · ') || null,
                            },
                            {
                                label: 'Payment page expiry',
                                value: `${partner.session_ttl_minutes} min`,
                            },
                            {
                                label: 'Minimum',
                                value: formatLimit(partner.deposit_min_amount),
                            },
                            {
                                label: 'Maximum',
                                value: formatLimit(partner.deposit_max_amount),
                            },
                            {
                                label: 'Daily limit',
                                value: formatLimit(partner.deposit_daily_limit),
                            },
                            ...(rates
                                ? [
                                      {
                                          label: 'Commission',
                                          value: percent(rates.deposit),
                                      },
                                  ]
                                : []),
                        ]}
                    />
                </Panel>
                <Panel className="flex flex-col gap-4 p-5">
                    <div className="text-sm font-semibold">
                        Payouts (withdrawals)
                    </div>
                    <KeyValues
                        items={[
                            {
                                label: 'Status',
                                value: partner.is_payout_enabled
                                    ? 'Enabled'
                                    : 'Disabled',
                            },
                            {
                                label: 'Auto withdrawal',
                                value: partner.is_auto_withdrawal
                                    ? 'Yes'
                                    : 'No',
                            },
                            {
                                label: 'Partial withdrawal',
                                value: partner.is_partial_withdrawal
                                    ? 'Allowed'
                                    : 'Not allowed',
                            },
                            {
                                label: 'Minimum',
                                value: formatLimit(
                                    partner.withdrawal_min_amount,
                                ),
                            },
                            {
                                label: 'Maximum',
                                value: formatLimit(
                                    partner.withdrawal_max_amount,
                                ),
                            },
                            {
                                label: 'Daily limit',
                                value: formatLimit(
                                    partner.withdrawal_daily_limit,
                                ),
                            },
                            ...(rates
                                ? [
                                      {
                                          label: 'Commission',
                                          value: percent(rates.withdrawal),
                                      },
                                  ]
                                : []),
                        ]}
                    />
                </Panel>
            </div>
        </>
    );
}
