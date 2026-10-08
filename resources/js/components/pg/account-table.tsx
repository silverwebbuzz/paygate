import type { ReactNode } from 'react';
import { formatLimit, formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { AccountRow } from './account-form-dialog';
import { DataTable } from './data-table';
import type { Column } from './data-table';
import { EmptyState } from './empty-state';
import { SelectInput } from './field';
import { StatusBadge } from './status-badge';

export type AccountVerification = AccountRow['verification'];

export function AccountTable({
    accounts,
    showBranch,
    detailed = false,
    onVerification,
    onActive,
    actions,
    empty,
}: {
    accounts: AccountRow[];
    showBranch: boolean;
    detailed?: boolean;
    onVerification?: (
        account: AccountRow,
        verification: AccountVerification,
    ) => void;
    onActive?: (account: AccountRow, active: boolean) => void;
    actions?: (account: AccountRow) => ReactNode;
    empty: ReactNode;
}) {
    const columns = detailed
        ? detailedColumns(showBranch, onVerification, onActive, actions)
        : compactColumns(showBranch, actions ?? (() => null));

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
                              {a.branch?.code}
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
    showBranch: boolean,
    onVerification:
        | ((account: AccountRow, verification: AccountVerification) => void)
        | undefined,
    onActive: ((account: AccountRow, active: boolean) => void) | undefined,
    actions: ((account: AccountRow) => ReactNode) | undefined,
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
                    </div>
                </div>
            ),
        },
        ...(showBranch
            ? [
                  {
                      ...top,
                      key: 'branch',
                      header: 'Branch',
                      className: 'whitespace-nowrap',
                      cell: (a: AccountRow) => (
                          <div>
                              <div className="font-medium">
                                  {a.branch?.code}
                              </div>
                              <div className="text-xs text-tx3">
                                  {a.branch?.name}
                              </div>
                          </div>
                      ),
                  },
              ]
            : []),
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
            key: 'details',
            header: 'Bank/UPI details',
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
            cell: (a) => <Verification account={a} onChange={onVerification} />,
        },
        {
            ...top,
            key: 'status',
            header: 'Status',
            cell: (a) => <StatusCell account={a} onActive={onActive} />,
        },
        ...(actions
            ? [
                  {
                      ...top,
                      key: 'actions',
                      header: '',
                      align: 'right' as const,
                      cell: (a: AccountRow) => (
                          <div className="flex justify-end gap-1.5">
                              {actions(a)}
                          </div>
                      ),
                  },
              ]
            : []),
    ];
}

const VERIFICATION_OPTIONS = [
    ['verified', 'Verified'],
    ['pending', 'Pending'],
    ['unverified', 'Unverified'],
] as const;

function StatusCell({
    account,
    onActive,
}: {
    account: AccountRow;
    onActive?: (account: AccountRow, active: boolean) => void;
}) {
    const active = account.status === 'active';

    if (onActive && account.can.update) {
        const locked = !active && account.verification !== 'verified';

        return (
            <button
                type="button"
                role="switch"
                aria-checked={active}
                aria-label={`${account.label} is ${active ? 'active' : 'inactive'}`}
                disabled={locked}
                title={
                    locked
                        ? 'Verify the account before making it active.'
                        : undefined
                }
                onClick={() => onActive(account, !active)}
                className={cn(
                    'inline-flex items-center gap-2 text-xs font-medium',
                    locked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
                )}
            >
                <span
                    className={cn(
                        'relative h-5 w-9 flex-none rounded-full transition-colors',
                        active ? 'bg-ac' : 'bg-ln',
                    )}
                >
                    <span
                        className={cn(
                            'absolute top-0.5 size-4 rounded-full bg-white shadow transition-[left]',
                            active ? 'left-[18px]' : 'left-0.5',
                        )}
                    />
                </span>
                {active ? 'Active' : 'Inactive'}
            </button>
        );
    }

    return (
        <div className="flex flex-col items-start gap-1">
            <StatusBadge status={account.status} />
            {account.verification === 'unverified' &&
                account.rejected_reason && (
                    <div className="max-w-[180px] text-[11px] text-er">
                        {account.rejected_reason}
                    </div>
                )}
        </div>
    );
}

function Verification({
    account,
    onChange,
}: {
    account: AccountRow;
    onChange?: (account: AccountRow, verification: AccountVerification) => void;
}) {
    if (onChange && account.can.set_verification) {
        return (
            <SelectInput
                aria-label={`Verification for ${account.label}`}
                className="h-8 w-[140px] text-xs"
                value={account.verification}
                onChange={(event) =>
                    onChange(account, event.target.value as AccountVerification)
                }
            >
                {VERIFICATION_OPTIONS.map(([value, label]) => (
                    <option key={value} value={value}>
                        {label}
                    </option>
                ))}
            </SelectInput>
        );
    }

    return <StatusBadge status={account.verification} />;
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
