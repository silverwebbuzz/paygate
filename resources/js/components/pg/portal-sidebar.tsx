import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { NavGroup } from '@/lib/portal-nav';
import { logout } from '@/routes';

/**
 * Plum navigation sidebar (design: 260px expanded, 64px collapsed), 14px
 * menu text with an icon per group. Planned items render dimmed with the
 * phase that delivers them.
 *
 * Each group folds open and closed from its heading. Only one group starts
 * open: the group of the current page, or else the first group. The
 * icon-only sidebar shows one icon per group.
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
    const activeGroup = activeKey?.split('/')[0] ?? null;
    const [openGroups, setOpenGroups] = useState<string[]>(() => {
        const foldable = groups.filter((group) => group.items.length > 1);

        return foldable.some((group) => group.label === activeGroup)
            ? []
            : foldable.slice(0, 1).map((group) => group.label);
    });
    const isOpen = (label: string) =>
        label === activeGroup || openGroups.includes(label);
    const toggleGroup = (label: string) => {
        const next = isOpen(label)
            ? openGroups.filter((group) => group !== label)
            : [...openGroups, label];
        setOpenGroups(next);
    };

    return (
        <aside
            className={cn(
                'sticky top-0 z-10 flex h-screen flex-none flex-col overflow-hidden bg-nav text-white transition-[width] duration-200',
                collapsed ? 'w-16' : 'w-[260px]',
            )}
        >
            <div className="flex h-14 flex-none items-center gap-2.5 border-b border-white/[.08] px-[17px]">
                <div className="grid size-[30px] flex-none place-items-center rounded-lg bg-linear-135 from-ac to-ac2 text-sm font-bold text-white">
                    P
                </div>
                {!collapsed && (
                    <div className="flex min-w-0 flex-col leading-tight">
                        <span className="text-[15px] font-semibold tracking-tight text-white">
                            PayGate
                        </span>
                        <span className="text-[11.5px] whitespace-nowrap text-white/55">
                            {portalLabel} console
                        </span>
                    </div>
                )}
            </div>

            <nav className="flex flex-1 flex-col gap-1 overflow-y-auto px-3 pt-3 pb-4 text-[14px]">
                {groups.map((group) => {
                    const Icon = group.icon;

                    // One-item groups (Dashboard, Profile & settings…) are a
                    // single link; collapsed, every group is its icon.
                    if (group.items.length === 1 || collapsed) {
                        const item =
                            group.items.find(
                                (link) =>
                                    `${group.label}/${link.label}` ===
                                    activeKey,
                            ) ??
                            group.items.find((link) => link.href) ??
                            group.items[0];
                        const active = group.label === activeGroup;
                        const label =
                            group.items.length === 1 ? item.label : group.label;
                        const classes = cn(
                            'flex h-10 w-full flex-none items-center gap-3 rounded-[9px] px-3 font-medium',
                            active
                                ? 'bg-brand text-white'
                                : item.href
                                  ? 'text-white hover:bg-white/[.06]'
                                  : 'cursor-default text-white/40',
                        );
                        const content = (
                            <>
                                <Icon className="size-[18px] flex-none" />
                                {!collapsed && (
                                    <span className="flex-1 truncate">
                                        {label}
                                    </span>
                                )}
                            </>
                        );

                        return item.href ? (
                            <Link
                                key={group.label}
                                href={item.href}
                                prefetch
                                className={classes}
                                title={label}
                            >
                                {content}
                            </Link>
                        ) : (
                            <span
                                key={group.label}
                                className={classes}
                                title={label}
                            >
                                {content}
                            </span>
                        );
                    }

                    const open = isOpen(group.label);

                    return (
                        <div key={group.label} className="flex flex-col">
                            <button
                                type="button"
                                onClick={() => toggleGroup(group.label)}
                                aria-expanded={open}
                                disabled={group.label === activeGroup}
                                className={cn(
                                    'flex h-10 w-full items-center gap-3 rounded-[9px] px-3 text-left font-medium',
                                    group.label === activeGroup
                                        ? 'text-white'
                                        : 'text-white hover:bg-white/[.06]',
                                )}
                            >
                                <Icon className="size-[18px] flex-none" />
                                <span className="flex-1 truncate">
                                    {group.label}
                                </span>
                                {group.label !== activeGroup && (
                                    <ChevronDown
                                        className={cn(
                                            'size-4 flex-none text-white transition-transform',
                                            !open && '-rotate-90',
                                        )}
                                    />
                                )}
                            </button>
                            {open && (
                                <div className="flex flex-col gap-0.5 py-1">
                                    {group.items.map((item) => {
                                        const active =
                                            `${group.label}/${item.label}` ===
                                            activeKey;
                                        const content = (
                                            <>
                                                <span
                                                    className={cn(
                                                        'size-1.5 flex-none rounded-[2px]',
                                                        active
                                                            ? 'bg-white'
                                                            : item.href
                                                              ? 'bg-white/35'
                                                              : 'bg-white/15',
                                                    )}
                                                />
                                                <span className="flex-1 truncate">
                                                    {item.label}
                                                </span>
                                                {item.soon !== undefined && (
                                                    <span className="rounded px-1.5 py-px text-[10.5px] font-medium text-white/45 ring-1 ring-white/10">
                                                        {item.soon === 'later'
                                                            ? 'Later'
                                                            : `P${item.soon}`}
                                                    </span>
                                                )}
                                            </>
                                        );
                                        const classes = cn(
                                            'flex h-9 w-full items-center gap-3 rounded-[8px] pr-3 pl-[42px] text-left',
                                            active
                                                ? 'bg-brand font-semibold text-white'
                                                : item.href
                                                  ? 'text-[#DDD3EC] hover:bg-white/[.06] hover:text-white'
                                                  : 'cursor-default text-white/40',
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
                                                title={
                                                    item.soon === 'later'
                                                        ? `${item.label}: waiting for a client decision`
                                                        : `${item.label}: coming in Phase ${item.soon}`
                                                }
                                            >
                                                {content}
                                            </span>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    );
                })}
            </nav>

            <div className="flex flex-none items-center gap-2.5 border-t border-white/[.08] px-3.5 py-3">
                <div className="grid size-[30px] flex-none place-items-center rounded-full bg-white/10 text-[11.5px] font-semibold text-white">
                    {initials(auth.user.name)}
                </div>
                {!collapsed && (
                    <>
                        <div className="min-w-0 leading-tight">
                            <div className="truncate text-[12.5px] font-medium text-white">
                                {auth.user.name}
                            </div>
                            <div className="truncate text-[11.5px] text-white/55">
                                {auth.role?.name}
                            </div>
                        </div>
                        <span className="flex-1" />
                        <Link
                            href={logout()}
                            as="button"
                            className="h-[26px] flex-none rounded-md border border-white/15 px-2 text-[11.5px] text-white/80 hover:bg-white/[.06]"
                        >
                            Sign out
                        </Link>
                    </>
                )}
            </div>
        </aside>
    );
}
