import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatLimit } from '@/lib/money';
import { cn } from '@/lib/utils';

type Account = {
    id: string;
    holder: string;
    is_bank_enabled: boolean;
    bank_name: string | null;
    ifsc: string | null;
    account_number: string | null;
    is_upi_enabled: boolean;
    upi_id: string | null;
    upi_display_name: string | null;
    is_qr_enabled: boolean;
    min_amount: number | null;
    max_amount: number | null;
    daily_amount_limit: number | null;
    daily_count_limit: number | null;
    status: string;
};

type Props = {
    accounts: Account[];
};

export default function PartnerAccounts({ accounts }: Props) {
    const active = accounts.filter((a) => a.status === 'active').length;
    const banks = accounts.filter((a) => a.is_bank_enabled).length;
    const upis = accounts.filter((a) => a.is_upi_enabled).length;

    const columns: Column<Account>[] = [
        {
            key: 'holder',
            header: 'Account holder',
            className: 'min-w-[160px]',
            cell: (a) => <span className="font-medium">{a.holder}</span>,
        },
        {
            key: 'bank',
            header: 'Bank · Number · IFSC',
            className: 'whitespace-nowrap',
            cell: (a) =>
                a.is_bank_enabled ? (
                    <div>
                        <div>{a.bank_name}</div>
                        <div className="font-mono text-xs text-tx3">
                            {a.account_number} · {a.ifsc}
                        </div>
                    </div>
                ) : (
                    <span className="text-tx3">—</span>
                ),
        },
        {
            key: 'upi',
            header: 'UPI',
            cell: (a) =>
                a.is_upi_enabled ? (
                    <div>
                        <div className="font-mono text-xs">{a.upi_id}</div>
                        {a.upi_display_name && (
                            <div className="text-xs text-tx3">
                                {a.upi_display_name}
                            </div>
                        )}
                    </div>
                ) : (
                    <span className="text-tx3">—</span>
                ),
        },
        {
            key: 'per_payment',
            header: 'Per payment',
            cell: (a) => (
                <span className="text-xs whitespace-nowrap">
                    {a.min_amount === null && a.max_amount === null
                        ? 'Any amount'
                        : `${formatLimit(a.min_amount)} – ${formatLimit(a.max_amount)}`}
                </span>
            ),
        },
        {
            key: 'daily',
            header: 'Daily limit',
            cell: (a) => (
                <div className="text-xs whitespace-nowrap">
                    <div>{formatLimit(a.daily_amount_limit)}</div>
                    <div className="text-tx3">
                        {a.daily_count_limit === null
                            ? 'Unlimited payments'
                            : `${a.daily_count_limit} payments`}
                    </div>
                </div>
            ),
        },
        {
            key: 'methods',
            header: 'Methods',
            cell: (a) => (
                <div className="flex flex-wrap gap-1">
                    <Method on={a.is_bank_enabled}>Bank</Method>
                    <Method on={a.is_upi_enabled}>UPI</Method>
                    <Method on={a.is_qr_enabled}>QR</Method>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (a) => <StatusBadge status={a.status} />,
        },
    ];

    return (
        <>
            <Head title="Bank & UPI Accounts" />

            <PageHeader
                title="Bank & UPI Accounts"
                description="Bank accounts and UPI IDs your customers can be sent to pay into. Only active accounts receive payments. Limits reset daily at 00:00 IST."
            />

            <KpiGrid>
                <StatTile
                    label="Accounts"
                    value={String(accounts.length)}
                    icon="#"
                    tone="in"
                />
                <StatTile
                    label="Active"
                    value={String(active)}
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
                <DataTable
                    columns={columns}
                    rows={accounts}
                    rowKey={(a) => a.id}
                    empty={
                        <EmptyState
                            title="No accounts yet"
                            description="Bank accounts and UPI IDs appear here once PayGate assigns them to you."
                        />
                    }
                />
            </Panel>
        </>
    );
}

function Method({ on, children }: { on: boolean; children: ReactNode }) {
    return (
        <span
            className={cn(
                'rounded-[5px] border border-ln px-1.5 py-px text-[11px]',
                on ? 'text-tx2' : 'text-tx3 line-through opacity-60',
            )}
        >
            {children}
        </span>
    );
}
