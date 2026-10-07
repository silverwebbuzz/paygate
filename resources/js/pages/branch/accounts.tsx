import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { AccountActions } from '@/components/pg/account-actions';
import { AccountFormDialog } from '@/components/pg/account-form-dialog';
import type { AccountRow } from '@/components/pg/account-form-dialog';
import { AccountTable } from '@/components/pg/account-table';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { formatPaise } from '@/lib/money';
import accountsRoutes from '@/routes/branch/accounts';

type Props = {
    branch: {
        code: string;
        name: string;
        deposit_limit_type: string;
        deposit_topup_balance: number;
        deposit_min_amount: string | null;
        deposit_max_amount: string | null;
    };
    accounts: AccountRow[];
    can: { create: boolean };
};

export default function BranchAccounts({ branch, accounts, can }: Props) {
    const [editing, setEditing] = useState<AccountRow | 'new' | null>(null);
    const count = (status: string) =>
        accounts.filter((account) => account.status === status).length;
    const usedToday = accounts.reduce(
        (sum, account) => sum + account.used_today.amount,
        0,
    );

    return (
        <>
            <Head title="Bank & UPI Accounts" />

            <PageHeader
                title="Bank & UPI Accounts"
                description={`Accounts of ${branch.name} that customers pay into. Only verified, active accounts receive customers. Limits reset daily at 00:00 IST.`}
                actions={
                    can.create && (
                        <PgButton
                            variant="primary"
                            onClick={() => setEditing('new')}
                        >
                            <Plus className="size-4" /> Add account
                        </PgButton>
                    )
                }
            />

            <KpiGrid>
                <StatTile
                    label="Active"
                    value={String(count('active'))}
                    icon="●"
                    tone="ok"
                />
                <StatTile
                    label="Waiting for PayGate"
                    value={String(count('verification_pending'))}
                    icon="◷"
                    tone="wn"
                />
                <StatTile
                    label="Rejected"
                    value={String(count('rejected'))}
                    icon="✕"
                    tone="er"
                />
                <StatTile
                    label={
                        branch.deposit_limit_type === 'topup'
                            ? 'Deposit allowance left'
                            : 'Received today'
                    }
                    value={formatPaise(
                        branch.deposit_limit_type === 'topup'
                            ? branch.deposit_topup_balance
                            : usedToday,
                        0,
                    )}
                    icon="₹"
                    tone="in"
                />
            </KpiGrid>

            <Panel className="overflow-hidden">
                <AccountTable
                    accounts={accounts}
                    showBranch={false}
                    detailed
                    empty="Add the bank accounts and UPI IDs your customers should pay into. PayGate verifies each one first."
                    actions={(account) => (
                        <AccountActions
                            account={account}
                            statusUrl={accountsRoutes.status(account.id).url}
                            onEdit={() => setEditing(account)}
                        />
                    )}
                />
            </Panel>

            {editing !== null && (
                <AccountFormDialog
                    account={editing === 'new' ? undefined : editing}
                    url={
                        editing === 'new'
                            ? accountsRoutes.store().url
                            : accountsRoutes.update(editing.id).url
                    }
                    method={editing === 'new' ? 'post' : 'put'}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
