import { Search, X } from 'lucide-react';
import type { ReactNode } from 'react';

export type FilterChip = {
    key: string;
    label: string;
    value: string;
    onRemove?: () => void;
};

/** Search box + active filter chips + summary (design: transactions toolbar). */
export function FilterBar({
    search,
    onSearch,
    placeholder = 'Search…',
    chips = [],
    trailing,
    onAddFilter,
}: {
    search?: string;
    onSearch?: (value: string) => void;
    placeholder?: string;
    chips?: FilterChip[];
    trailing?: ReactNode;
    onAddFilter?: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
            <label className="flex h-[30px] w-[260px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                <Search className="size-3.5" />
                <input
                    value={search}
                    onChange={(event) => onSearch?.(event.target.value)}
                    placeholder={placeholder}
                    className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                />
            </label>
            {chips.map((chip) => (
                <span
                    key={chip.key}
                    className="inline-flex h-[30px] items-center gap-1.5 rounded-[7px] border border-ln bg-sf2 pr-1.5 pl-2.5 text-[12.5px] whitespace-nowrap"
                >
                    <span className="text-tx3">{chip.label}</span>
                    <b className="font-medium text-tx">{chip.value}</b>
                    {chip.onRemove && (
                        <button
                            type="button"
                            onClick={chip.onRemove}
                            className="grid size-[18px] place-items-center rounded text-tx3 hover:bg-ln2"
                        >
                            <X className="size-3" />
                        </button>
                    )}
                </span>
            ))}
            {onAddFilter && (
                <button
                    type="button"
                    onClick={onAddFilter}
                    className="h-[30px] rounded-[7px] border border-dashed border-ln px-2.5 text-[12.5px] font-medium text-ac"
                >
                    + Add filter
                </button>
            )}
            <div className="flex-1" />
            {trailing && <span className="text-xs text-tx3">{trailing}</span>}
        </div>
    );
}

/** Tabs with counts along the top of a table card (design: saved views). */
export function ViewTabs({
    views,
    active,
    onChange,
}: {
    views: { key: string; label: string; count?: number }[];
    active: string;
    onChange: (key: string) => void;
}) {
    return (
        <div className="flex items-center gap-0.5 overflow-x-auto border-b border-ln2 px-3">
            {views.map((view) => (
                <button
                    key={view.key}
                    type="button"
                    onClick={() => onChange(view.key)}
                    className={
                        'flex h-[42px] items-center gap-1.5 border-b-2 px-2.5 text-[13px] font-medium whitespace-nowrap ' +
                        (view.key === active
                            ? 'border-ac text-tx'
                            : 'border-transparent text-tx2 hover:text-tx')
                    }
                >
                    {view.label}
                    {view.count !== undefined && (
                        <span className="rounded-[5px] bg-sf2 px-1.5 py-px text-[11px] text-tx3">
                            {view.count}
                        </span>
                    )}
                </button>
            ))}
        </div>
    );
}
