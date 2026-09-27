import { Bell, Moon, PanelLeft, Rows3, Rows4, Search, Sun } from 'lucide-react';
import { usePage } from '@inertiajs/react';
import { useAppearance } from '@/hooks/use-appearance';
import type { Density } from '@/hooks/use-density';

export function PortalTopbar({
    portalLabel,
    crumbs,
    onToggleSidebar,
    density,
    onToggleDensity,
}: {
    portalLabel: string;
    crumbs: string[];
    onToggleSidebar: () => void;
    density: Density;
    onToggleDensity: () => void;
}) {
    const { environment } = usePage().props;
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const envLabel =
        environment === 'production'
            ? 'Production'
            : environment.charAt(0).toUpperCase() + environment.slice(1);
    const iconButton =
        'grid size-8 flex-none place-items-center rounded-lg border border-ln bg-sf text-tx2 hover:bg-sf2';

    return (
        <header className="sticky top-0 z-[4] flex h-14 flex-none items-center gap-3.5 border-b border-ln bg-sf px-5">
            <button
                type="button"
                onClick={onToggleSidebar}
                title="Collapse sidebar"
                className={iconButton}
            >
                <PanelLeft className="size-3.5" />
            </button>

            <div className="flex flex-none items-center gap-1.5 text-[13px] whitespace-nowrap">
                {[portalLabel, ...crumbs].map((crumb, index, all) => (
                    <span
                        key={`${crumb}-${index}`}
                        className="flex items-center gap-1.5"
                    >
                        {index > 0 && <span className="text-ln">/</span>}
                        <span
                            className={
                                index === all.length - 1
                                    ? 'font-medium text-tx'
                                    : 'text-tx3'
                            }
                        >
                            {crumb}
                        </span>
                    </span>
                ))}
            </div>

            <div
                className="ml-auto flex h-8 max-w-[340px] min-w-0 flex-[1_1_200px] items-center gap-2 rounded-lg border border-ln bg-sf2 px-2.5 text-tx3"
                title="Global search arrives with transactions (Phase 7)"
            >
                <Search className="size-3.5 flex-none" />
                <span className="flex-1 truncate text-[12.5px]">
                    Search txn ID, UTR, order ID, partner…
                </span>
                <kbd className="rounded border border-ln bg-sf px-1.5 font-mono text-[10.5px]">
                    ⌘K
                </kbd>
            </div>

            <span className="inline-flex h-[26px] items-center gap-1.5 rounded-full bg-acs px-2.5 text-xs font-medium whitespace-nowrap text-act">
                <span className="size-1.5 rounded-full bg-ac" />
                {portalLabel} · {envLabel}
            </span>

            <button
                type="button"
                onClick={onToggleDensity}
                title={
                    density === 'compact' ? 'Comfortable rows' : 'Compact rows'
                }
                className={iconButton}
            >
                {density === 'compact' ? (
                    <Rows4 className="size-3.5" />
                ) : (
                    <Rows3 className="size-3.5" />
                )}
            </button>
            <button
                type="button"
                onClick={() =>
                    updateAppearance(
                        resolvedAppearance === 'dark' ? 'light' : 'dark',
                    )
                }
                title="Toggle theme"
                className={iconButton}
            >
                {resolvedAppearance === 'dark' ? (
                    <Sun className="size-3.5" />
                ) : (
                    <Moon className="size-3.5" />
                )}
            </button>
            <button
                type="button"
                title="Notifications (Phase 12)"
                className={iconButton}
            >
                <Bell className="size-3.5" />
            </button>
        </header>
    );
}
