import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PortalSidebar } from '@/components/pg/portal-sidebar';
import { PortalTopbar } from '@/components/pg/portal-topbar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useDensity } from '@/hooks/use-density';
import { PORTAL_LABELS, PORTAL_NAV } from '@/lib/portal-nav';

/**
 * Shell for the admin, branch and partner portals (design: dark sidebar,
 * 56px top bar). The portal decides the accent colour via data-portal.
 */
export default function PortalLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { auth, sidebarOpen, environment } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();
    const [collapsed, setCollapsed] = useState(!sidebarOpen);
    const [density, toggleDensity] = useDensity();

    const portal = auth.user.type;
    const groups = (
        portal === 'admin' && environment === 'local'
            ? [
                  ...PORTAL_NAV.admin,
                  {
                      label: 'Developer',
                      items: [{ label: 'UI kit', href: '/admin/ui-kit' }],
                  },
              ]
            : PORTAL_NAV[portal]
    )
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    !item.permission ||
                    auth.permissions.includes(item.permission),
            ),
        }))
        .filter((group) => group.items.length > 0);
    const current = groups
        .flatMap((group) =>
            group.items.map((item) => ({ group: group.label, item })),
        )
        .find(({ item }) => item.href !== undefined && isCurrentUrl(item.href));

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
