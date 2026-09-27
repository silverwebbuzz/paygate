import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { OrgPicker } from '@/components/pg/org-picker';
import type { PickerItem } from '@/components/pg/org-picker';
import { PageHeader } from '@/components/pg/page-header';
import { StatusBadge } from '@/components/pg/status-badge';
import { SwitchField } from '@/components/pg/switch-field';
import branchesRoutes from '@/routes/admin/branches';

type BranchForm = {
    code: string;
    name: string;
    is_deposit_enabled: boolean;
    is_withdrawal_enabled: boolean;
    deposit_limit_type: string;
    deposit_min_amount: string;
    deposit_max_amount: string;
    deposit_daily_limit: string;
    withdrawal_min_amount: string;
    withdrawal_max_amount: string;
    withdrawal_daily_limit: string;
    deposit_rate: string;
    withdrawal_rate: string;
    partner_ids: string[];
    admin_name: string;
    admin_email: string;
    activate: boolean;
};

type Existing = Partial<Record<keyof BranchForm, unknown>> & {
    id: string;
    status: string;
};

type Props = {
    branch: Existing | null;
    partners: PickerItem[];
    can: {
        rates: boolean;
        mappings: boolean;
        invite: boolean;
        activate: boolean;
    };
};

const str = (value: unknown, fallback = '') =>
    typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : fallback;

const num = (value: string | undefined) =>
    value === undefined || value === '' ? null : Number(value);

export default function BranchFormPage({ branch, partners, can }: Props) {
    const editing = branch !== null;
    const isDraft = !editing || branch.status === 'draft';
    const [search, setSearch] = useState('');
    const form = useForm<BranchForm>({
        code: str(branch?.code),
        name: str(branch?.name),
        is_deposit_enabled:
            typeof branch?.is_deposit_enabled === 'boolean'
                ? branch.is_deposit_enabled
                : true,
        is_withdrawal_enabled:
            typeof branch?.is_withdrawal_enabled === 'boolean'
                ? branch.is_withdrawal_enabled
                : false,
        deposit_limit_type: str(branch?.deposit_limit_type, 'daily_reset'),
        deposit_min_amount: str(branch?.deposit_min_amount),
        deposit_max_amount: str(branch?.deposit_max_amount),
        deposit_daily_limit: str(branch?.deposit_daily_limit),
        withdrawal_min_amount: str(branch?.withdrawal_min_amount),
        withdrawal_max_amount: str(branch?.withdrawal_max_amount),
        withdrawal_daily_limit: str(branch?.withdrawal_daily_limit),
        deposit_rate: str(branch?.deposit_rate),
        withdrawal_rate: str(branch?.withdrawal_rate),
        partner_ids: Array.isArray(branch?.partner_ids)
            ? (branch.partner_ids as string[])
            : [],
        admin_name: '',
        admin_email: '',
        activate: false,
    });
    const { data, setData } = form;
    const errors = form.errors as Partial<Record<string, string>>;

    const blockers = [
        !data.is_deposit_enabled &&
            !data.is_withdrawal_enabled &&
            'Enable deposits or withdrawals',
        data.is_deposit_enabled &&
            data.deposit_rate === '' &&
            'Set the deposit commission',
        data.is_withdrawal_enabled &&
            data.withdrawal_rate === '' &&
            'Set the withdrawal commission',
    ].filter(Boolean) as string[];

    const submit = (activate: boolean) => {
        form.transform((values) => ({
            ...values,
            activate,
            ...(editing
                ? { admin_name: undefined, admin_email: undefined }
                : {}),
        }));
        const options = { preserveScroll: true };

        if (editing) {
            form.put(branchesRoutes.update(branch.id).url, options);
        } else {
            form.post(branchesRoutes.store().url, options);
        }
    };

    const input = (
        key: keyof BranchForm,
        label: string,
        props: ComponentProps<typeof TextInput> & { hint?: ReactNode } = {},
    ) => {
        const { hint, ...rest } = props;

        return (
            <Field label={label} hint={hint} error={errors[key]}>
                <TextInput
                    value={data[key] as string}
                    invalid={!!errors[key]}
                    onChange={(event) => setData(key, event.target.value)}
                    {...rest}
                />
            </Field>
        );
    };

    const money = (
        key: keyof BranchForm,
        label: string,
        hint = 'Empty = no limit',
    ) =>
        input(key, label, {
            inputMode: 'decimal',
            pattern: '\\d{1,11}(\\.\\d{1,2})?',
            placeholder: 'No limit',
            hint,
            onChange: (event) =>
                setData(key, event.target.value.replace(/[,₹\s]/g, '')),
        });

    const rate = (key: 'deposit_rate' | 'withdrawal_rate', label: string) =>
        input(key, label, {
            inputMode: 'decimal',
            pattern: '\\d{1,3}(\\.\\d{1,4})?',
            disabled: !can.rates,
            hint: can.rates
                ? 'What PayGate pays this branch, % of the gross amount. Applies from now; history is kept.'
                : 'You don’t have permission to change commission.',
        });

    const selected = partners.filter((p) => data.partner_ids.includes(p.id));
    const title = editing ? `Edit ${str(branch.name)}` : 'Create branch';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description={
                    editing ? (
                        <span className="inline-flex items-center gap-2">
                            {str(branch.code)}{' '}
                            <StatusBadge status={branch.status} />
                        </span>
                    ) : (
                        'A new branch starts as a draft. Its accounts receive customers once it is active and they are verified.'
                    )
                }
            />

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit(false);
                }}
                className="flex flex-col gap-4"
            >
                <Section title="Branch" description="Who the branch is.">
                    {input('name', 'Branch name', {
                        required: true,
                        autoFocus: !editing,
                    })}
                    {input('code', 'Branch code', {
                        required: true,
                        maxLength: 30,
                        pattern: '[A-Z0-9][A-Z0-9_\\-]*',
                        disabled: !isDraft,
                        className: 'font-mono uppercase',
                        onChange: (event) =>
                            setData('code', event.target.value.toUpperCase()),
                        hint: isDraft
                            ? 'Unique. Capitals, digits, - and _. Fixed once live.'
                            : 'Fixed: the branch is live.',
                    })}
                </Section>

                <Section
                    title="Deposits"
                    description="Customers of mapped partners pay into this branch’s accounts."
                >
                    <div className="sm:col-span-2">
                        <SwitchField
                            label="Deposits enabled"
                            checked={data.is_deposit_enabled}
                            onChange={(v) => setData('is_deposit_enabled', v)}
                        />
                    </div>
                    <Field
                        label="Limit type"
                        error={errors.deposit_limit_type}
                        hint={
                            data.deposit_limit_type === 'topup'
                                ? 'A running allowance: each deposit uses it up, Admin tops it up.'
                                : 'A cap on deposits per day, restarting at 00:00 IST.'
                        }
                    >
                        <SelectInput
                            value={data.deposit_limit_type}
                            onChange={(event) =>
                                setData(
                                    'deposit_limit_type',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="daily_reset">
                                Daily limit (resets 00:00 IST)
                            </option>
                            <option value="topup">Top-up balance</option>
                        </SelectInput>
                    </Field>
                    {data.deposit_limit_type === 'daily_reset' ? (
                        money('deposit_daily_limit', 'Daily deposit limit')
                    ) : (
                        <div />
                    )}
                    {money('deposit_min_amount', 'Minimum deposit')}
                    {money('deposit_max_amount', 'Maximum deposit')}
                </Section>

                <Section
                    title="Withdrawals"
                    description="The branch pays customers’ withdrawals."
                >
                    <div className="sm:col-span-2">
                        <SwitchField
                            label="Withdrawals enabled"
                            checked={data.is_withdrawal_enabled}
                            onChange={(v) =>
                                setData('is_withdrawal_enabled', v)
                            }
                        />
                    </div>
                    {money('withdrawal_min_amount', 'Minimum per payout')}
                    {money('withdrawal_max_amount', 'Maximum per payout')}
                    {money('withdrawal_daily_limit', 'Daily withdrawal limit')}
                </Section>

                <Section
                    title="Commission"
                    description="What PayGate pays the branch. Partners pay PayGate their own rate; the difference is the platform margin."
                >
                    {rate('deposit_rate', 'Deposit commission (%)')}
                    {rate('withdrawal_rate', 'Withdrawal commission (%)')}
                    <div className="sm:col-span-2">
                        <Margins
                            partners={selected}
                            deposit={num(data.deposit_rate)}
                            withdrawal={num(data.withdrawal_rate)}
                        />
                    </div>
                </Section>

                <Section
                    title="Partners"
                    description="Partners whose customers this branch may serve. Pair-level settings are on the Mapping page."
                >
                    <div className="sm:col-span-2">
                        <OrgPicker
                            noun="partner"
                            emptyHint="No partners exist yet."
                            items={partners}
                            selected={data.partner_ids}
                            search={search}
                            onSearch={setSearch}
                            disabled={!can.mappings}
                            onChange={(ids) => setData('partner_ids', ids)}
                        />
                    </div>
                </Section>

                {!editing && can.invite && (
                    <Section
                        title="Branch admin"
                        description="Optional. They get an email to set a password, and can then add accounts and invite their operators."
                    >
                        {input('admin_name', 'Full name')}
                        {input('admin_email', 'Email', { type: 'email' })}
                    </Section>
                )}

                <Panel className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                    <div className="text-xs text-tx3">
                        {errors.status ? (
                            <span className="text-er">{errors.status}</span>
                        ) : (
                            isDraft &&
                            blockers.length > 0 &&
                            `Can’t go live yet: ${blockers.join('; ')}.`
                        )}
                    </div>
                    <div className="flex gap-2">
                        <Link
                            href={branchesRoutes.index().url}
                            className="inline-flex h-8 items-center px-2 text-[13px] font-medium text-tx2"
                        >
                            Cancel
                        </Link>
                        <PgButton
                            type="submit"
                            variant={
                                isDraft && can.activate
                                    ? 'secondary'
                                    : 'primary'
                            }
                            disabled={form.processing}
                        >
                            {editing ? 'Save changes' : 'Create as draft'}
                        </PgButton>
                        {isDraft && can.activate && (
                            <PgButton
                                variant="primary"
                                disabled={
                                    form.processing || blockers.length > 0
                                }
                                onClick={() => submit(true)}
                            >
                                {editing
                                    ? 'Save & activate'
                                    : 'Create & activate'}
                            </PgButton>
                        )}
                    </div>
                </Panel>
            </form>
        </>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <Panel className="grid gap-4 p-5 md:grid-cols-[220px_minmax(0,1fr)]">
            <div>
                <div className="text-sm font-semibold">{title}</div>
                <div className="mt-0.5 text-xs text-tx3">{description}</div>
            </div>
            <div className="grid content-start gap-4 sm:grid-cols-2">
                {children}
            </div>
        </Panel>
    );
}

/** Margin per selected partner: partner rate − this branch's rate. */
function Margins({
    partners,
    deposit,
    withdrawal,
}: {
    partners: PickerItem[];
    deposit: number | null;
    withdrawal: number | null;
}) {
    if (partners.length === 0) {
        return (
            <p className="text-xs text-tx3">
                Select partners below to see the platform’s margin with each.
            </p>
        );
    }

    const cell = (
        partnerRate: string | undefined,
        branchRate: number | null,
    ) => {
        if (partnerRate === undefined)
            return <span className="text-tx3">No partner rate</span>;
        if (branchRate === null)
            return (
                <span className="text-tx3">{Number(partnerRate)}% partner</span>
            );
        const margin =
            Math.round((Number(partnerRate) - branchRate) * 10000) / 10000;

        return (
            <span className={margin < 0 ? 'font-medium text-er' : ''}>
                {margin > 0 ? '+' : ''}
                {margin}%{' '}
                <span className="text-tx3">
                    ({Number(partnerRate)}% from partner)
                </span>
            </span>
        );
    };

    return (
        <div className="overflow-hidden rounded-lg border border-ln">
            <div className="bg-sf2 px-3 py-2 text-xs font-medium text-tx2">
                Platform margin per partner (partner rate − branch rate)
            </div>
            <table className="w-full text-[13px]">
                <tbody>
                    {partners.map((p) => (
                        <tr key={p.id} className="border-t border-ln2">
                            <td className="px-3 py-1.5">
                                <span className="font-mono text-xs text-tx3">
                                    {p.code}
                                </span>{' '}
                                {p.name}
                            </td>
                            <td className="px-3 py-1.5">
                                Deposit {cell(p.rates.deposit, deposit)}
                            </td>
                            <td className="px-3 py-1.5">
                                Withdrawal{' '}
                                {cell(p.rates.withdrawal, withdrawal)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
