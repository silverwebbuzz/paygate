import { Link, router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatRelative } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { read, readAll } from '@/routes/notifications';
import { edit as notificationSettings } from '@/routes/notifications';

/**
 * The top bar's bell (G-47): unread count, the latest alerts, mark as read.
 * Opening an alert marks it read and follows its link.
 */
export function AlertBell({ className }: { className: string }) {
    const { alerts } = usePage().props;

    if (!alerts) return null;

    const open = (id: string, url: string | null) =>
        router.post(
            read(id).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => url && router.visit(url),
            },
        );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    title="Notifications"
                    className={cn(className, 'relative')}
                >
                    <Bell className="size-3.5" />
                    {alerts.unread > 0 && (
                        <span className="absolute -top-1 -right-1 grid h-4 min-w-4 place-items-center rounded-full bg-er px-1 text-[10px] font-semibold text-white">
                            {alerts.unread > 9 ? '9+' : alerts.unread}
                        </span>
                    )}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[360px] p-0">
                <div className="flex items-center justify-between border-b border-ln2 px-3 py-2.5">
                    <span className="text-sm font-semibold">Notifications</span>
                    {alerts.unread > 0 && (
                        <button
                            type="button"
                            onClick={() =>
                                router.post(
                                    readAll().url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            className="text-xs font-medium text-ac"
                        >
                            Mark all read
                        </button>
                    )}
                </div>
                <div className="max-h-[380px] overflow-y-auto">
                    {alerts.latest.length === 0 && (
                        <p className="px-3 py-8 text-center text-xs text-tx3">
                            No notifications yet.
                        </p>
                    )}
                    {alerts.latest.map((alert) => (
                        <button
                            key={alert.id}
                            type="button"
                            onClick={() => open(alert.id, alert.url)}
                            className="flex w-full gap-2.5 border-b border-ln2 px-3 py-2.5 text-left last:border-0 hover:bg-sf2"
                        >
                            <span
                                className={cn(
                                    'mt-1.5 size-1.5 flex-none rounded-full',
                                    alert.read ? 'bg-transparent' : 'bg-ac',
                                )}
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block text-[13px] font-medium">
                                    {alert.title}
                                </span>
                                <span className="line-clamp-2 block text-xs text-tx2">
                                    {alert.body}
                                </span>
                                <span className="block text-[11px] text-tx3">
                                    {formatRelative(alert.at)}
                                </span>
                            </span>
                        </button>
                    ))}
                </div>
                <Link
                    href={notificationSettings().url}
                    className="block border-t border-ln2 px-3 py-2 text-center text-xs text-tx2 hover:bg-sf2"
                >
                    Email settings
                </Link>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
