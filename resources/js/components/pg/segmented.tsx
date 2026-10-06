import { cn } from '@/lib/utils';

/** Segmented control (design: dashboard date ranges). */
export function Segmented<T extends string>({
    options,
    value,
    onChange,
}: {
    options: { value: T; label: string }[];
    value: T;
    onChange: (value: T) => void;
}) {
    return (
        <div className="flex flex-wrap gap-0.5 rounded-[9px] border border-ln bg-sf p-[3px]">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'h-[26px] rounded-md px-2.5 text-xs font-medium whitespace-nowrap',
                        option.value === value
                            ? 'bg-brand text-white'
                            : 'text-tx2 hover:text-tx',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
