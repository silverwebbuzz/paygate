import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
    onStatus?: (account: AccountRow, status: AccountStatus) => void;
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
    onStatus:
        | ((account: AccountRow, status: AccountStatus) => void)
        | undefined,
    actions: (account: AccountRow) => ReactNode,
): Column<AccountRow>[] {
    const top = { valign: 'top' as const };

    return [
        {
            ...top,
            key: 'account',
            header: 'Account',
            className: 'min-w-[180px]',
            cell: (a) => (
                <div className="flex items-start gap-2.5">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand text-sm font-semibold text-white">
                        {(a.bank_name || a.label)
                            .trim()
                            .charAt(0)
                            .toUpperCase()}
                    </span>
                    <div className="min-w-0">
                        <div className="font-semibold">{a.label}</div>
                        <div className="text-xs text-tx2">{a.holder}</div>
                        <div className="mt-1 inline-flex rounded-md bg-sf2 px-1.5 py-0.5 text-[11px] text-tx3">
                            {a.branch.code} · {a.branch.name}
                        </div>
                    </div>
                </div>
            ),
        },
        {
            ...top,
            key: 'limits',
            header: 'Deposit limits',
            className: 'min-w-[148px]',
            cell: (a) => (
                <div className="space-y-1">
                    <Limit label="Minimum" value={formatLimit(a.min_amount)} />
                    <Limit label="Maximum" value={formatLimit(a.max_amount)} />
                    <Limit
                        label="Per day"
                        value={formatLimit(a.daily_amount_limit)}
                    />
                    <Limit
                        label="Count"
                        value={
                            a.daily_count_limit === null
                                ? 'Any'
                                : String(a.daily_count_limit)
                        }
                    />
                    <div className="pt-1 text-[11px] text-tx3">
                        Used today {formatPaise(a.used_today.amount, 0)} ·{' '}
                        {a.used_today.count} · {a.max_open_sessions} open
                    </div>
                </div>
            ),
        },
        {
            ...top,
            key: 'credentials',
            header: 'Credentials',
            className: 'min-w-[240px]',
            cell: (a) => (
                <div className="rounded-lg border border-ln2 bg-sf2 px-3 py-2">
                    {a.is_bank_enabled && (
                        <div>
                            <div className="text-xs font-medium">
                                {a.bank_name}
                            </div>
                            <div className="font-mono text-sm font-semibold tracking-wide">
                                {a.account_number}
                            </div>
                        </div>
                    )}
                    <div className="mt-1.5 flex flex-wrap gap-1">
                        {a.is_bank_enabled && a.ifsc && (
                            <Mark label="IFSC" value={a.ifsc} tone="in" mono />
                        )}
                        {a.is_upi_enabled && a.upi_id && (
                            <Mark
                                label="UPI"
                                value={a.upi_id}
                                tone="brand"
                                mono
                            />
                        )}
                        {a.upi_display_name && (
                            <Mark
                                label="Shown as"
                                value={a.upi_display_name}
                                tone="ok"
                            />
                        )}
                        {a.upi_code && (
                            <Mark
                                label="UPI code"
                                value={a.upi_code}
                                tone="nt"
                                mono
                            />
                        )}
                    </div>
                    <div className="mt-2 flex flex-wrap gap-1">
                        <Method on={a.is_bank_enabled}>Bank</Method>
                        <Method on={a.is_upi_enabled}>UPI</Method>
                        <Method on={a.is_qr_enabled}>QR</Method>
                    </div>
                </div>
            ),
        },
        {
            ...top,
            key: 'verification',
            header: 'Verification',
            cell: (a) => <Verification account={a} />,
        },
        {
            ...top,
            key: 'status',
            header: 'Status',
            cell: (a) => (
                <StatusCell
                    account={a}
                    onChange={
                        onStatus ? (status) => onStatus(a, status) : undefined
                    }
                />
            ),
        },
        {
            ...top,
            key: 'actions',
            header: '',
            align: 'right',
            cell: (a) => (
                <div className="flex justify-end gap-1.5">{actions(a)}</div>
            ),
        },
    ];
}

/** Account statuses in the order the "Change status" menu lists them. */
export const ACCOUNT_STATUSES = [
    ['verification_pending', 'Needs verification'],
    ['verified', 'Verified, not active'],
    ['active', 'Active'],
    ['paused', 'Paused'],
    ['rejected', 'Rejected'],
    ['disabled', 'Disabled'],
] as const;

export type AccountStatus = (typeof ACCOUNT_STATUSES)[number][0];

/**
 * The current status as a label, with a separate "Change status" menu below
 * it listing only the statuses this account can move to.
 */
function StatusCell({
    account,
    onChange,
}: {
    account: AccountRow;
    onChange?: (status: AccountStatus) => void;
}) {
    const choices = ACCOUNT_STATUSES.filter(
        ([value]) =>
            value !== account.status && account.can.switch_to.includes(value),
    );

    return (
        <div className="flex min-w-[170px] flex-col items-start gap-2">
            <StatusBadge
                status={account.status}
                label={account.status === 'rejected' ? 'Rejected' : undefined}
                className="h-[26px] px-2.5 text-[12.5px]"
            />
            {account.status === 'rejected' && account.rejected_reason && (
                <div className="max-w-[180px] text-[11px] text-er">
                    {account.rejected_reason}
                </div>
            )}
            {onChange && choices.length > 0 && (
                <DropdownMenu>
                    <DropdownMenuTrigger className="inline-flex h-7 items-center gap-1.5 rounded-md border border-ln bg-sf px-2.5 text-xs font-medium text-tx2 hover:border-ac/50 hover:text-tx data-[state=open]:border-ac data-[state=open]:text-tx">
                        Change status
                        <ChevronDown className="size-3.5" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="min-w-52">
                        <DropdownMenuLabel className="text-xs font-medium text-tx3">
                            Move to
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {choices.map(([value, label]) => (
                            <DropdownMenuItem
                                key={value}
                                onSelect={() => onChange(value)}
                                className="cursor-pointer"
                            >
                                <StatusBadge status={value} label={label} />
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );
}

function Verification({ account }: { account: AccountRow }) {
    if (account.status === 'rejected') {
        return <StatusBadge status="rejected" label="Rejected" />;
    }

    if (account.status === 'verification_pending' || account.status === 'new') {
        return <StatusBadge status="verification_pending" label="Pending" />;
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

function Mark({
    label,
    value,
    tone,
    mono = false,
}: {
    label: string;
    value: string;
    tone: 'in' | 'brand' | 'ok' | 'nt';
    mono?: boolean;
}) {
    const toneClass = {
        in: 'bg-inb text-in',
        brand: 'bg-acs text-ac',
        ok: 'bg-okb text-ok',
        nt: 'bg-sf text-tx2',
    }[tone];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-semibold',
                toneClass,
            )}
        >
            <span className="tracking-wide uppercase opacity-80">{label}</span>
            <span className={cn('font-medium text-tx', mono && 'font-mono')}>
                {value}
            </span>
        </span>
    );
}

function Limit({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-xs">
            <span className="text-tx3">{label}</span>
            <span className="font-semibold tabular-nums">{value}</span>
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
                on
                    ? 'border-ln bg-sf font-medium text-tx'
                    : 'text-tx3 line-through opacity-60',
            )}
        >
            {children}
        </span>
    );
}
