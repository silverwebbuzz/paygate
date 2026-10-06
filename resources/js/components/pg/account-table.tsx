import type { ReactNode } from 'react';
import { formatLimit, formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { AccountRow } from './account-form-dialog';
import { DataTable } from './data-table';
import type { Column } from './data-table';
import { EmptyState } from './empty-state';
import { StatusBadge } from './status-badge';

export function AccountTable({
    accounts,
    showBranch,
    detailed = false,
    onStatus,
    actions,
    empty,
}: {
    accounts: AccountRow[];
    showBranch: boolean;
    detailed?: boolean;
    onStatus?: (account: AccountRow) => void;
    actions: (account: AccountRow) => ReactNode;
    empty: ReactNode;
}) {
    const columns = detailed
        ? detailedColumns(onStatus, actions)
        : compactColumns(showBranch, actions);

    return (
        <DataTable
            columns={columns}
            rows={accounts}
            rowKey={(a) => a.id}
            empty={<EmptyState title="No accounts yet" description={empty} />}
        />
    );
}

function compactColumns(
    showBranch: boolean,
    actions: (account: AccountRow) => ReactNode,
): Column<AccountRow>[] {
    return [
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
            cell: (a) => <StatusCell account={a} />,
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
}

function detailedColumns(
    onStatus: ((account: AccountRow) => void) | undefined,
    actions: (account: AccountRow) => ReactNode,
): Column<AccountRow>[] {
    return [
        {
            key: 'account',
            header: 'Account',
            className: 'min-w-[160px]',
            cell: (a) => (
                <div>
                    <div className="font-medium">{a.label}</div>
                    <div className="text-xs text-tx2">{a.holder}</div>
                    <div className="mt-1 text-xs text-tx3">
                        {a.branch.code} · {a.branch.name}
                    </div>
                </div>
            ),
        },
        {
            key: 'limits',
            header: 'Deposit limits',
            className: 'min-w-[150px]',
            cell: (a) => (
                <div>
                    <Line label="Minimum" value={formatLimit(a.min_amount)} />
                    <Line label="Maximum" value={formatLimit(a.max_amount)} />
                    <Line
                        label="Per day"
                        value={formatLimit(a.daily_amount_limit)}
                    />
                    <Line
                        label="Count"
                        value={
                            a.daily_count_limit === null
                                ? 'Any'
                                : String(a.daily_count_limit)
                        }
                    />
                    <div className="mt-1 text-[11px] text-tx3">
                        Used today {formatPaise(a.used_today.amount, 0)} ·{' '}
                        {a.used_today.count} payments · {a.max_open_sessions}{' '}
                        open at once
                    </div>
                </div>
            ),
        },
        {
            key: 'credentials',
            header: 'Credentials',
            className: 'min-w-[280px]',
            cell: (a) => (
                <div>
                    {a.is_bank_enabled && (
                        <>
                            <Line label="Bank" value={a.bank_name} />
                            <Line label="Holder" value={a.holder} />
                            <Line
                                label="Account"
                                value={a.account_number}
                                mono
                            />
                            <Line label="IFSC" value={a.ifsc} mono />
                        </>
                    )}
                    {a.is_upi_enabled && (
                        <>
                            <Line label="UPI" value={a.upi_id} mono />
                            <Line label="Shown as" value={a.upi_display_name} />
                            <Line label="UPI code" value={a.upi_code} mono />
                        </>
                    )}
                    <div className="mt-1.5 flex flex-wrap gap-1">
                        <Method on={a.is_bank_enabled}>Bank</Method>
                        <Method on={a.is_upi_enabled}>UPI</Method>
                        <Method on={a.is_qr_enabled}>QR</Method>
                        <Method on={a.is_intent_enabled}>Intent</Method>
                    </div>
                </div>
            ),
        },
        {
            key: 'intent',
            header: 'UPI intent',
            cell: (a) => (
                <span
                    className={cn(
                        'text-xs font-medium',
                        a.is_intent_enabled ? 'text-ok' : 'text-tx3',
                    )}
                >
                    {a.is_intent_enabled ? 'Allowed' : 'Off'}
                </span>
            ),
        },
        {
            key: 'verification',
            header: 'Verification',
            cell: (a) => <Verification account={a} />,
        },
        {
            key: 'status',
            header: 'Status',
            cell: (a) => (
                <StatusCell
                    account={a}
                    onClick={
                        onStatus && a.can.switch_to.length > 0
                            ? () => onStatus(a)
                            : undefined
                    }
                />
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
}

function StatusCell({
    account,
    onClick,
}: {
    account: AccountRow;
    onClick?: () => void;
}) {
    const badge = (
        <div>
            <StatusBadge
                status={account.status}
                label={account.status === 'rejected' ? 'Rejected' : undefined}
            />
            {account.status === 'rejected' && account.rejected_reason && (
                <div className="mt-1 max-w-[180px] text-[11px] text-er">
                    {account.rejected_reason}
                </div>
            )}
        </div>
    );

    if (!onClick) {
        return badge;
    }

    return (
        <button
            type="button"
            onClick={onClick}
            className="rounded-md text-left hover:bg-sf2"
        >
            {badge}
        </button>
    );
}

function Verification({ account }: { account: AccountRow }) {
    if (account.status === 'rejected') {
        return <StatusBadge status="rejected" label="Rejected" />;
    }

    if (
        account.status === 'verification_pending' ||
        account.status === 'new'
    ) {
        return (
            <StatusBadge
                status="verification_pending"
                label="Pending"
            />
        );
    }

    if (
        account.verified_at ||
        account.status === 'verified' ||
        account.status === 'active' ||
        account.status === 'paused'
    ) {
        return <StatusBadge status="verified" label="Verified" />;
    }

    return <StatusBadge status="new" label="Not verified" />;
}

function Line({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string | null | undefined;
    mono?: boolean;
}) {
    return (
        <div className="flex gap-2 text-xs leading-5">
            <span className="w-[64px] shrink-0 text-tx3">{label}</span>
            <span className={cn('break-all', mono && 'font-mono')}>
                {value && value !== '' ? value : '—'}
            </span>
        </div>
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
