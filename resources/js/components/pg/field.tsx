import type {
    InputHTMLAttributes,
    ReactNode,
    SelectHTMLAttributes,
    TextareaHTMLAttributes,
} from 'react';
import { cn } from '@/lib/utils';

const control =
    'w-full rounded-lg border border-ln bg-sf px-3 text-sm text-tx outline-none placeholder:text-tx3 focus:border-ac focus:ring-[3px] focus:ring-acs disabled:bg-sf2 disabled:text-tx3 aria-invalid:border-er';

/** Label + control + hint / error (design: form field). */
export function Field({
    label,
    hint,
    error,
    children,
}: {
    label: string;
    hint?: ReactNode;
    error?: string;
    children: ReactNode;
}) {
    return (
        <label className="flex flex-col gap-1.5 text-[12.5px] font-medium text-tx2">
            {label}
            {children}
            {error ? (
                <span className="text-xs font-normal text-er">{error}</span>
            ) : (
                hint && (
                    <span className="text-xs font-normal text-tx3">{hint}</span>
                )
            )}
        </label>
    );
}

export function TextInput({
    className,
    invalid,
    ...props
}: InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }) {
    return (
        <input
            aria-invalid={invalid || undefined}
            className={cn(control, 'h-10', className)}
            {...props}
        />
    );
}

export function TextArea({
    className,
    invalid,
    ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }) {
    return (
        <textarea
            aria-invalid={invalid || undefined}
            className={cn(control, 'min-h-[72px] py-2', className)}
            {...props}
        />
    );
}

export function SelectInput({
    className,
    invalid,
    children,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean }) {
    return (
        <select
            aria-invalid={invalid || undefined}
            className={cn(control, 'h-10 pr-8', className)}
            {...props}
        >
            {children}
        </select>
    );
}
