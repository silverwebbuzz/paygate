import { cn } from '@/lib/utils';

/** On/off setting with a label and hint (design: "Enabled ●" rows). */
export function SwitchField({
    label,
    hint,
    checked,
    disabled,
    onChange,
}: {
    label: string;
    hint?: string;
    checked: boolean;
    disabled?: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label
            className={cn(
                'flex items-center justify-between gap-3 rounded-lg border border-ln px-3 py-2.5',
                disabled ? 'opacity-60' : 'cursor-pointer',
            )}
        >
            <span className="min-w-0">
                <span className="block text-[13px] font-medium text-tx">
                    {label}
                </span>
                {hint && <span className="block text-xs text-tx3">{hint}</span>}
            </span>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={cn(
                    'relative h-5 w-9 flex-none rounded-full transition-colors',
                    checked ? 'bg-ac' : 'bg-ln',
                )}
            >
                <span
                    className={cn(
                        'absolute top-0.5 size-4 rounded-full bg-white shadow transition-[left]',
                        checked ? 'left-[18px]' : 'left-0.5',
                    )}
                />
            </button>
        </label>
    );
}
