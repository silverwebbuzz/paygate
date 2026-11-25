import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { PgButton } from '@/components/pg/button';
import { SwitchField } from '@/components/pg/switch-field';
import { update } from '@/routes/notifications';
import { edit as editNotifications } from '@/routes/notifications';

type Props = { events: { key: string; label: string; enabled: boolean }[] };

/**
 * Which alerts show under the bell in the top bar (G-47). No email: the
 * system doesn't send any (client request 2026-11-25).
 */
export default function NotificationSettings({ events: alerts }: Props) {
    const form = useForm({
        enabled: Object.fromEntries(
            alerts.map((alert) => [alert.key, alert.enabled]),
        ) as Record<string, boolean>,
    });

    return (
        <>
            <Head title="Notification settings" />
            <h1 className="sr-only">Notification settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Notifications"
                    description="Alerts appear under the bell in the top bar. Turn off the ones you don't want to see."
                />
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(update().url, { preserveScroll: true });
                    }}
                >
                    {alerts.map((alert) => (
                        <SwitchField
                            key={alert.key}
                            label={alert.label}
                            checked={form.data.enabled[alert.key]}
                            onChange={(checked) =>
                                form.setData('enabled', {
                                    ...form.data.enabled,
                                    [alert.key]: checked,
                                })
                            }
                        />
                    ))}
                    {alerts.length === 0 && (
                        <p className="text-sm text-tx3">
                            There are no alerts for your portal.
                        </p>
                    )}
                    <div>
                        <PgButton
                            type="submit"
                            variant="primary"
                            disabled={form.processing || !form.isDirty}
                        >
                            Save
                        </PgButton>
                    </div>
                </form>
            </div>
        </>
    );
}

NotificationSettings.layout = {
    breadcrumbs: [
        { title: 'Notification settings', href: editNotifications() },
    ],
};
