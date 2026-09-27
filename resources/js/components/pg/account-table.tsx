import type { ReactNode } from 'react';
import { formatLimit, formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { AccountRow } from './account-form-dialog';
import { DataTable } from './data-table';
import type { Column } from './data-table';
import { EmptyState } from './empty-state';
import { StatusBadge } from './status-badge';

/**
 * Bank & UPI accounts table (design: account, bank, UPI, branch, per-txn
 * limit, used today against the daily limit, methods, status, actions).
 */
export function AccountTable({
    accounts,
    showBranch,
    actions,
    empty,
}: {
    accounts: AccountRow[];
    showBranch: boolean;
    actions: (account: AccountRow) => ReactNode;
    empty: ReactNode;
}) {
    const columns: Column<AccountRow>[] = [
        {
            key: 'account',
            header: 'Account',
            className: 'min-w-[180px]',
            cell: (a) => (
                <div>
                    <div className="font-medium">{a.holder}</div>
                    <div className="text-xs text-tx3">{a.label}</div>
                </div>
            ),
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
        ...(showBranch
            ? [
                  {
                      key: 'branch',
                      header: 'Branch',
                      cell: (a: AccountRow) => (
                          <span className="rounded-md bg-sf2 px-2 py-0.5 text-xs">
                              {a.branch.code}
                          </span>
                      ),
                  },
              ]
            : []),
        {
            key: 'per_txn',
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
            key: 'usage',
            header: 'Used today / daily limit',
            cell: (a) => <Usage account={a} />,
        },
        {
            key: 'methods',
            header: 'Methods',
            cell: (a) => (
                <div className="flex flex-wrap gap-1">
                    <Method on={a.is_bank_enabled}>Bank</Method>
                    <Method on={a.is_upi_enabled}>UPI</Method>
                    <Method on={a.is_qr_enabled}>QR</Method>
                    <Method on={a.is_intent_enabled}>Intent</Method>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (a) => (
                <div>
                    <StatusBadge
                        status={a.status}
                        label={a.status === 'rejected' ? 'Rejected' : undefined}
                    />
                    {a.status === 'rejected' && a.rejected_reason && (
                        <div className="mt-1 max-w-[200px] text-[11px] text-er">
                            {a.rejected_reason}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            cell: (a) => (
                <div className="flex justify-end gap-1.5">{actions(a)}</div>
            ),
        },
    ];

    return (
        <DataTable
            columns={columns}
            rows={accounts}
            rowKey={(a) => a.id}
            empty={<EmptyState title="No accounts yet" description={empty} />}
        />
    );
}

function Usage({ account }: { account: AccountRow }) {
    const limit = account.daily_amount_limit;
    const used = account.used_today.amount;

    if (limit === null) {
        return (
            <div className="text-xs">
                <b className="font-semibold">{formatPaise(used, 0)}</b>
                <span className="text-tx3"> · no daily limit</span>
            </div>
        );
    }

    const percent = Math.min(100, Math.round((used / limit) * 100));

    return (
        <div className="w-[150px] text-xs">
            <div className="flex justify-between">
                <b className="font-semibold">{formatPaise(used, 0)}</b>
                <span className="text-tx3">{formatPaise(limit, 0)}</span>
            </div>
            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-sf2">
                <div
                    className={cn(
                        'h-full rounded-full',
                        percent >= 100
                            ? 'bg-er'
                            : percent > 75
                              ? 'bg-wn'
                              : 'bg-ok',
                    )}
                    style={{ width: `${percent}%` }}
                />
            </div>
            <div className="mt-0.5 text-[11px] text-tx3">
                {percent >= 100
                    ? 'Limit reached · resets 00:00 IST'
                    : `${formatPaise(limit - used, 0)} remaining`}
            </div>
        </div>
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
