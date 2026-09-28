import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PortalSidebar } from '@/components/pg/portal-sidebar';
import { PortalTopbar } from '@/components/pg/portal-topbar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useDensity } from '@/hooks/use-density';
import { PORTAL_LABELS, PORTAL_NAV } from '@/lib/portal-nav';
import type { NavGroup } from '@/lib/portal-nav';
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
        environment,
        superAdmin,
        qaChecklist,
        rolloutHidden,
        rolloutLimited,
    } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();
    const [collapsed, setCollapsed] = useState(!sidebarOpen);
    const [density, toggleDensity] = useDensity();

    const portal = auth.user.type;
    // Super-admin tools at the end of the Admin menu (the QA Checklist on
    // local and staging only).
    const extras: NavGroup[] = [
        ...(portal === 'admin' && superAdmin
            ? [
                  {
                      label: 'Testing',
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
            : []),
        ...(portal === 'admin' && superAdmin && environment === 'local'
            ? [
                  {
                      label: 'Developer',
                      items: [{ label: 'UI kit', href: '/admin/ui-kit' }],
                  },
              ]
            : []),
    ];
    const groups = [...PORTAL_NAV[portal], ...extras]
        .map((group) => ({
            ...group,
            items: group.items.filter(
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
                item,
                url: toUrl(item.href!),
            })),
    );
    const current =
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

    const toggleSidebar = () => {
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
            <PortalSidebar
                groups={groups}
                activeKey={
                    current ? `${current.group}/${current.item.label}` : null
                }
                portalLabel={PORTAL_LABELS[portal]}
                collapsed={collapsed}
            />
            <main className="flex min-w-0 flex-1 flex-col">
                <PortalTopbar
                    portalLabel={PORTAL_LABELS[portal]}
                    crumbs={current ? [current.group, current.item.label] : []}
                    onToggleSidebar={toggleSidebar}
                    density={density}
                    onToggleDensity={toggleDensity}
                />
                <div className="flex flex-1 flex-col gap-4 p-5">{children}</div>
            </main>
        </div>
    );
}
