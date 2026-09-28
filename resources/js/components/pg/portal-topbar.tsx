import { Menu, Moon, PanelLeft, Rows3, Rows4, Search, Sun } from 'lucide-react';
import { usePage } from '@inertiajs/react';
import { useAppearance } from '@/hooks/use-appearance';
import type { Density } from '@/hooks/use-density';
import { AlertBell } from './alert-bell';

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
        <header className="sticky top-0 z-[4] flex h-14 flex-none items-center gap-2 border-b border-ln bg-sf px-3 md:gap-3.5 md:px-5">
            <button
                type="button"
                onClick={onToggleSidebar}
                title="Menu"
                className={iconButton}
            >
                <PanelLeft className="size-3.5 max-md:hidden" />
                <Menu className="size-4 md:hidden" />
            </button>

            {/* Phones show only the current page's name. */}
            <div className="flex min-w-0 flex-none items-center gap-1.5 text-[13px] whitespace-nowrap max-md:flex-1">
                {[portalLabel, ...crumbs].map((crumb, index, all) => (
                    <span
                        key={`${crumb}-${index}`}
                        className={
                            index === all.length - 1
                                ? 'flex min-w-0 items-center gap-1.5 truncate'
                                : 'flex items-center gap-1.5 max-md:hidden'
                        }
                    >
                        {index > 0 && (
                            <span className="text-ln max-md:hidden">/</span>
                        )}
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
                className="ml-auto flex h-8 max-w-[340px] min-w-0 flex-[1_1_200px] items-center gap-2 rounded-lg border border-ln bg-sf2 px-2.5 text-tx3 max-md:hidden"
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

            <span className="inline-flex h-[26px] items-center gap-1.5 rounded-full bg-acs px-2.5 text-xs font-medium whitespace-nowrap text-act max-md:hidden">
                <span className="size-1.5 rounded-full bg-ac" />
                {portalLabel} · {envLabel}
            </span>

            <button
                type="button"
                onClick={onToggleDensity}
                title={
                    density === 'compact' ? 'Comfortable rows' : 'Compact rows'
                }
                className={`${iconButton} max-md:hidden`}
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
            <AlertBell className={iconButton} />
        </header>
    );
}
