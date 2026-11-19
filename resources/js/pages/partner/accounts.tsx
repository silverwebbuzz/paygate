import { Head, Link } from '@inertiajs/react';
import { History } from 'lucide-react';
import { AccountTable } from '@/components/pg/account-table';
import type { AccountRow } from '@/components/pg/account-form-dialog';
import { Panel } from '@/components/pg/data-table';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import accountsRoutes from '@/routes/partner/accounts';

type Props = {
    accounts: AccountRow[];
};

export default function PartnerAccounts({ accounts }: Props) {
    const banks = accounts.filter((a) => a.is_bank_enabled).length;
    const upis = accounts.filter((a) => a.is_upi_enabled).length;

    return (
        <>
            <Head title="Bank & UPI Accounts" />

            <PageHeader
                title="Bank & UPI Accounts"
                description="Active bank accounts and UPI IDs assigned to you."
                actions={
                    <Link
                        href={accountsRoutes.logs.index().url}
                        className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                    >
                        <History className="size-3.5" /> Account log
                    </Link>
                }
            />

            <KpiGrid>
                <StatTile
                    label="Active"
                    value={String(accounts.length)}
                    icon="●"
                    tone="ok"
                />
                <StatTile
                    label="Bank accounts"
                    value={String(banks)}
                    icon="₹"
                />
                <StatTile label="UPI IDs" value={String(upis)} icon="@" />
            </KpiGrid>

            <Panel className="overflow-hidden">
                <AccountTable
                    accounts={accounts}
                    showBranch={false}
                    showVerification={false}
                    showLimits={false}
                    detailed
                    actions={(account) => (
                        <Link
                            href={
                                accountsRoutes.logs.index({
                                    query: { account: account.id },
                                }).url
                            }
                            className="inline-flex h-7 items-center rounded-[7px] border border-ln bg-sf px-3 text-xs font-medium"
                        >
                            Log
                        </Link>
                    )}
                    empty="Active bank accounts and UPI IDs appear here once PayGate assigns them to you."
                />
            </Panel>
        </>
    );
}
