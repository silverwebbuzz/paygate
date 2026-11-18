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
 * (each an existing page, with its waiting count) and, on Deposit › Manual
 * Entry only, the bank entry bar. The page below it is unchanged.
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
            {/* Same height on every tab, so the tabs don't jump when
                switching: the Live line keeps its space and the
                description stays on one line. */}
            <PageHeader
                title={title}
                description={description}
                eyebrow={eyebrow ?? <div aria-hidden className="h-4" />}
                actions={actions}
                oneLine
            />

            {views.length > 1 && (
                // Button-like tabs on a tinted strip (client request
                // 2026-11-18: the plain underline didn't read as tabs).
                <nav
                    aria-label={`${title} sections`}
                    className="flex items-center gap-1.5 overflow-x-auto rounded-xl border border-ln bg-sf2 p-1.5"
                >
                    {views.map((item) => {
                        const count = hub.counts[item.key];
                        const active = item.key === view;

                        return (
                            <Link
                                key={item.key}
                                href={item.href}
                                preserveScroll={false}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'flex h-9 items-center gap-1.5 rounded-lg border px-3.5 text-[13px] font-medium whitespace-nowrap transition-colors',
                                    active
                                        ? 'border-transparent bg-brand text-white shadow-[0_2px_8px_rgba(15,23,42,.18)]'
                                        : 'border-ln bg-sf text-tx2 shadow-[0_1px_2px_rgba(15,23,42,.06)] hover:border-ac/50 hover:text-tx',
                                )}
                            >
                                {item.label}
                                {count !== undefined && (
                                    <span
                                        className={cn(
                                            'min-w-5 rounded-[5px] px-1.5 py-px text-center text-[11px]',
                                            active
                                                ? 'bg-white/25 font-semibold text-white'
                                                : count > 0
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
