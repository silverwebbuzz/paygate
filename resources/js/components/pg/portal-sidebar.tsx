import { Link, usePage } from '@inertiajs/react';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { NavGroup } from '@/lib/portal-nav';
import { logout } from '@/routes';

/**
 * Dark navigation sidebar (design: 244px expanded, 64px collapsed).
 * Planned items render dimmed with the phase that delivers them.
 */
export function PortalSidebar({
    groups,
    activeKey,
    portalLabel,
    collapsed,
}: {
    groups: NavGroup[];
    activeKey: string | null;
    portalLabel: string;
    collapsed: boolean;
}) {
    const { auth } = usePage().props;
    const initials = useInitials();

    return (
        <aside
            className={cn(
                'sticky top-0 z-10 flex h-screen flex-none flex-col overflow-hidden bg-nav text-[#C8D1DF] transition-[width] duration-200',
                collapsed ? 'w-16' : 'w-[244px]',
            )}
        >
            <div className="flex h-14 flex-none items-center gap-2.5 border-b border-white/5 px-[18px]">
                <div className="grid size-7 flex-none place-items-center rounded-lg bg-ac text-sm font-bold text-white">
                    P
                </div>
                {!collapsed && (
                    <div className="flex min-w-0 flex-col leading-tight">
                        <span className="text-sm font-semibold tracking-tight text-white">
                            PayGate
                        </span>
                        <span className="text-[11px] whitespace-nowrap text-[#8391A8]">
                            {portalLabel} console
                        </span>
                    </div>
                )}
            </div>

            <nav className="flex-1 overflow-y-auto px-2.5 pt-2 pb-4">
                {groups.map((group) => (
                    <div key={group.label} className="mt-3">
                        {!collapsed && (
                            <div className="px-2.5 pb-1.5 text-[10.5px] font-semibold tracking-[.06em] text-[#6B778C] uppercase">
                                {group.label}
                            </div>
                        )}
                        {group.items.map((item) => {
                            const active =
                                `${group.label}/${item.label}` === activeKey;
                            const content = (
                                <>
                                    <span
                                        className={cn(
                                            'size-1.5 flex-none rounded-[2px]',
                                            active
                                                ? 'bg-ac'
                                                : item.href
                                                  ? 'bg-white/30'
                                                  : 'bg-white/10',
                                        )}
                                    />
                                    {!collapsed && (
                                        <>
                                            <span className="flex-1 truncate">
                                                {item.label}
                                            </span>
                                            {item.soon !== undefined && (
                                                <span className="rounded px-1.5 py-px text-[10.5px] font-medium text-[#6B778C] ring-1 ring-white/10">
                                                    P{item.soon}
                                                </span>
                                            )}
                                        </>
                                    )}
                                </>
                            );
                            const classes = cn(
                                'flex h-8 w-full items-center gap-2.5 rounded-[7px] px-2.5 text-left text-[13px]',
                                active
                                    ? 'bg-white/8 font-semibold text-white'
                                    : item.href
                                      ? 'font-medium text-[#C8D1DF] hover:bg-white/5'
                                      : 'cursor-default font-medium text-[#7B879C]',
                            );

                            return item.href ? (
                                <Link
                                    key={item.label}
                                    href={item.href}
                                    prefetch
                                    className={classes}
                                    title={item.label}
                                >
                                    {content}
                                </Link>
                            ) : (
                                <span
                                    key={item.label}
                                    className={classes}
                                    title={`${item.label}: coming in Phase ${item.soon}`}
                                >
                                    {content}
                                </span>
                            );
                        })}
                    </div>
                ))}
            </nav>

            <div className="flex flex-none items-center gap-2.5 border-t border-white/5 px-3.5 py-3">
                <div className="grid size-[30px] flex-none place-items-center rounded-full bg-[#1E293B] text-[11.5px] font-semibold text-[#E2E8F0]">
                    {initials(auth.user.name)}
                </div>
                {!collapsed && (
                    <>
                        <div className="min-w-0 leading-tight">
                            <div className="truncate text-[12.5px] font-medium text-white">
                                {auth.user.name}
                            </div>
                            <div className="truncate text-[11.5px] text-[#8391A8]">
                                {auth.role?.name}
                            </div>
                        </div>
                        <span className="flex-1" />
                        <Link
                            href={logout()}
                            as="button"
                            className="h-[26px] flex-none rounded-md border border-white/10 px-2 text-[11.5px] text-[#C8D1DF] hover:bg-white/5"
                        >
                            Sign out
                        </Link>
                    </>
                )}
            </div>
        </aside>
    );
}
