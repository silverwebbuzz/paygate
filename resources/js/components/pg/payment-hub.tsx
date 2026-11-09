import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pg/page-header';
import { StatementEntryBar } from '@/components/pg/statement-entry';
import { HUB_TITLES, hubViews, visibleHubViews } from '@/lib/payment-hub';
import type { HubProps, HubView } from '@/lib/payment-hub';
import { cn } from '@/lib/utils';

/**
 * The top of every Payments › Deposit / Withdrawal tab: the title, the tabs
 * (each an existing page, with its waiting count) and, on Deposit, the bank
 * entry bar. The page below it is unchanged.
 *
 * `live`: the page already reloads `hub` with its own refresh; otherwise the
 * tab counts refresh here every 20 s, so new deposits show on every tab.
 */
export function PaymentHubHeader({
    hub,
    view,
    description,
    eyebrow,
    actions,
    live = false,
}: {
    hub: HubProps;
    view: HubView;
    description?: ReactNode;
    eyebrow?: ReactNode;
    actions?: ReactNode;
    live?: boolean;
}) {
    const { auth, rolloutHidden } = usePage().props;
    const portal = auth.user.type;
    const views = visibleHubViews(
        portal,
        hub.kind,
        auth.permissions,
        rolloutHidden,
    );
    const current =
        views.find((item) => item.key === view) ??
        hubViews(portal, hub.kind).find((item) => item.key === view);
    const title = HUB_TITLES[hub.kind];

    useEffect(() => {
        if (live) return;
        const timer = setInterval(() => {
            if (document.hidden) return;
            router.reload({ only: ['hub'] });
        }, 20000);

        return () => clearInterval(timer);
    }, [live]);

    return (
        <>
            <Head
                title={
                    views.length > 1 && current
                        ? `${title} · ${current.label}`
                        : title
                }
            />
            <PageHeader
                title={title}
                description={description}
                eyebrow={eyebrow}
                actions={actions}
            />

            {views.length > 1 && (
                <nav
                    aria-label={`${title} sections`}
                    className="flex items-center gap-0.5 overflow-x-auto rounded-[10px] border border-ln bg-sf px-2"
                >
                    {views.map((item) => {
                        const count = hub.counts[item.key];

                        return (
                            <Link
                                key={item.key}
                                href={item.href}
                                preserveScroll={false}
                                aria-current={
                                    item.key === view ? 'page' : undefined
                                }
                                className={cn(
                                    'flex h-[42px] items-center gap-1.5 border-b-2 px-3 text-[13px] font-medium whitespace-nowrap',
                                    item.key === view
                                        ? 'border-ac text-tx'
                                        : 'border-transparent text-tx2 hover:text-tx',
                                )}
                            >
                                {item.label}
                                {count !== undefined && (
                                    <span
                                        className={cn(
                                            'rounded-[5px] px-1.5 py-px text-[11px]',
                                            count > 0
                                                ? 'bg-wnb font-semibold text-wn'
                                                : 'bg-sf2 text-tx3',
                                        )}
                                    >
                                        {count}
                                    </span>
                                )}
                            </Link>
                        );
                    })}
                </nav>
            )}

            {hub.entry && (
                <StatementEntryBar
                    portal={portal}
                    accounts={hub.entry.accounts}
                    branches={hub.entry.branches}
                />
            )}
        </>
    );
}
