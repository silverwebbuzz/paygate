import { ChevronDown } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

type Org = { id: string; code: string; name: string };

/** Pick any number of branches / partners (none = all), with a search box. */
export function MultiSelect({
    noun,
    items,
    selected,
    onChange,
    className,
}: {
    noun: string;
    items: Org[];
    selected: string[];
    onChange: (ids: string[]) => void;
    className?: string;
}) {
    const [search, setSearch] = useState('');
    const term = search.trim().toLowerCase();
    const visible = items.filter(
        (item) =>
            term === '' ||
            item.code.toLowerCase().includes(term) ||
            item.name.toLowerCase().includes(term),
    );
    const first = items.find((item) => item.id === selected[0]);
    const toggle = (id: string) =>
        onChange(
            selected.includes(id)
                ? selected.filter((value) => value !== id)
                : [...selected, id],
        );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className={cn(
                    'flex h-10 min-w-[220px] flex-1 items-center gap-2 rounded-lg border border-ln bg-sf px-3 text-left text-[13px]',
                    className,
                )}
            >
                {first ? (
                    <span className="flex min-w-0 flex-1 items-center gap-1.5">
                        <span className="truncate rounded-md bg-acs px-2 py-0.5 text-xs font-semibold text-act">
                            {first.code} {first.name}
                        </span>
                        {selected.length > 1 && (
                            <span className="text-xs text-tx3">
                                +{selected.length - 1}
                            </span>
                        )}
                    </span>
                ) : (
                    <span className="flex-1 text-tx3">All {noun}</span>
                )}
                <ChevronDown className="size-4 flex-none text-tx3" />
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-80 w-[var(--radix-dropdown-menu-trigger-width)] overflow-y-auto"
            >
                <div className="p-1">
                    <input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        onKeyDown={(event) => event.stopPropagation()}
                        placeholder={`Search ${noun}`}
                        className="h-8 w-full rounded-md border border-ln bg-sf px-2 text-[13px]"
                    />
                </div>
                <DropdownMenuLabel className="flex items-center justify-between text-xs text-tx3">
                    {selected.length === 0
                        ? `All ${noun}`
                        : `${selected.length} selected`}
                    {selected.length > 0 && (
                        <button
                            type="button"
                            onClick={() => onChange([])}
                            className="font-medium text-ac"
                        >
                            Clear
                        </button>
                    )}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {visible.map((item) => (
                    <DropdownMenuCheckboxItem
                        key={item.id}
                        checked={selected.includes(item.id)}
                        onCheckedChange={() => toggle(item.id)}
                        onSelect={(event) => event.preventDefault()}
                    >
                        <span className="font-medium">{item.code}</span>
                        <span className="truncate text-tx3">{item.name}</span>
                    </DropdownMenuCheckboxItem>
                ))}
                {visible.length === 0 && (
                    <div className="px-2 py-3 text-center text-xs text-tx3">
                        No {noun} match.
                    </div>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Pick one branch / partner from a list that may be long, with a search box
 * (by code or name). `allLabel` adds an "all" choice (value ''); without it
 * the field is required and shows `placeholder` until something is picked.
 */
export function SearchSelect({
    noun,
    items,
    value,
    onChange,
    allLabel,
    placeholder = 'Choose…',
    invalid,
    className,
    ariaLabel,
}: {
    noun: string;
    items: Org[];
    value: string;
    onChange: (id: string) => void;
    allLabel?: string;
    placeholder?: string;
    invalid?: boolean;
    className?: string;
    ariaLabel?: string;
}) {
    const [search, setSearch] = useState('');
    const input = useRef<HTMLInputElement>(null);
    const term = search.trim().toLowerCase();
    const visible = items.filter(
        (item) =>
            term === '' ||
            item.code.toLowerCase().includes(term) ||
            item.name.toLowerCase().includes(term),
    );
    const current = items.find((item) => item.id === value);

    return (
        <DropdownMenu
            onOpenChange={(open) => {
                setSearch('');

                if (open) {
                    requestAnimationFrame(() => input.current?.focus());
                }
            }}
        >
            <DropdownMenuTrigger
                aria-label={ariaLabel}
                aria-invalid={invalid || undefined}
                className={cn(
                    'flex h-10 w-full items-center gap-2 rounded-lg border border-ln bg-sf px-3 text-left text-sm text-tx outline-none focus:border-ac focus:ring-[3px] focus:ring-acs aria-invalid:border-er',
                    className,
                )}
            >
                <span
                    className={cn(
                        'min-w-0 flex-1 truncate',
                        !current && 'text-tx3',
                    )}
                >
                    {current
                        ? `${current.code} · ${current.name}`
                        : (allLabel ?? placeholder)}
                </span>
                <ChevronDown className="size-4 flex-none text-tx3" />
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-80 w-[max(var(--radix-dropdown-menu-trigger-width),240px)] overflow-y-auto"
            >
                <div className="p-1">
                    <input
                        ref={input}
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        onKeyDown={(event) => {
                            // Typing goes to the box, not the menu's typeahead.
                            if (event.key !== 'Escape') {
                                event.stopPropagation();
                            }
                        }}
                        placeholder={`Search ${noun} by code or name`}
                        className="h-8 w-full rounded-md border border-ln bg-sf px-2 text-[13px] outline-none focus:border-ac"
                    />
                </div>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup value={value} onValueChange={onChange}>
                    {allLabel !== undefined && term === '' && (
                        <DropdownMenuRadioItem value="">
                            {allLabel}
                        </DropdownMenuRadioItem>
                    )}
                    {visible.map((item) => (
                        <DropdownMenuRadioItem key={item.id} value={item.id}>
                            <span className="font-medium">{item.code}</span>
                            <span className="truncate text-tx3">
                                {item.name}
                            </span>
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
                {visible.length === 0 && (
                    <div className="px-2 py-3 text-center text-xs text-tx3">
                        No {noun} match.
                    </div>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
