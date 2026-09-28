import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { PgButton } from '@/components/pg/button';
import { update } from '@/routes/notifications';
import { edit as editNotifications } from '@/routes/notifications';

type Props = { events: { key: string; label: string; email: boolean }[] };

/**
 * Which alerts also come by email (G-47). The bell always shows them.
 */
export default function NotificationSettings({ events: alerts }: Props) {
    const form = useForm({
        email: Object.fromEntries(
            alerts.map((alert) => [alert.key, alert.email]),
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
                    description="Every alert appears under the bell in the top bar. Choose which ones you also get by email."
                />
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(update().url, { preserveScroll: true });
                    }}
                >
                    {alerts.map((alert) => (
                        <label
                            key={alert.key}
                            className="flex items-center justify-between gap-4 rounded-lg border border-ln px-3 py-2.5 text-[13px]"
                        >
                            {alert.label}
                            <span className="flex items-center gap-2 text-xs text-tx2">
                                Email
                                <input
                                    type="checkbox"
                                    className="size-4 accent-[var(--pg-ac)]"
                                    checked={form.data.email[alert.key]}
                                    onChange={(event) =>
                                        form.setData('email', {
                                            ...form.data.email,
                                            [alert.key]: event.target.checked,
                                        })
                                    }
                                />
                            </span>
                        </label>
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
