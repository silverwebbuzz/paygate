import type { ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

const VARIANTS = {
    primary: 'border-transparent bg-brand text-white hover:brightness-110',
    secondary: 'border-ln bg-sf text-tx hover:bg-sf2',
    danger: 'border-ln bg-sf text-er hover:bg-erb',
    ghost: 'border-transparent bg-transparent text-tx2 hover:bg-sf2',
} as const;

/** The design's 32px button (primary = magenta → plum gradient). */
export function PgButton({
    variant = 'secondary',
    className,
    type = 'button',
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: keyof typeof VARIANTS;
}) {
    return (
        <button
            type={type}
            className={cn(
                'inline-flex h-8 items-center gap-1.5 rounded-[7px] border px-3 text-[13px] font-medium whitespace-nowrap disabled:pointer-events-none disabled:opacity-50',
                VARIANTS[variant],
                className,
            )}
            {...props}
        />
    );
}
