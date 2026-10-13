import { Head } from '@inertiajs/react';
import { Panel } from '@/components/pg/data-table';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { formatLimit, formatPaise } from '@/lib/money';

type Props = {
    balance: {
        balance: number;
        reserved: number;
        available: number;
        max_payout: number;
    };
    limits: { min: number | null; max: number | null; daily: number | null };
    payouts_enabled: boolean;
};

/**
 * Partner portal: what can be withdrawn (the same figures as GET /v1/balance).
 */
export default function Balance({ balance, limits, payouts_enabled }: Props) {
    return (
        <>
            <Head title="Balance" />
            <PageHeader
                title="Balance"
                description="Money PayGate holds for you from your customers’ deposits (after fees), less payouts. Settled amounts are paid to you outside PayGate."
            />

            <KpiGrid>
                <StatTile
                    label="Balance"
                    value={formatPaise(balance.balance)}
                    icon="₹"
                    tone="in"
                />
                <StatTile
                    label="Held for payouts in progress"
                    value={formatPaise(balance.reserved)}
                    icon="◷"
                    tone="wn"
                />
                <StatTile
                    label="Available"
                    value={formatPaise(balance.available)}
                    icon="✓"
                    tone="ok"
                />
                <StatTile
                    label="Largest single payout now"
                    value={formatPaise(balance.max_payout)}
                    icon="↗"
                    tone="nt"
                />
            </KpiGrid>

            <Panel className="flex flex-col gap-2 p-5 text-[13px] leading-relaxed text-tx2">
                <div className="text-sm font-semibold text-tx">
                    How payouts use your balance
                </div>
                {!payouts_enabled && (
                    <p className="text-er">
                        Payouts are not enabled for your account. Contact
                        PayGate to enable them.
                    </p>
                )}
                <p>
                    Each payout goes to the next of your branches in turn. It
                    must fit your total available balance, including the
                    payout fee. When your balance is not enough, the API
                    answers{' '}
                    <code className="rounded bg-sf2 px-1 font-mono text-[12px]">
                        insufficient_balance
                    </code>{' '}
                    (“balance is low”).
                </p>
                <p>
                    Your payout limits: {formatLimit(limits.min)} to{' '}
                    {formatLimit(limits.max)} per payout
                    {limits.daily !== null
                        ? `, ${formatLimit(limits.daily)} per day`
                        : ', no daily limit'}
                    .
                </p>
            </Panel>
        </>
    );
}
