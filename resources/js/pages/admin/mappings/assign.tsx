import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { PageHeader } from '@/components/pg/page-header';
import { OrgPicker } from '@/components/pg/org-picker';
import type { PickerItem } from '@/components/pg/org-picker';
import { StatusBadge } from '@/components/pg/status-badge';
import { cn } from '@/lib/utils';
import mappingsRoutes from '@/routes/admin/mappings';

type Owner = {
    id: string;
    code: string;
    name: string;
    status: string;
    assigned: number;
};

type Props = {
    side: 'partner' | 'branch';
    owners: Owner[];
    selected: string | null;
    items: PickerItem[];
    selected_ids: string[];
    can: { update: boolean };
};

const COPY = {
    partner: {
        title: 'Partner → branches',
        description:
            'Pick a partner, then tick every branch that should serve it. Unticked branches are unassigned. A branch can still be ticked for other partners.',
        other: 'Branch → partners',
        otherHref: () => mappingsRoutes.byBranch().url,
        emptyOwners: 'No partners yet. Create one under Partners.',
        emptyItems: 'No branches exist yet. Create one under Branches.',
        noun: 'branch',
        save: 'Save branches',
    },
    branch: {
        title: 'Branch → partners',
        description:
            'Pick a branch, then tick every partner it should serve. Unticked partners are unassigned. A partner can still be ticked for other branches.',
        other: 'Partner → branches',
        otherHref: () => mappingsRoutes.byPartner().url,
        emptyOwners: 'No branches yet. Create one under Branches.',
        emptyItems: 'No partners exist yet. Create one under Partners.',
        noun: 'partner',
        save: 'Save partners',
    },
} as const;

export default function AssignMappings({
    side,
    owners,
    selected,
    items,
    selected_ids,
    can,
}: Props) {
    const copy = COPY[side];
    const current = owners.find((owner) => owner.id === selected) ?? null;

    const open = (id: string) =>
        router.get(
            side === 'partner'
                ? mappingsRoutes.byPartner({ query: { partner: id } }).url
                : mappingsRoutes.byBranch({ query: { branch: id } }).url,
            {},
            { preserveState: false },
        );

    return (
        <>
            <Head title={copy.title} />
            <PageHeader
                title={copy.title}
                description={copy.description}
                actions={
                    <Link
                        href={copy.otherHref()}
                        className="inline-flex h-8 items-center rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                    >
                        {copy.other}
                    </Link>
                }
            />

            {owners.length === 0 ? (
                <EmptyState title={copy.emptyOwners} />
            ) : (
                <div className="grid items-start gap-4 lg:grid-cols-[300px_1fr]">
                    <Panel className="max-h-[70vh] overflow-y-auto">
                        {owners.map((owner) => (
                            <button
                                key={owner.id}
                                type="button"
                                onClick={() => open(owner.id)}
                                className={cn(
                                    'flex w-full items-center gap-2 border-b border-ln2 px-3 py-2.5 text-left last:border-b-0 hover:bg-sf2',
                                    owner.id === selected && 'bg-sf2',
                                )}
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block font-mono text-xs text-tx3">
                                        {owner.code}
                                    </span>
                                    <span className="block truncate text-[13px]">
                                        {owner.name}
                                    </span>
                                </span>
                                <span className="text-xs whitespace-nowrap text-tx3">
                                    {owner.assigned} ticked
                                </span>
                                <StatusBadge status={owner.status} />
                            </button>
                        ))}
                    </Panel>

                    {current && (
                        <AssignmentForm
                            key={current.id}
                            side={side}
                            owner={current}
                            items={items}
                            selectedIds={selected_ids}
                            canUpdate={can.update}
                            emptyItems={copy.emptyItems}
                            noun={copy.noun}
                            saveLabel={copy.save}
                        />
                    )}
                </div>
            )}
        </>
    );
}

function AssignmentForm({
    side,
    owner,
    items,
    selectedIds,
    canUpdate,
    emptyItems,
    noun,
    saveLabel,
}: {
    side: 'partner' | 'branch';
    owner: Owner;
    items: PickerItem[];
    selectedIds: string[];
    canUpdate: boolean;
    emptyItems: string;
    noun: string;
    saveLabel: string;
}) {
    const [search, setSearch] = useState('');
    const form = useForm({ ids: selectedIds });

    return (
        <Panel className="p-4">
            <div className="mb-3">
                <h2 className="text-[15px] font-semibold">
                    {owner.code} · {owner.name}
                </h2>
                <p className="text-xs text-tx3">
                    Tick the {noun === 'branch' ? 'branches' : 'partners'} to
                    assign. Rates shown are deposit / withdrawal.
                </p>
            </div>
            <OrgPicker
                noun={noun}
                emptyHint={emptyItems}
                items={items}
                selected={form.data.ids}
                search={search}
                onSearch={setSearch}
                disabled={!canUpdate}
                onChange={(next) => form.setData('ids', next)}
            />
            {canUpdate && items.length > 0 && (
                <div className="mt-4 flex items-center gap-3">
                    <PgButton
                        variant="primary"
                        disabled={form.processing}
                        onClick={() => {
                            form.transform((data) =>
                                side === 'partner'
                                    ? {
                                          partner_id: owner.id,
                                          branch_ids: data.ids,
                                      }
                                    : {
                                          branch_id: owner.id,
                                          partner_ids: data.ids,
                                      },
                            );
                            form.put(
                                side === 'partner'
                                    ? mappingsRoutes.byPartner.update().url
                                    : mappingsRoutes.byBranch.update().url,
                                { preserveScroll: true },
                            );
                        }}
                    >
                        {saveLabel}
                    </PgButton>
                    <span className="text-xs text-tx3">
                        {form.data.ids.length} selected
                    </span>
                </div>
            )}
        </Panel>
    );
}
