import { Head, Link, useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { Field, SelectInput, TextArea, TextInput } from '@/components/pg/field';
import { PageHeader } from '@/components/pg/page-header';
import { StatusBadge } from '@/components/pg/status-badge';
import { SwitchField } from '@/components/pg/switch-field';
import { WizardSteps } from '@/components/pg/wizard-steps';
import { cn } from '@/lib/utils';
import partnersRoutes from '@/routes/admin/partners';

type Rates = { deposit?: string; withdrawal?: string };

type Branch = {
    id: string;
    code: string;
    name: string;
    status: string;
    rates: Rates;
};

type PartnerForm = {
    name: string;
    code: string;
    email: string;
    description: string;
    website_url: string;
    return_url: string;
    callback_url: string;
    payin_webhook_url: string;
    payout_webhook_url: string;
    ip_addresses: string;
    is_payin_enabled: boolean;
    manual_payment_type: string;
    allow_qr: boolean;
    allow_upi: boolean;
    allow_bank_transfer: boolean;
    is_h2h_enabled: boolean;
    session_ttl_minutes: string;
    deposit_min_amount: string;
    deposit_max_amount: string;
    deposit_daily_limit: string;
    deposit_rate: string;
    withdrawal_rate: string;
    payout_limit_type: string;
    is_payout_enabled: boolean;
    withdraw_url: string;
    payout_group: string;
    withdrawal_min_amount: string;
    withdrawal_max_amount: string;
    withdrawal_daily_limit: string;
    is_auto_withdrawal: boolean;
    is_partial_withdrawal: boolean;
    branch_ids: string[];
    activate: boolean;
};

type ExistingPartner = Partial<Record<keyof PartnerForm, unknown>> & {
    id: string;
    status: string;
};

type Props = {
    partner: ExistingPartner | null;
    branches: Branch[];
    can: { rates: boolean; mappings: boolean; ips: boolean; activate: boolean };
};

/** The design's 7 steps; `fields` maps server errors back to their step. */
const STEPS: {
    title: string;
    description: string;
    fields: (keyof PartnerForm)[];
}[] = [
    {
        title: 'Basic information',
        description: 'Who the partner is.',
        fields: ['name', 'code', 'email', 'description', 'website_url'],
    },
    {
        title: 'API & security',
        description:
            'Endpoints PayGate calls, and where requests may come from.',
        fields: [
            'return_url',
            'callback_url',
            'payin_webhook_url',
            'payout_webhook_url',
            'ip_addresses',
        ],
    },
    {
        title: 'Payment configuration',
        description: 'Pay-in methods offered on the checkout page, and limits.',
        fields: [
            'is_payin_enabled',
            'manual_payment_type',
            'allow_qr',
            'allow_upi',
            'allow_bank_transfer',
            'is_h2h_enabled',
            'session_ttl_minutes',
            'deposit_min_amount',
            'deposit_max_amount',
            'deposit_daily_limit',
        ],
    },
    {
        title: 'Commission',
        description:
            'What the partner pays PayGate, as a % of the gross amount.',
        fields: ['deposit_rate', 'withdrawal_rate', 'payout_limit_type'],
    },
    {
        title: 'Withdrawal',
        description: 'Payout rules for this partner.',
        fields: [
            'is_payout_enabled',
            'withdraw_url',
            'payout_group',
            'withdrawal_min_amount',
            'withdrawal_max_amount',
            'withdrawal_daily_limit',
            'is_auto_withdrawal',
            'is_partial_withdrawal',
        ],
    },
    {
        title: 'Branch mapping',
        description: 'Branches that may collect and pay out for this partner.',
        fields: ['branch_ids'],
    },
    {
        title: 'Review',
        description: 'Confirm everything before saving.',
        fields: ['activate'],
    },
];

const PAYMENT_TYPES = [
    ['bank_details', 'Manual bank details'],
    ['intent', 'UPI intent'],
    ['dynamic_qr', 'Dynamic QR'],
] as const;

const text = (value: unknown, fallback = '') =>
    typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : fallback;

function initial(partner: ExistingPartner | null): PartnerForm {
    const p = partner ?? ({} as ExistingPartner);
    const flag = (key: keyof PartnerForm, fallback: boolean) =>
        typeof p[key] === 'boolean' ? (p[key] as boolean) : fallback;

    return {
        name: text(p.name),
        code: text(p.code),
        email: text(p.email),
        description: text(p.description),
        website_url: text(p.website_url),
        return_url: text(p.return_url),
        callback_url: text(p.callback_url),
        payin_webhook_url: text(p.payin_webhook_url),
        payout_webhook_url: text(p.payout_webhook_url),
        ip_addresses: text(p.ip_addresses),
        is_payin_enabled: flag('is_payin_enabled', true),
        manual_payment_type: text(p.manual_payment_type),
        allow_qr: flag('allow_qr', true),
        allow_upi: flag('allow_upi', true),
        allow_bank_transfer: flag('allow_bank_transfer', true),
        is_h2h_enabled: flag('is_h2h_enabled', false),
        session_ttl_minutes: text(p.session_ttl_minutes, '15'),
        deposit_min_amount: text(p.deposit_min_amount),
        deposit_max_amount: text(p.deposit_max_amount),
        deposit_daily_limit: text(p.deposit_daily_limit),
        deposit_rate: text(p.deposit_rate),
        withdrawal_rate: text(p.withdrawal_rate),
        payout_limit_type: text(p.payout_limit_type, 'daily_reset'),
        is_payout_enabled: flag('is_payout_enabled', false),
        withdraw_url: text(p.withdraw_url),
        payout_group: text(p.payout_group),
        withdrawal_min_amount: text(p.withdrawal_min_amount),
        withdrawal_max_amount: text(p.withdrawal_max_amount),
        withdrawal_daily_limit: text(p.withdrawal_daily_limit),
        is_auto_withdrawal: flag('is_auto_withdrawal', false),
        is_partial_withdrawal: flag('is_partial_withdrawal', false),
        branch_ids: Array.isArray(p.branch_ids)
            ? (p.branch_ids as string[])
            : [],
        activate: false,
    };
}

/** Rates as numbers for comparisons in the UI only (the server compares exactly). */
const rate = (value: string | undefined) =>
    value === undefined || value === '' ? null : Number(value);

export default function PartnerFormPage({ partner, branches, can }: Props) {
    const editing = partner !== null;
    const isDraft = !editing || partner.status === 'draft';
    const [step, setStep] = useState(0);
    const [reached, setReached] = useState(editing ? STEPS.length - 1 : 0);
    const [branchSearch, setBranchSearch] = useState('');
    const formRef = useRef<HTMLFormElement>(null);
    const form = useForm<PartnerForm>(initial(partner));
    const { data, setData } = form;
    const errors = form.errors as Partial<Record<string, string>>;

    const errorSteps = STEPS.flatMap((s, index) =>
        Object.keys(errors).some((key) =>
            s.fields.some(
                (field) => key === field || key.startsWith(`${field}.`),
            ),
        )
            ? [index]
            : [],
    );

    const goTo = (index: number) => {
        setStep(index);
        setReached((current) => Math.max(current, index));
    };

    const next = () => {
        if (formRef.current?.reportValidity() === false) return;
        goTo(step + 1);
    };

    const submit = (activate: boolean) => {
        form.transform((values) => ({ ...values, activate }));
        const options = {
            preserveScroll: true,
            onError: (errs: Record<string, string>) => {
                const first = STEPS.findIndex((s) =>
                    Object.keys(errs).some((key) =>
                        s.fields.some(
                            (field) =>
                                key === field || key.startsWith(`${field}.`),
                        ),
                    ),
                );
                if (first >= 0) setStep(first);
            },
        };

        if (editing) {
            form.put(partnersRoutes.update(partner.id).url, options);
        } else {
            form.post(partnersRoutes.store().url, options);
        }
    };

    const selectedBranches = branches.filter((branch) =>
        data.branch_ids.includes(branch.id),
    );
    const negative = (direction: 'deposit' | 'withdrawal') => {
        const partnerRate = rate(data[`${direction}_rate`]);

        return partnerRate === null
            ? []
            : selectedBranches.filter(
                  (branch) =>
                      (rate(branch.rates[direction]) ?? -1) > partnerRate,
              );
    };
    const blockers = [
        !data.is_payin_enabled &&
            !data.is_payout_enabled &&
            'Enable pay-in or pay-out',
        data.is_payin_enabled &&
            data.deposit_rate === '' &&
            'Set the deposit commission',
        data.is_payout_enabled &&
            data.withdrawal_rate === '' &&
            'Set the withdrawal commission',
    ].filter(Boolean) as string[];

    const money = (
        key: keyof PartnerForm,
        label: string,
        hint = 'Empty = no limit',
    ) => (
        <Field label={label} hint={hint} error={errors[key]}>
            <div className="relative">
                <span className="absolute top-1/2 left-3 -translate-y-1/2 text-sm text-tx3">
                    ₹
                </span>
                <TextInput
                    inputMode="decimal"
                    pattern="\d{1,11}(\.\d{1,2})?"
                    placeholder="No limit"
                    className="pl-7"
                    value={data[key] as string}
                    invalid={!!errors[key]}
                    onChange={(event) =>
                        setData(key, event.target.value.replace(/[,₹\s]/g, ''))
                    }
                />
            </div>
        </Field>
    );

    const input = (
        key: keyof PartnerForm,
        label: string,
        props: Partial<ComponentProps<typeof TextInput>> & {
            hint?: ReactNode;
        } = {},
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

    const toggle = (
        key: keyof PartnerForm,
        label: string,
        hint?: string,
        disabled?: boolean,
    ) => (
        <SwitchField
            label={label}
            hint={hint}
            checked={data[key] as boolean}
            disabled={disabled}
            onChange={(checked) => setData(key, checked)}
        />
    );

    const title = editing ? `Edit ${text(partner.name)}` : 'Create partner';

    return (
        <>
            <Head title={title} />

            <PageHeader
                title={title}
                description={
                    editing ? (
                        <span className="inline-flex items-center gap-2">
                            {text(partner.code)}{' '}
                            <StatusBadge status={partner.status} />
                        </span>
                    ) : (
                        'A new partner starts as a draft and gets an API key. It can take payments once activated.'
                    )
                }
            />

            <Panel className="grid overflow-hidden md:grid-cols-[250px_minmax(0,1fr)]">
                <div className="border-b border-ln2 bg-sf2 p-3 md:border-r md:border-b-0">
                    <div className="px-3 pt-1 pb-2 text-xs text-tx3">
                        {editing ? 'Edit partner' : 'Create partner'} · Step{' '}
                        {step + 1} of {STEPS.length}
                    </div>
                    <WizardSteps
                        steps={STEPS}
                        current={step}
                        reachable={reached}
                        errors={errorSteps}
                        onSelect={setStep}
                    />
                </div>

                <form
                    ref={formRef}
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (step < STEPS.length - 1) next();
                    }}
                    className="flex min-h-[520px] flex-col gap-5 p-6"
                >
                    <div>
                        <div className="text-base font-semibold">
                            {STEPS[step].title}
                        </div>
                        <div className="text-[13px] text-tx2">
                            {STEPS[step].description}
                        </div>
                    </div>

                    <div className="grid flex-1 content-start gap-4 sm:grid-cols-2">
                        {step === 0 && (
                            <>
                                {input('name', 'Partner name', {
                                    required: true,
                                    maxLength: 255,
                                    autoFocus: true,
                                })}
                                {input('code', 'Partner code', {
                                    required: true,
                                    maxLength: 30,
                                    pattern: '[A-Z0-9][A-Z0-9_\\-]*',
                                    disabled: !isDraft,
                                    className: 'font-mono uppercase',
                                    onChange: (event) =>
                                        setData(
                                            'code',
                                            event.target.value.toUpperCase(),
                                        ),
                                    hint: isDraft
                                        ? 'Unique. Capitals, digits, - and _. Used in the API and reports; fixed once live.'
                                        : 'Fixed: the partner is live.',
                                })}
                                {input('email', 'Email', {
                                    type: 'email',
                                    required: true,
                                })}
                                {input('website_url', 'Website URL', {
                                    type: 'url',
                                    required: true,
                                    placeholder: 'https://',
                                })}
                                <div className="sm:col-span-2">
                                    <Field
                                        label="Description"
                                        error={errors.description}
                                    >
                                        <TextArea
                                            maxLength={1000}
                                            value={data.description}
                                            onChange={(event) =>
                                                setData(
                                                    'description',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            </>
                        )}

                        {step === 1 && (
                            <>
                                <Field
                                    label="API version"
                                    hint="All partners use the current version."
                                >
                                    <TextInput value="v1" disabled />
                                </Field>
                                {input('return_url', 'Return URL', {
                                    type: 'url',
                                    placeholder: 'https://',
                                    hint: 'Where the customer goes after paying.',
                                })}
                                {input('callback_url', 'Pay-in callback URL', {
                                    type: 'url',
                                    placeholder: 'https://',
                                })}
                                {input(
                                    'payin_webhook_url',
                                    'Pay-in webhook URL',
                                    {
                                        type: 'url',
                                        placeholder: 'https://',
                                        hint: 'We POST signed status updates here.',
                                    },
                                )}
                                {input(
                                    'payout_webhook_url',
                                    'Payout webhook URL',
                                    {
                                        type: 'url',
                                        placeholder: 'https://',
                                        hint: 'Optional.',
                                    },
                                )}
                                <div className="sm:col-span-2">
                                    <Field
                                        label="Allowed IPs"
                                        hint={
                                            can.ips
                                                ? 'The partner’s server addresses, one per line or comma separated. Ranges like 10.0.0.0/24 are allowed.'
                                                : 'You don’t have permission to change allowed IPs.'
                                        }
                                        error={errors.ip_addresses}
                                    >
                                        <TextArea
                                            className="font-mono text-[13px]"
                                            disabled={!can.ips}
                                            placeholder="52.66.45.184, 52.66.164.33"
                                            value={data.ip_addresses}
                                            onChange={(event) =>
                                                setData(
                                                    'ip_addresses',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            </>
                        )}

                        {step === 2 && (
                            <>
                                <div className="sm:col-span-2">
                                    {toggle(
                                        'is_payin_enabled',
                                        'Pay-in (deposits) enabled',
                                        'Customers of this partner can pay in.',
                                    )}
                                </div>
                                <Field
                                    label="Manual payment type"
                                    error={errors.manual_payment_type}
                                >
                                    <SelectInput
                                        value={data.manual_payment_type}
                                        onChange={(event) =>
                                            setData(
                                                'manual_payment_type',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="">Not set</option>
                                        {PAYMENT_TYPES.map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </SelectInput>
                                </Field>
                                {input(
                                    'session_ttl_minutes',
                                    'Payment page expires after (minutes)',
                                    {
                                        type: 'number',
                                        min: 1,
                                        max: 1440,
                                        required: true,
                                    },
                                )}
                                {toggle('allow_qr', 'QR code')}
                                {toggle('allow_upi', 'UPI')}
                                {toggle('allow_bank_transfer', 'Bank details')}
                                {toggle('is_h2h_enabled', 'Host-to-host (H2H)')}
                                {money('deposit_min_amount', 'Minimum deposit')}
                                {money('deposit_max_amount', 'Maximum deposit')}
                                {money(
                                    'deposit_daily_limit',
                                    'Daily deposit limit',
                                )}
                            </>
                        )}

                        {step === 3 && (
                            <>
                                {(['deposit', 'withdrawal'] as const).map(
                                    (direction) => (
                                        <Field
                                            key={direction}
                                            label={`${direction === 'deposit' ? 'Deposit' : 'Withdrawal'} commission`}
                                            hint={
                                                can.rates
                                                    ? 'Percent of the gross amount, up to 4 decimals. A change applies from now on; history is kept.'
                                                    : 'You don’t have permission to change commission.'
                                            }
                                            error={errors[`${direction}_rate`]}
                                        >
                                            <div className="relative">
                                                <TextInput
                                                    inputMode="decimal"
                                                    pattern="\d{1,3}(\.\d{1,4})?"
                                                    disabled={!can.rates}
                                                    className="pr-8"
                                                    value={
                                                        data[
                                                            `${direction}_rate`
                                                        ]
                                                    }
                                                    invalid={
                                                        !!errors[
                                                            `${direction}_rate`
                                                        ]
                                                    }
                                                    onChange={(event) =>
                                                        setData(
                                                            `${direction}_rate`,
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                <span className="absolute top-1/2 right-3 -translate-y-1/2 text-sm text-tx3">
                                                    %
                                                </span>
                                            </div>
                                        </Field>
                                    ),
                                )}
                                <Field
                                    label="Payout limit type"
                                    hint={
                                        data.payout_limit_type === 'topup'
                                            ? 'Payouts use a balance Admin tops up.'
                                            : 'The daily payout limit restarts at 00:00 IST.'
                                    }
                                    error={errors.payout_limit_type}
                                >
                                    <SelectInput
                                        value={data.payout_limit_type}
                                        onChange={(event) =>
                                            setData(
                                                'payout_limit_type',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="daily_reset">
                                            Daily limit (resets 00:00 IST)
                                        </option>
                                        <option value="topup">
                                            Top-up balance
                                        </option>
                                    </SelectInput>
                                </Field>
                                <div className="sm:col-span-2">
                                    <MarginTable
                                        branches={selectedBranches}
                                        deposit={rate(data.deposit_rate)}
                                        withdrawal={rate(data.withdrawal_rate)}
                                    />
                                </div>
                            </>
                        )}

                        {step === 4 && (
                            <>
                                <div className="sm:col-span-2">
                                    {toggle(
                                        'is_payout_enabled',
                                        'Payouts (withdrawals) enabled',
                                        'The partner can send withdrawal requests.',
                                    )}
                                </div>
                                {input('withdraw_url', 'Withdraw URL', {
                                    type: 'url',
                                    placeholder: 'https://',
                                })}
                                {input('payout_group', 'Group name', {
                                    maxLength: 100,
                                    hint: 'Optional label for grouping partners.',
                                })}
                                {money(
                                    'withdrawal_min_amount',
                                    'Minimum withdrawal',
                                )}
                                {money(
                                    'withdrawal_max_amount',
                                    'Maximum withdrawal',
                                )}
                                {money(
                                    'withdrawal_daily_limit',
                                    'Daily withdrawal limit',
                                )}
                                <div />
                                {toggle(
                                    'is_auto_withdrawal',
                                    'Auto withdrawal',
                                )}
                                {toggle(
                                    'is_partial_withdrawal',
                                    'Partial withdrawal',
                                )}
                            </>
                        )}

                        {step === 5 && (
                            <div className="sm:col-span-2">
                                <BranchPicker
                                    branches={branches}
                                    selected={data.branch_ids}
                                    search={branchSearch}
                                    onSearch={setBranchSearch}
                                    disabled={!can.mappings}
                                    onChange={(ids) =>
                                        setData('branch_ids', ids)
                                    }
                                />
                                {errors.branch_ids && (
                                    <p className="mt-2 text-xs text-er">
                                        {errors.branch_ids}
                                    </p>
                                )}
                            </div>
                        )}

                        {step === 6 && (
                            <div className="flex flex-col gap-4 sm:col-span-2">
                                <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                    <Summary
                                        label="Partner"
                                        value={`${data.name || '—'} · ${data.code || '—'}`}
                                    />
                                    <Summary
                                        label="API"
                                        value={`v1 · ${data.ip_addresses.split(/[\s,]+/).filter(Boolean).length} IPs allowed`}
                                    />
                                    <Summary
                                        label="Pay-in"
                                        value={
                                            data.is_payin_enabled
                                                ? [
                                                      data.allow_qr && 'QR',
                                                      data.allow_upi && 'UPI',
                                                      data.allow_bank_transfer &&
                                                          'Bank',
                                                      data.is_h2h_enabled &&
                                                          'H2H',
                                                  ]
                                                      .filter(Boolean)
                                                      .join(' · ') ||
                                                  'No methods'
                                                : 'Disabled'
                                        }
                                    />
                                    <Summary
                                        label="Payouts"
                                        value={
                                            data.is_payout_enabled
                                                ? `Enabled${data.is_auto_withdrawal ? ' · auto' : ''}`
                                                : 'Disabled'
                                        }
                                    />
                                    <Summary
                                        label="Commission"
                                        value={`${data.deposit_rate || '—'}% deposit · ${data.withdrawal_rate || '—'}% withdrawal`}
                                    />
                                    <Summary
                                        label="Branches"
                                        value={`${data.branch_ids.length} mapped`}
                                    />
                                </dl>
                                {!editing && (
                                    <p className="text-[13px] text-tx2">
                                        An API key is generated when you save.
                                        Its secret is shown once, so have a safe
                                        place ready to store it.
                                    </p>
                                )}
                                {(negative('deposit').length > 0 ||
                                    negative('withdrawal').length > 0) && (
                                    <Notice tone="warning">
                                        Some mapped branches earn more than this
                                        partner pays, so the platform loses the
                                        difference on those transactions. This
                                        is allowed and will be recorded in the
                                        audit log.
                                    </Notice>
                                )}
                                {blockers.length > 0 && isDraft && (
                                    <Notice tone="info">
                                        Can’t go live yet: {blockers.join('; ')}
                                        . You can save it as a draft.
                                    </Notice>
                                )}
                                {errors.activate && (
                                    <Notice tone="error">
                                        {errors.activate}
                                    </Notice>
                                )}
                                {errors.status && (
                                    <Notice tone="error">
                                        {errors.status}
                                    </Notice>
                                )}
                            </div>
                        )}
                    </div>

                    <div className="flex items-center justify-between gap-2 border-t border-ln2 pt-4">
                        <Link
                            href={partnersRoutes.index().url}
                            className="text-[13px] font-medium text-tx2 hover:text-tx"
                        >
                            Cancel
                        </Link>
                        <div className="flex gap-2">
                            {step > 0 && (
                                <PgButton onClick={() => setStep(step - 1)}>
                                    Back
                                </PgButton>
                            )}
                            {editing && step < STEPS.length - 1 && (
                                <PgButton
                                    disabled={form.processing}
                                    onClick={() => submit(false)}
                                >
                                    Save changes
                                </PgButton>
                            )}
                            {step < STEPS.length - 1 ? (
                                <PgButton variant="primary" onClick={next}>
                                    Next
                                </PgButton>
                            ) : (
                                <>
                                    <PgButton
                                        variant={
                                            isDraft && can.activate
                                                ? 'secondary'
                                                : 'primary'
                                        }
                                        disabled={form.processing}
                                        onClick={() => submit(false)}
                                    >
                                        {editing
                                            ? 'Save changes'
                                            : 'Create as draft'}
                                    </PgButton>
                                    {isDraft && can.activate && (
                                        <PgButton
                                            variant="primary"
                                            disabled={
                                                form.processing ||
                                                blockers.length > 0
                                            }
                                            onClick={() => submit(true)}
                                        >
                                            {editing
                                                ? 'Save & activate'
                                                : 'Create & activate'}
                                        </PgButton>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                </form>
            </Panel>
        </>
    );
}

function Summary({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-tx3">{label}</dt>
            <dd className="mt-0.5 text-[13px]">{value}</dd>
        </div>
    );
}

function Notice({
    tone,
    children,
}: {
    tone: 'warning' | 'info' | 'error';
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex items-start gap-2 rounded-lg px-3 py-2.5 text-[12.5px]',
                tone === 'warning' && 'bg-wnb text-wn',
                tone === 'info' && 'bg-inb text-in',
                tone === 'error' && 'bg-erb text-er',
            )}
        >
            <TriangleAlert className="mt-0.5 size-4 flex-none" />
            <span>{children}</span>
        </div>
    );
}

/** Platform margin per mapped branch: partner rate − branch rate. */
function MarginTable({
    branches,
    deposit,
    withdrawal,
}: {
    branches: Branch[];
    deposit: number | null;
    withdrawal: number | null;
}) {
    if (branches.length === 0) {
        return (
            <p className="text-xs text-tx3">
                Map branches (step 6) to see the platform’s margin per branch
                here.
            </p>
        );
    }

    const cell = (
        partnerRate: number | null,
        branchRate: string | undefined,
    ) => {
        const b = branchRate === undefined ? null : Number(branchRate);

        if (b === null) return <span className="text-tx3">No branch rate</span>;
        if (partnerRate === null)
            return <span className="text-tx3">{b}% branch</span>;

        const margin = Math.round((partnerRate - b) * 10000) / 10000;

        return (
            <span className={margin < 0 ? 'font-medium text-er' : 'text-tx'}>
                {margin > 0 ? '+' : ''}
                {margin}% <span className="text-tx3">({b}% to branch)</span>
            </span>
        );
    };

    return (
        <div className="overflow-hidden rounded-lg border border-ln">
            <div className="bg-sf2 px-3 py-2 text-xs font-medium text-tx2">
                Platform margin per mapped branch (partner rate − branch rate)
            </div>
            <table className="w-full text-[13px]">
                <thead>
                    <tr className="text-xs text-tx3">
                        <th className="px-3 py-1.5 text-left font-medium">
                            Branch
                        </th>
                        <th className="px-3 py-1.5 text-left font-medium">
                            Deposit
                        </th>
                        <th className="px-3 py-1.5 text-left font-medium">
                            Withdrawal
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {branches.map((branch) => (
                        <tr key={branch.id} className="border-t border-ln2">
                            <td className="px-3 py-1.5">
                                <span className="font-mono text-xs text-tx3">
                                    {branch.code}
                                </span>{' '}
                                {branch.name}
                            </td>
                            <td className="px-3 py-1.5">
                                {cell(deposit, branch.rates.deposit)}
                            </td>
                            <td className="px-3 py-1.5">
                                {cell(withdrawal, branch.rates.withdrawal)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function BranchPicker({
    branches,
    selected,
    search,
    onSearch,
    disabled,
    onChange,
}: {
    branches: Branch[];
    selected: string[];
    search: string;
    onSearch: (value: string) => void;
    disabled: boolean;
    onChange: (ids: string[]) => void;
}) {
    const term = search.trim().toLowerCase();
    const visible = branches.filter(
        (branch) =>
            term === '' ||
            branch.name.toLowerCase().includes(term) ||
            branch.code.toLowerCase().includes(term),
    );
    const toggle = (id: string) =>
        onChange(
            selected.includes(id)
                ? selected.filter((x) => x !== id)
                : [...selected, id],
        );

    if (branches.length === 0) {
        return (
            <p className="text-[13px] text-tx2">
                No branches exist yet. Branches are set up in Phase 5; you can
                map them to this partner later.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <TextInput
                    className="h-8 max-w-[260px] text-[12.5px]"
                    placeholder="Search branches"
                    value={search}
                    onChange={(event) => onSearch(event.target.value)}
                />
                <span className="text-xs text-tx3">
                    {selected.length} of {branches.length} selected
                </span>
                <div className="flex-1" />
                {!disabled && (
                    <>
                        <PgButton
                            variant="ghost"
                            onClick={() =>
                                onChange([
                                    ...new Set([
                                        ...selected,
                                        ...visible.map((b) => b.id),
                                    ]),
                                ])
                            }
                        >
                            Select shown
                        </PgButton>
                        <PgButton variant="ghost" onClick={() => onChange([])}>
                            Clear
                        </PgButton>
                    </>
                )}
            </div>
            {disabled && (
                <p className="text-xs text-tx3">
                    You don’t have permission to change the branch mapping.
                </p>
            )}
            <div className="max-h-[340px] overflow-y-auto rounded-lg border border-ln">
                {visible.map((branch) => (
                    <label
                        key={branch.id}
                        className="flex cursor-pointer items-center gap-3 border-b border-ln2 px-3 py-2 last:border-b-0 hover:bg-sf2"
                    >
                        <input
                            type="checkbox"
                            className="size-4 accent-ac"
                            disabled={disabled}
                            checked={selected.includes(branch.id)}
                            onChange={() => toggle(branch.id)}
                        />
                        <span className="font-mono text-xs text-tx3">
                            {branch.code}
                        </span>
                        <span className="flex-1 text-[13px]">
                            {branch.name}
                        </span>
                        <span className="text-xs text-tx3">
                            {branch.rates.deposit ?? '—'}% /{' '}
                            {branch.rates.withdrawal ?? '—'}%
                        </span>
                        <StatusBadge status={branch.status} />
                    </label>
                ))}
            </div>
        </div>
    );
}
