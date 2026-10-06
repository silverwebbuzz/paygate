import { Head, Link, router } from '@inertiajs/react';
import { History, Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AccountActions } from '@/components/pg/account-actions';
import { AccountFormDialog } from '@/components/pg/account-form-dialog';
import type { AccountRow } from '@/components/pg/account-form-dialog';
import { AccountTable } from '@/components/pg/account-table';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { KeyValues } from '@/components/pg/drawer';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { ViewTabs } from '@/components/pg/filter-bar';
import { FormDialog } from '@/components/pg/form-dialog';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { formatLimit } from '@/lib/money';
import accountsRoutes from '@/routes/admin/accounts';

type Branch = { id: string; code: string; name: string };

type Props = {
    accounts: { data: AccountRow[]; total: number };
    filters: { status: string | null; branch: string | null; search: string };
    counts: Record<string, number>;
    branches: Branch[];
    reveal?: {
        id: string;
        account_number: string | null;
        upi_id: string | null;
    } | null;
    can: { create: boolean; verify: boolean };
};

const TABS = [
    ['all', 'All'],
    ['verification_pending', 'Needs verification'],
    ['active', 'Active'],
    ['verified', 'Verified, not active'],
    ['paused', 'Paused'],
    ['rejected', 'Rejected'],
    ['disabled', 'Disabled'],
] as const;

export default function AdminAccounts({
    accounts,
    filters,
    counts,
    branches,
    reveal,
    can,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState<AccountRow | 'new' | null>(null);
    const [reviewing, setReviewing] = useState<AccountRow | null>(null);
    const [statusAccount, setStatusAccount] = useState<AccountRow | null>(
        null,
    );

    const visit = (next: Partial<Props['filters']>) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, search, ...next }).filter(
                ([, value]) => value !== null && value !== '',
            ),
        );
        router.get(
            accountsRoutes.index({ query }).url,
            {},
            { preserveState: true, replace: true },
        );
    };

    useEffect(() => {
        if (search === filters.search) return;
        const timer = setTimeout(() => visit({ search }), 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <>
            <Head title="Bank & UPI Accounts" />

            <PageHeader
                title="Bank & UPI Accounts"
                description="Collection accounts of every branch. The full bank and UPI details are shown here. Click a status to change it; a reason is kept in the account log, and a disabled account can be turned back on."
                actions={
                    <>
                        <Link
                            href={accountsRoutes.logs.index().url}
                            className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                        >
                            <History className="size-3.5" /> Account log
                        </Link>
                        {can.create && (
                            <PgButton
                                variant="primary"
                                onClick={() => setEditing('new')}
                            >
                                <Plus className="size-4" /> Add account
                            </PgButton>
                        )}
                    </>
                }
            />

            <KpiGrid>
                <StatTile
                    label="Active"
                    value={String(counts.active ?? 0)}
                    icon="●"
                    tone="ok"
                />
                <StatTile
                    label="Needs verification"
                    value={String(counts.verification_pending ?? 0)}
                    icon="◷"
                    tone="wn"
                />
                <StatTile
                    label="Paused"
                    value={String(counts.paused ?? 0)}
                    icon="‖"
                    tone="hd"
                />
                <StatTile
                    label="Rejected"
                    value={String(counts.rejected ?? 0)}
                    icon="✕"
                    tone="er"
                />
            </KpiGrid>

            <Panel className="overflow-hidden">
                <ViewTabs
                    views={TABS.map(([key, label]) => ({
                        key,
                        label,
                        count: counts[key] ?? 0,
                    }))}
                    active={filters.status ?? 'all'}
                    onChange={(key) =>
                        visit({ status: key === 'all' ? null : key })
                    }
                />
                <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] w-[260px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Label, holder, bank or last 4 digits"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                    <SelectInput
                        className="h-[30px] w-[220px] text-[12.5px]"
                        value={filters.branch ?? ''}
                        onChange={(event) =>
                            visit({ branch: event.target.value || null })
                        }
                    >
                        <option value="">All branches</option>
                        {branches.map((branch) => (
                            <option key={branch.id} value={branch.id}>
                                {branch.code} · {branch.name}
                            </option>
                        ))}
                    </SelectInput>
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">
                        {accounts.total} accounts
                    </span>
                </div>
                <AccountTable
                    accounts={accounts.data}
                    showBranch
                    detailed
                    onStatus={setStatusAccount}
                    empty="Branches add their accounts in the branch portal; you can also add one here."
                    actions={(account) => (
                        <>
                            {account.can.verify && (
                                <PgButton
                                    variant="primary"
                                    className="h-7 text-xs"
                                    onClick={() => {
                                        setReviewing(account);
                                        router.reload({
                                            only: ['reveal'],
                                            data: { reveal: account.id },
                                        });
                                    }}
                                >
                                    Review
                                </PgButton>
                            )}
                            <AccountActions
                                account={account}
                                hideStatus
                                statusUrl={
                                    accountsRoutes.status(account.id).url
                                }
                                onEdit={() => setEditing(account)}
                            />
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
                        </>
                    )}
                />
            </Panel>

            {editing !== null && (
                <AccountFormDialog
                    account={editing === 'new' ? undefined : editing}
                    branches={editing === 'new' ? branches : undefined}
                    url={
                        editing === 'new'
                            ? accountsRoutes.store().url
                            : accountsRoutes.update(editing.id).url
                    }
                    method={editing === 'new' ? 'post' : 'put'}
                    onClose={() => setEditing(null)}
                />
            )}

            {statusAccount && (
                <StatusDialog
                    account={statusAccount}
                    onClose={() => setStatusAccount(null)}
                />
            )}

            {reviewing && (
                <ReviewDialog
                    account={reviewing}
                    numbers={
                        reveal && reveal.id === reviewing.id ? reveal : null
                    }
                    onClose={() => setReviewing(null)}
                />
            )}
        </>
    );
}

const STATUS_OPTIONS = [
    ['verification_pending', 'Needs verification'],
    ['verified', 'Verified, not receiving customers'],
    ['active', 'Active'],
    ['paused', 'Paused'],
    ['rejected', 'Rejected'],
    ['disabled', 'Disabled'],
] as const;

/**
 * Verification: the full numbers (loaded on request and audited), then
 * approve, or reject with a reason the branch will see.
 */
function StatusDialog({
    account,
    onClose,
}: {
    account: AccountRow;
    onClose: () => void;
}) {
    const choices = STATUS_OPTIONS.filter(([value]) =>
        account.can.switch_to.includes(value),
    );
    const [status, setStatus] = useState(choices[0]?.[0] ?? 'active');
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    return (
        <FormDialog
            open
            width={480}
            onOpenChange={(open) => !open && onClose()}
            title={`Change status of ${account.label}`}
            description={`${account.branch.code} · ${account.branch.name}. Only an active account receives customers. You can change this again later, including turning a disabled account back on.`}
            submitLabel="Save status"
            processing={processing || reason.trim() === ''}
            onSubmit={() =>
                router.put(
                    accountsRoutes.status(account.id).url,
                    { status, reason },
                    {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                        onSuccess: onClose,
                        onError: (errors: Record<string, string>) =>
                            setError(Object.values(errors)[0]),
                    },
                )
            }
        >
            <Field label="New status" required>
                <SelectInput
                    value={status}
                    onChange={(event) =>
                        setStatus(
                            event.target.value as (typeof STATUS_OPTIONS)[number][0],
                        )
                    }
                >
                    {choices.map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </SelectInput>
            </Field>
            <Field
                label="Reason"
                required
                hint={
                    status === 'rejected'
                        ? 'The branch sees this reason.'
                        : 'Kept in the account log.'
                }
            >
                <TextInput
                    autoFocus
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    placeholder="Why this status is changing"
                />
            </Field>
            {error && <p className="text-xs text-er">{error}</p>}
        </FormDialog>
    );
}

function ReviewDialog({
    account,
    numbers,
    onClose,
}: {
    account: AccountRow;
    numbers: { account_number: string | null; upi_id: string | null } | null;
    onClose: () => void;
}) {
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const options = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onSuccess: onClose,
        onError: (errors: Record<string, string>) =>
            setError(Object.values(errors)[0]),
    };

    return (
        <FormDialog
            open
            width={560}
            onOpenChange={(open) => !open && onClose()}
            title={`Verify ${account.label}`}
            description={`${account.branch.code} · ${account.branch.name}. Check these details against the branch's bank documents before approving.`}
            submitLabel={rejecting ? 'Reject account' : 'Approve'}
            processing={processing || (rejecting && reason.trim() === '')}
            onSubmit={() =>
                rejecting
                    ? router.post(
                          accountsRoutes.reject(account.id).url,
                          { reason },
                          options,
                      )
                    : router.post(
                          accountsRoutes.approve(account.id).url,
                          {},
                          options,
                      )
            }
        >
            <KeyValues
                items={[
                    { label: 'Account holder', value: account.holder },
                    {
                        label: 'Bank',
                        value: account.is_bank_enabled
                            ? account.bank_name
                            : null,
                    },
                    {
                        label: 'IFSC',
                        value: account.is_bank_enabled ? account.ifsc : null,
                        mono: true,
                    },
                    {
                        label: 'Account number',
                        value: account.is_bank_enabled
                            ? numbers
                                ? numbers.account_number
                                : 'Loading…'
                            : null,
                        mono: true,
                    },
                    {
                        label: 'UPI ID',
                        value: account.is_upi_enabled
                            ? numbers
                                ? numbers.upi_id
                                : 'Loading…'
                            : null,
                        mono: true,
                    },
                    {
                        label: 'Name shown to customers',
                        value: account.upi_display_name,
                    },
                    {
                        label: 'Per payment',
                        value: `${formatLimit(account.min_amount)} – ${formatLimit(account.max_amount)}`,
                    },
                    {
                        label: 'Daily limit',
                        value: formatLimit(account.daily_amount_limit),
                    },
                ]}
            />
            <p className="text-xs text-tx3">
                Viewing the full numbers is recorded in the audit log.
            </p>
            <label className="flex items-center gap-2 text-[13px]">
                <input
                    type="checkbox"
                    className="size-4 accent-ac"
                    checked={rejecting}
                    onChange={(event) => setRejecting(event.target.checked)}
                />
                The details don’t match: reject this account
            </label>
            {rejecting && (
                <TextInput
                    autoFocus
                    placeholder="Reason, shown to the branch (e.g. IFSC doesn't match the passbook)"
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                />
            )}
            {error && <p className="text-xs text-er">{error}</p>}
        </FormDialog>
    );
}
