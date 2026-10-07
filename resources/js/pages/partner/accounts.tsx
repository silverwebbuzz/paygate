import { Head } from '@inertiajs/react';
import { AccountTable } from '@/components/pg/account-table';
import type { AccountRow } from '@/components/pg/account-form-dialog';
import { Panel } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';

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
                    detailed
                    empty={
                        <EmptyState
                            title="No active accounts"
                            description="Active bank accounts and UPI IDs appear here once PayGate assigns them to you."
                        />
                    }
                />
            </Panel>
        </>
    );
}
