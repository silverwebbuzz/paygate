import { Head, useForm } from '@inertiajs/react';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { PageHeader } from '@/components/pg/page-header';
import { formatDateTime } from '@/lib/dates';
import admin from '@/routes/admin';

type Props = {
    settlement: { timezone: string; time: string; last_cutoff: string };
    timezones: string[];
};

/**
 * Global Settings. Phase 10: the settlement cut-off; the other platform
 * settings arrive with Phase 12.
 */
export default function GlobalSettings({ settlement, timezones }: Props) {
    const form = useForm({
        timezone: settlement.timezone,
        time: settlement.time,
    });

    return (
        <>
            <Head title="Global Settings" />
            <PageHeader
                title="Global Settings"
                description="Platform-wide settings. Every change is recorded in the audit log."
            />

            <Panel className="flex max-w-[640px] flex-col gap-4 p-5">
                <div>
                    <div className="text-sm font-semibold">Settlement day</div>
                    <p className="mt-1 text-[13px] text-tx2">
                        Each day’s settlements are calculated when the day ends.
                        A change applies from the next cut-off; settlements
                        already calculated don’t change.
                    </p>
                </div>
                <form
                    className="grid items-end gap-3 sm:grid-cols-[1fr_140px_auto]"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(admin.settings.settlement().url, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Field label="Timezone" error={form.errors.timezone}>
                        <SelectInput
                            value={form.data.timezone}
                            onChange={(event) =>
                                form.setData('timezone', event.target.value)
                            }
                        >
                            {timezones.map((zone) => (
                                <option key={zone} value={zone}>
                                    {zone}
                                </option>
                            ))}
                        </SelectInput>
                    </Field>
                    <Field label="Day ends at" error={form.errors.time}>
                        <TextInput
                            type="time"
                            required
                            value={form.data.time}
                            onChange={(event) =>
                                form.setData('time', event.target.value)
                            }
                        />
                    </Field>
                    <PgButton
                        type="submit"
                        variant="primary"
                        className="h-10"
                        disabled={form.processing || !form.isDirty}
                    >
                        Save
                    </PgButton>
                </form>
                <p className="text-xs text-tx3">
                    Last cut-off: {formatDateTime(settlement.last_cutoff)}{' '}
                    (India time).
                </p>
            </Panel>
        </>
    );
}
