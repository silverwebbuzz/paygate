import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
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
        owners: 'Partners',
        itemsTitle: 'Branches',
        ownerNoun: 'partner',
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
        owners: 'Branches',
        itemsTitle: 'Partners',
        ownerNoun: 'branch',
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
                        <div className="sticky top-0 z-[1] flex items-center justify-between border-b border-ln bg-sf2 px-3 py-2.5">
                            <span className="text-[13px] font-semibold">
                                {copy.owners}
                            </span>
                            <span className="text-xs text-tx3">
                                {owners.length}
                            </span>
                        </div>
                        {owners.map((owner) => (
                            <button
                                key={owner.id}
                                type="button"
                                onClick={() => open(owner.id)}
                                aria-current={owner.id === selected}
                                className={cn(
                                    'flex w-full items-center gap-2 border-b border-l-[3px] border-b-ln2 px-3 py-2.5 text-left last:border-b-0',
                                    owner.id === selected
                                        ? 'border-l-ac bg-acs'
                                        : 'border-l-transparent hover:bg-sf2',
                                )}
                            >
                                <span className="min-w-0 flex-1">
                                    <span
                                        className={cn(
                                            'block font-mono text-xs',
                                            owner.id === selected
                                                ? 'text-act'
                                                : 'text-tx3',
                                        )}
                                    >
                                        {owner.code}
                                    </span>
                                    <span
                                        className={cn(
                                            'flex items-center gap-1.5 truncate text-[13px]',
                                            owner.id === selected &&
                                                'font-semibold text-act',
                                        )}
                                    >
                                        {owner.id === selected && (
                                            <Check className="size-3.5 flex-none" />
                                        )}
                                        <span className="truncate">
                                            {owner.name}
                                        </span>
                                    </span>
                                </span>
                                <span className="text-xs whitespace-nowrap text-tx3">
                                    {owner.assigned} ticked
                                </span>
                                <StatusBadge status={owner.status} />
                            </button>
                        ))}
                    </Panel>

                    {current ? (
                        <AssignmentForm
                            key={current.id}
                            side={side}
                            owner={current}
                            ownerNoun={copy.ownerNoun}
                            itemsTitle={copy.itemsTitle}
                            items={items}
                            selectedIds={selected_ids}
                            canUpdate={can.update}
                            emptyItems={copy.emptyItems}
                            noun={copy.noun}
                            saveLabel={copy.save}
                        />
                    ) : (
                        <Panel className="grid min-h-40 place-items-center p-6 text-center text-[13px] text-tx3">
                            Pick a {copy.ownerNoun} on the left to tick its{' '}
                            {copy.itemsTitle.toLowerCase()}.
                        </Panel>
                    )}
                </div>
            )}
        </>
    );
}

function AssignmentForm({
    side,
    owner,
    ownerNoun,
    itemsTitle,
    items,
    selectedIds,
    canUpdate,
    emptyItems,
    noun,
    saveLabel,
}: {
    side: 'partner' | 'branch';
    owner: Owner;
    ownerNoun: string;
    itemsTitle: string;
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
        <Panel className="overflow-hidden">
            {/* Who is being mapped, so it stays clear while ticking. */}
            <div className="flex flex-wrap items-center gap-3 border-b border-ln bg-acs px-4 py-3">
                <span className="text-xs font-medium text-tx2">
                    Selected {ownerNoun}
                </span>
                <span className="inline-flex min-w-0 items-center gap-2 rounded-lg bg-brand px-3 py-1.5 text-[13px] font-semibold text-white">
                    <span className="font-mono text-xs opacity-85">
                        {owner.code}
                    </span>
                    <span className="truncate">{owner.name}</span>
                </span>
                <StatusBadge status={owner.status} />
            </div>
            <div className="p-4">
                <div className="mb-3">
                    <h2 className="text-[15px] font-semibold">{itemsTitle}</h2>
                    <p className="text-xs text-tx3">
                        Tick the {noun === 'branch' ? 'branches' : 'partners'}{' '}
                        to assign to {owner.name}. Rates shown are deposit /
                        withdrawal.
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
            </div>
        </Panel>
    );
}
