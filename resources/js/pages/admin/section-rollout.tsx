import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { SwitchField } from '@/components/pg/switch-field';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';

type Portal = 'admin' | 'branch' | 'partner';

type Group = {
    label: string;
    items: { key: string; label: string; path: string }[];
};

type Props = {
    portals: Record<Portal, Group[]>;
    state: { active: boolean; open: Record<Portal, string[]> };
};

const PORTALS: { value: Portal; label: string }[] = [
    { value: 'admin', label: 'Admin portal' },
    { value: 'branch', label: 'Branch portal' },
    { value: 'partner', label: 'Partner portal' },
];

/**
 * Section rollout (super admins only): while it is on, everyone except
 * super admins sees only the menu items opened here, so the client can test
 * one section at a time. Every change is saved at once and audited.
 */
export default function SectionRollout({ portals, state }: Props) {
    const [portal, setPortal] = useState<Portal>('admin');

    const save = (active: boolean, open: Props['state']['open']) =>
        router.put(
            admin.sectionRollout.update().url,
            { active, open },
            { preserveScroll: true },
        );

    const setOpen = (keys: string[]) =>
        save(state.active, { ...state.open, [portal]: keys });

    const open = state.open[portal];
    const all = portals[portal].flatMap((group) =>
        group.items.map((item) => item.key),
    );

    return (
        <>
            <Head title="Section rollout" />
            <PageHeader
                title="Section rollout"
                description="Show the client one section at a time. While rollout is on, everyone except super admins sees only the sections opened here; the others disappear from their menu and can’t be opened."
            />

            <Panel
                className={cn(
                    'flex flex-col gap-2 p-4',
                    state.active && 'ring-2 ring-ac',
                )}
            >
                <SwitchField
                    label={
                        state.active
                            ? 'Rollout is ON: other users see only the open sections'
                            : 'Rollout is OFF: everyone sees every section'
                    }
                    hint="Super admins always see everything. Dashboards and Profile & settings are always open. Remember to switch rollout off (or open everything) before go-live."
                    checked={state.active}
                    onChange={(active) => save(active, state.open)}
                />
            </Panel>

            <div className="flex flex-wrap items-center justify-between gap-2">
                <Segmented<Portal>
                    value={portal}
                    onChange={setPortal}
                    options={PORTALS.map((option) => ({
                        value: option.value,
                        label: `${option.label} · ${state.open[option.value].length}/${portals[option.value].flatMap((group) => group.items).length}`,
                    }))}
                />
                <div className="flex gap-2">
                    <PgButton onClick={() => setOpen(all)}>Open all</PgButton>
                    <PgButton onClick={() => setOpen([])}>Close all</PgButton>
                </div>
            </div>

            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {portals[portal].map((group) => {
                    const keys = group.items.map((item) => item.key);
                    const openHere = keys.filter((key) => open.includes(key));

                    return (
                        <Panel
                            key={group.label}
                            className="flex h-fit flex-col gap-2 p-4"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <div className="text-sm font-semibold">
                                    {group.label}{' '}
                                    <span className="text-xs font-normal text-tx3">
                                        {openHere.length}/{keys.length} open
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    className="text-xs font-medium text-ac"
                                    onClick={() =>
                                        setOpen(
                                            openHere.length === keys.length
                                                ? open.filter(
                                                      (key) =>
                                                          !keys.includes(key),
                                                  )
                                                : [
                                                      ...new Set([
                                                          ...open,
                                                          ...keys,
                                                      ]),
                                                  ],
                                        )
                                    }
                                >
                                    {openHere.length === keys.length
                                        ? 'Close group'
                                        : 'Open group'}
                                </button>
                            </div>
                            {group.items.map((item) => (
                                <SwitchField
                                    key={item.key}
                                    label={item.label}
                                    hint={item.path}
                                    checked={open.includes(item.key)}
                                    onChange={(checked) =>
                                        setOpen(
                                            checked
                                                ? [...open, item.key]
                                                : open.filter(
                                                      (key) => key !== item.key,
                                                  ),
                                        )
                                    }
                                />
                            ))}
                        </Panel>
                    );
                })}
            </div>
        </>
    );
}
