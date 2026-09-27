import { Head, usePage } from '@inertiajs/react';
import { EmptyState } from './empty-state';
import { Panel } from './data-table';
import { PageHeader } from './page-header';

/**
 * Dashboard placeholder until live metrics exist (payments: Phase 7,
 * dashboards: Phase 11). Shows what the portal will contain, never fake numbers.
 */
export function PortalDashboard({
    title,
    description,
    upcoming,
}: {
    title: string;
    description: string;
    upcoming: { phase: number; label: string }[];
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description={description}
                eyebrow={
                    <div className="text-xs text-tx3">
                        Signed in as {auth.user.name} · {auth.role?.name}
                    </div>
                }
            />
            <Panel>
                <EmptyState
                    title="No activity yet"
                    description="Live figures appear here once payments start flowing (Phase 7) and dashboards are built (Phase 11)."
                />
            </Panel>
            <Panel className="p-4">
                <div className="text-sm font-semibold">
                    Coming to this portal
                </div>
                <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {upcoming.map((item) => (
                        <li
                            key={item.label}
                            className="flex items-center gap-2.5 rounded-lg border border-ln2 bg-sf2 px-3 py-2 text-[13px]"
                        >
                            <span className="rounded-[5px] bg-acs px-1.5 py-px text-[11px] font-semibold text-act">
                                P{item.phase}
                            </span>
                            {item.label}
                        </li>
                    ))}
                </ul>
            </Panel>
        </>
    );
}
