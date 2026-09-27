import { Head, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type OverviewCard = {
    title: string;
    description: string;
    icon: LucideIcon;
};

/**
 * Landing page shared by the three portals until their real dashboards exist.
 */
export function PortalOverview({
    title,
    cards,
}: {
    title: string;
    cards: OverviewCard[];
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">{title}</h1>
                    <p className="text-sm text-muted-foreground">
                        Signed in as {auth.user.name} ({auth.role?.name})
                    </p>
                </div>
                <div className="grid gap-4 md:grid-cols-3">
                    {cards.map(({ title, description, icon: Icon }) => (
                        <div
                            key={title}
                            className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        >
                            <Icon className="mb-3 size-5 text-muted-foreground" />
                            <h2 className="font-medium">{title}</h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
