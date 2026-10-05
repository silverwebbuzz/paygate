import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
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
    required,
    children,
}: {
    label: string;
    hint?: ReactNode;
    error?: string;
    required?: boolean;
    children: ReactNode;
}) {
    return (
        <label className="flex flex-col gap-1.5 text-[12.5px] font-medium text-tx2">
            <span>
                {label}
                {required && (
                    <span aria-hidden="true" className="ml-0.5 text-er">
                        *
                    </span>
                )}
            </span>
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

/** Password field with an eye button to show / hide what is typed. */
export function PasswordTextInput({
    className,
    invalid,
    ...props
}: Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & {
    invalid?: boolean;
}) {
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <input
                type={visible ? 'text' : 'password'}
                aria-invalid={invalid || undefined}
                className={cn(control, 'h-10 pr-10', className)}
                {...props}
            />
            <button
                type="button"
                onClick={() => setVisible(!visible)}
                aria-label={visible ? 'Hide password' : 'Show password'}
                title={visible ? 'Hide password' : 'Show password'}
                className="absolute inset-y-0 right-0 flex items-center px-3 text-tx3 hover:text-tx"
            >
                {visible ? (
                    <EyeOff className="size-4" />
                ) : (
                    <Eye className="size-4" />
                )}
            </button>
        </div>
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
