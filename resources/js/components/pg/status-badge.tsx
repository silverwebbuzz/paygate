import { statusStyle, TONE_CLASSES } from '@/lib/status';
import { cn } from '@/lib/utils';

/**
 * Status pill with the design's icon + label + tone (lib/status.ts). `label`
 * overrides the wording where a status means something else on a screen
 * (e.g. a rejected account vs a declined payment).
 */
export function StatusBadge({
    status,
    label,
    className,
}: {
    status: string;
    label?: string;
    className?: string;
}) {
    const style = statusStyle(status);

    return (
        <span
            className={cn(
                'inline-flex h-[22px] items-center gap-1.5 rounded-md px-2 text-xs font-medium whitespace-nowrap',
                TONE_CLASSES[style.tone],
                className,
            )}
        >
            <span aria-hidden className="text-[11px]">
                {style.icon}
            </span>
            {label ?? style.label}
        </span>
    );
}
