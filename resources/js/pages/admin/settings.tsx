import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { PageHeader } from '@/components/pg/page-header';
import { formatDateTime } from '@/lib/dates';
import admin from '@/routes/admin';

type Reason = {
    id: string;
    context: 'payin_reject' | 'payout_reject';
    code: string;
    label: string;
    is_active: boolean;
    sort: number;
};

type Props = {
    settlement: { timezone: string; time: string; last_cutoff: string };
    timezones: string[];
    alert_settings: { deposit_wait_minutes: number };
    checkout: { email: string | null; phone: string | null };
    reasons: Reason[];
    can: { update: boolean };
};

/**
 * Global Settings (G-48): settlement day, alerts, the payment page's support
 * contact, and the reasons offered when declining a deposit or failing a
 * payout. Content pages have their own screen.
 */
export default function GlobalSettings(props: Props) {
    const { settlement, timezones, can } = props;
    const cutoff = useForm({
        timezone: settlement.timezone,
        time: settlement.time,
    });
    const alerts = useForm({
        deposit_wait_minutes: String(props.alert_settings.deposit_wait_minutes),
    });
    const checkout = useForm({
        email: props.checkout.email ?? '',
        phone: props.checkout.phone ?? '',
    });

    return (
        <>
            <Head title="Global Settings" />
            <PageHeader
                title="Global Settings"
                description="Platform-wide settings. Every change is recorded in the audit log."
                actions={
                    <Link
                        href={admin.pages.index().url}
                        className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                    >
                        <FileText className="size-3.5" /> Content pages
                    </Link>
                }
            />

            <div className="grid max-w-[1100px] gap-3 lg:grid-cols-2">
                <Section
                    title="Settlement day"
                    text="Each day's settlements are calculated when the day ends. A change applies from the next cut-off."
                >
                    <form
                        className="grid items-end gap-3 sm:grid-cols-[1fr_130px_auto]"
                        onSubmit={(event) => {
                            event.preventDefault();
                            cutoff.put(admin.settings.settlement().url, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <Field label="Timezone" error={cutoff.errors.timezone}>
                            <SelectInput
                                disabled={!can.update}
                                value={cutoff.data.timezone}
                                onChange={(event) =>
                                    cutoff.setData(
                                        'timezone',
                                        event.target.value,
                                    )
                                }
                            >
                                {timezones.map((zone) => (
                                    <option key={zone} value={zone}>
                                        {zone}
                                    </option>
                                ))}
                            </SelectInput>
                        </Field>
                        <Field label="Day ends at" error={cutoff.errors.time}>
                            <TextInput
                                type="time"
                                required
                                disabled={!can.update}
                                value={cutoff.data.time}
                                onChange={(event) =>
                                    cutoff.setData('time', event.target.value)
                                }
                            />
                        </Field>
                        <Save form={cutoff} can={can.update} />
                    </form>
                    <p className="text-xs text-tx3">
                        Last cut-off: {formatDateTime(settlement.last_cutoff)}{' '}
                        (India time).
                    </p>
                </Section>

                <Section
                    title="Alerts"
                    text="Branches are alerted (bell and email) when a customer's deposit has waited this long for approval."
                >
                    <form
                        className="grid items-end gap-3 sm:grid-cols-[1fr_auto]"
                        onSubmit={(event) => {
                            event.preventDefault();
                            alerts.put(admin.settings.alerts().url, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <Field
                            label="Deposit waiting alert after (minutes)"
                            hint="5 to 1,440 minutes."
                            error={alerts.errors.deposit_wait_minutes}
                        >
                            <TextInput
                                type="number"
                                min={5}
                                max={1440}
                                disabled={!can.update}
                                value={alerts.data.deposit_wait_minutes}
                                onChange={(event) =>
                                    alerts.setData(
                                        'deposit_wait_minutes',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Save form={alerts} can={can.update} />
                    </form>
                </Section>

                <Section
                    title="Payment page support"
                    text="Shown to customers on the payment page, so they know whom to ask. Leave empty to hide."
                >
                    <form
                        className="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]"
                        onSubmit={(event) => {
                            event.preventDefault();
                            checkout.put(admin.settings.checkout().url, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <Field label="Email" error={checkout.errors.email}>
                            <TextInput
                                type="email"
                                disabled={!can.update}
                                value={checkout.data.email}
                                onChange={(event) =>
                                    checkout.setData(
                                        'email',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field label="Phone" error={checkout.errors.phone}>
                            <TextInput
                                disabled={!can.update}
                                value={checkout.data.phone}
                                onChange={(event) =>
                                    checkout.setData(
                                        'phone',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Save form={checkout} can={can.update} />
                    </form>
                </Section>

                <Section
                    title="Reasons"
                    text="Offered when a branch declines a deposit or can't pay a payout; partners receive the code. Codes never change once created; switch a reason off instead."
                >
                    <Reasons
                        title="Declining a deposit"
                        context="payin_reject"
                        reasons={props.reasons.filter(
                            (reason) => reason.context === 'payin_reject',
                        )}
                        can={can.update}
                    />
                    <Reasons
                        title="Failing a payout"
                        context="payout_reject"
                        reasons={props.reasons.filter(
                            (reason) => reason.context === 'payout_reject',
                        )}
                        can={can.update}
                    />
                </Section>
            </div>
        </>
    );
}

function Section({
    title,
    text,
    children,
}: {
    title: string;
    text: string;
    children: ReactNode;
}) {
    return (
        <Panel className="flex h-fit flex-col gap-3 p-5">
            <div>
                <div className="text-sm font-semibold">{title}</div>
                <p className="mt-1 text-[13px] text-tx2">{text}</p>
            </div>
            {children}
        </Panel>
    );
}

function Save({
    form,
    can,
}: {
    form: { processing: boolean; isDirty: boolean };
    can: boolean;
}) {
    return can ? (
        <PgButton
            type="submit"
            variant="primary"
            className="h-10"
            disabled={form.processing || !form.isDirty}
        >
            Save
        </PgButton>
    ) : null;
}

function Reasons({
    title,
    context,
    reasons,
    can,
}: {
    title: string;
    context: Reason['context'];
    reasons: Reason[];
    can: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ context, code: '', label: '', is_active: true });
    const errors = form.errors as Record<string, string | undefined>;

    const save = (reason: Reason, changes: Partial<Reason>) =>
        router.put(
            admin.settings.reasons.update(reason.id).url,
            {
                label: changes.label ?? reason.label,
                is_active: changes.is_active ?? reason.is_active,
                sort: reason.sort,
            },
            { preserveScroll: true },
        );

    return (
        <div className="flex flex-col gap-1.5">
            <div className="text-xs font-semibold tracking-[.04em] text-tx3 uppercase">
                {title}
            </div>
            {reasons.map((reason) => (
                <div
                    key={reason.id}
                    className="flex items-center gap-2 rounded-lg border border-ln2 px-2.5 py-1.5 text-[13px]"
                >
                    <span className="w-40 truncate font-mono text-xs text-tx3">
                        {reason.code}
                    </span>
                    <input
                        defaultValue={reason.label}
                        disabled={!can}
                        onBlur={(event) =>
                            event.target.value !== reason.label &&
                            event.target.value.trim() &&
                            save(reason, { label: event.target.value })
                        }
                        className="min-w-0 flex-1 bg-transparent outline-none"
                    />
                    <label className="flex items-center gap-1.5 text-xs text-tx2">
                        <input
                            type="checkbox"
                            disabled={!can || reason.code === 'other'}
                            checked={reason.is_active}
                            onChange={(event) =>
                                save(reason, {
                                    is_active: event.target.checked,
                                })
                            }
                        />
                        Offered
                    </label>
                </div>
            ))}
            {can &&
                (adding ? (
                    <form
                        className="flex items-start gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(admin.settings.reasons.store().url, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset();
                                    setAdding(false);
                                },
                            });
                        }}
                    >
                        <Field label="Code" error={errors.code}>
                            <TextInput
                                className="h-8 font-mono text-xs"
                                placeholder="e.g. customer_cancelled"
                                value={form.data.code}
                                onChange={(event) =>
                                    form.setData('code', event.target.value)
                                }
                            />
                        </Field>
                        <Field label="Label" error={errors.label}>
                            <TextInput
                                className="h-8 text-[13px]"
                                value={form.data.label}
                                onChange={(event) =>
                                    form.setData('label', event.target.value)
                                }
                            />
                        </Field>
                        <PgButton
                            type="submit"
                            variant="primary"
                            className="mt-[22px]"
                            disabled={form.processing}
                        >
                            Add
                        </PgButton>
                    </form>
                ) : (
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="self-start text-xs font-medium text-ac"
                    >
                        + Add a reason
                    </button>
                ))}
        </div>
    );
}
