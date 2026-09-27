import { PgButton } from './button';
import { TextInput } from './field';
import { StatusBadge } from './status-badge';

export type PickerItem = {
    id: string;
    code: string;
    name: string;
    status: string;
    rates: { deposit?: string; withdrawal?: string };
};

/**
 * Searchable checklist of partners or branches (mapping in the partner
 * wizard and the branch form), showing each one's deposit / withdrawal rate.
 */
export function OrgPicker({
    noun,
    emptyHint,
    items,
    selected,
    search,
    onSearch,
    disabled,
    onChange,
}: {
    noun: string;
    emptyHint: string;
    items: PickerItem[];
    selected: string[];
    search: string;
    onSearch: (value: string) => void;
    disabled: boolean;
    onChange: (ids: string[]) => void;
}) {
    const term = search.trim().toLowerCase();
    const visible = items.filter(
        (item) =>
            term === '' ||
            item.name.toLowerCase().includes(term) ||
            item.code.toLowerCase().includes(term),
    );
    const toggle = (id: string) =>
        onChange(
            selected.includes(id)
                ? selected.filter((x) => x !== id)
                : [...selected, id],
        );

    if (items.length === 0) {
        return <p className="text-[13px] text-tx2">{emptyHint}</p>;
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <TextInput
                    className="h-8 max-w-[260px] text-[12.5px]"
                    placeholder={`Search ${noun === 'branch' ? 'branches' : 'partners'}`}
                    value={search}
                    onChange={(event) => onSearch(event.target.value)}
                />
                <span className="text-xs text-tx3">
                    {selected.length} of {items.length} selected
                </span>
                <div className="flex-1" />
                {!disabled && (
                    <>
                        <PgButton
                            variant="ghost"
                            onClick={() =>
                                onChange([
                                    ...new Set([
                                        ...selected,
                                        ...visible.map((b) => b.id),
                                    ]),
                                ])
                            }
                        >
                            Select shown
                        </PgButton>
                        <PgButton variant="ghost" onClick={() => onChange([])}>
                            Clear
                        </PgButton>
                    </>
                )}
            </div>
            {disabled && (
                <p className="text-xs text-tx3">
                    You don’t have permission to change the mapping.
                </p>
            )}
            <div className="max-h-[340px] overflow-y-auto rounded-lg border border-ln">
                {visible.map((item) => (
                    <label
                        key={item.id}
                        className="flex cursor-pointer items-center gap-3 border-b border-ln2 px-3 py-2 last:border-b-0 hover:bg-sf2"
                    >
                        <input
                            type="checkbox"
                            className="size-4 accent-ac"
                            disabled={disabled}
                            checked={selected.includes(item.id)}
                            onChange={() => toggle(item.id)}
                        />
                        <span className="font-mono text-xs text-tx3">
                            {item.code}
                        </span>
                        <span className="flex-1 text-[13px]">{item.name}</span>
                        <span className="text-xs text-tx3">
                            {item.rates.deposit ?? '—'}% /{' '}
                            {item.rates.withdrawal ?? '—'}%
                        </span>
                        <StatusBadge status={item.status} />
                    </label>
                ))}
            </div>
        </div>
    );
}
