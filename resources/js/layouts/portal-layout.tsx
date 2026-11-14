import { router, usePage } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PortalSidebar } from '@/components/pg/portal-sidebar';
import { PortalTopbar } from '@/components/pg/portal-topbar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useDensity } from '@/hooks/use-density';
import { useIsMobile } from '@/hooks/use-mobile';
import { visibleHubViews } from '@/lib/payment-hub';
import type { HubProps } from '@/lib/payment-hub';
import { navGroupId, PORTAL_LABELS, PORTAL_NAV } from '@/lib/portal-nav';
import type { NavGroup, NavLink } from '@/lib/portal-nav';
import admin from '@/routes/admin';
import { toUrl } from '@/lib/utils';

/**
 * Shell for the admin, branch and partner portals (design: dark sidebar,
 * 56px top bar). The portal decides the accent colour via data-portal.
 */
export default function PortalLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const {
        auth,
        sidebarOpen,
        superAdmin,
        qaChecklist,
        rolloutHidden,
        rolloutLimited,
        hub: pageHub,
    } = usePage<{ hub?: HubProps | null }>().props;
    const { isCurrentUrl } = useCurrentUrl();
    const [collapsed, setCollapsed] = useState(!sidebarOpen);
    const [density, toggleDensity] = useDensity();
    // Phones: the menu is a slide-in panel, closed until the menu button is
    // pressed and closed again after each page change.
    const isMobile = useIsMobile();
    const [menuOpen, setMenuOpen] = useState(false);

    useEffect(() => router.on('navigate', () => setMenuOpen(false)), []);

    const portal = auth.user.type;
    // Super-admin tools at the end of the Admin menu.
    const extras: NavGroup[] =
        portal === 'admin' && superAdmin
            ? [
                  {
                      label: 'Testing',
                      icon: FlaskConical,
                      items: [
                          ...(qaChecklist
                              ? [
                                    {
                                        label: 'QA Checklist',
                                        href: admin.qaChecklist.index(),
                                    },
                                ]
                              : []),
                          {
                              label: 'Section rollout',
                              href: admin.sectionRollout.index(),
                          },
                      ],
                  },
              ]
            : [];
    // Super admins also get the UI kit under Developer.
    const nav = PORTAL_NAV[portal].map((group) =>
        portal === 'admin' && superAdmin && group.label === 'Developer'
            ? {
                  ...group,
                  items: [
                      ...group.items,
                      { label: 'UI kit', href: admin.uiKit() },
                  ],
              }
            : group,
    );
    // Payments › Deposit / Withdrawal: one item for a page of tabs. It shows
    // while any tab is open to this person and links to the first one.
    const resolveHub = (item: NavLink): NavLink[] => {
        if (!item.hub) return [item];
        const views = visibleHubViews(
            portal,
            item.hub,
            auth.permissions,
            rolloutHidden,
        );

        return views.length > 0 ? [{ ...item, href: views[0].href }] : [];
    };
    const groups = [...nav, ...extras]
        .map((group) => ({
            ...group,
            items: group.items.flatMap(resolveHub).filter(
                (item) =>
                    (!item.permission ||
                        auth.permissions.includes(item.permission)) &&
                    // Section rollout: sections not open yet (and planned
                    // items) are hidden.
                    !(rolloutLimited && !item.href) &&
                    !(
                        item.href &&
                        rolloutHidden.includes(
                            toUrl(item.href).replace(
                                /^(https?:)?\/\/[^/]+/,
                                '',
                            ),
                        )
                    ),
            ),
        }))
        .filter((group) => group.items.length > 0);
    // The menu item for this page: an exact match, otherwise the item whose
    // URL is the longest parent of this one (e.g. /admin/partners/create
    // belongs to Partners).
    const links = groups.flatMap((group) =>
        group.items
            .filter((item) => item.href !== undefined)
            .map((item) => ({
                group: group.label,
                groupId: navGroupId(group),
                item,
                url: toUrl(item.href!),
            })),
    );
    const current =
        // A Deposit / Withdrawal tab marks its menu item (the UTR
        // Reconciliation page is a tab of both, by direction).
        (pageHub
            ? links.find(({ item }) => item.hub === pageHub.kind)
            : undefined) ??
        links.find(({ item }) => isCurrentUrl(item.href!)) ??
        links
            .filter(({ url }) =>
                isCurrentUrl(
                    `${url.replace(/^(https?:)?\/\/[^/]+/, '')}/`,
                    undefined,
                    true,
                ),
            )
            .sort((a, b) => b.url.length - a.url.length)[0];

    // Dialogs and drawers render in a portal at the end of <body>, outside
    // this layout; put the portal accent and density on <html> as well so
    // they match the page.
    useEffect(() => {
        document.documentElement.dataset.portal = portal;
        document.documentElement.dataset.density = density;
    }, [portal, density]);

    const activeKey = current
        ? `${current.groupId}/${current.item.label}`
        : null;

    const toggleSidebar = () => {
        if (isMobile) {
            setMenuOpen(!menuOpen);

            return;
        }

        const next = !collapsed;
        setCollapsed(next);
        document.cookie = `sidebar_state=${!next};path=/;max-age=${60 * 60 * 24 * 365};SameSite=Lax`;
    };

    return (
        <div
            data-portal={portal}
            data-density={density}
            className="flex min-h-screen bg-bg text-tx"
        >
            {isMobile ? (
                menuOpen && (
                    <div className="fixed inset-0 z-40 flex">
                        <PortalSidebar
                            groups={groups}
                            activeKey={activeKey}
                            portalLabel={PORTAL_LABELS[portal]}
                            collapsed={false}
                        />
                        <button
                            type="button"
                            aria-label="Close menu"
                            onClick={() => setMenuOpen(false)}
                            className="flex-1 bg-black/40"
                        />
                    </div>
                )
            ) : (
                <PortalSidebar
                    groups={groups}
                    activeKey={activeKey}
                    portalLabel={PORTAL_LABELS[portal]}
                    collapsed={collapsed}
                />
            )}
            <main className="flex min-w-0 flex-1 flex-col">
                <PortalTopbar
                    portalLabel={PORTAL_LABELS[portal]}
                    crumbs={
                        current
                            ? current.group === current.item.label
                                ? [current.item.label]
                                : [current.group, current.item.label]
                            : []
                    }
                    onToggleSidebar={toggleSidebar}
                    density={density}
                    onToggleDensity={toggleDensity}
                />
                <div className="flex flex-1 flex-col gap-4 p-3 md:p-5">
                    {children}
                </div>
            </main>
        </div>
    );
}
