import { TONE_CLASSES } from '@/lib/status';
import type { Tone } from '@/lib/status';
import { cn } from '@/lib/utils';

const SUB_TONES: Record<'up' | 'down' | 'warn' | 'neutral', string> = {
    up: 'text-ok',
    down: 'text-er',
    warn: 'text-wn',
    neutral: 'text-tx3',
};

/** Dashboard metric card. */
export function KpiCard({
    label,
    value,
    sub,
    trend = 'neutral',
}: {
    label: string;
    value: string;
    sub?: string;
    trend?: keyof typeof SUB_TONES;
}) {
    return (
        <div className="flex flex-col gap-1.5 rounded-[10px] border border-ln bg-sf px-3.5 py-[13px]">
            <div className="text-xs text-tx2">{label}</div>
            <div className="text-xl font-semibold tracking-[-.02em]">
                {value}
            </div>
            {sub && (
                <div className={cn('text-xs', SUB_TONES[trend])}>{sub}</div>
            )}
        </div>
    );
}

/** Compact metric with an icon tile (design: transaction summary row). */
export function StatTile({
    label,
    value,
    icon,
    tone = 'nt',
}: {
    label: string;
    value: string;
    icon: string;
    tone?: Tone;
}) {
    return (
        <div className="flex items-center gap-3 rounded-[10px] border border-ln bg-sf px-3.5 py-3">
            <span
                className={cn(
                    'grid size-[30px] place-items-center rounded-lg text-xs',
                    TONE_CLASSES[tone],
                )}
            >
                {icon}
            </span>
            <div>
                <div className="text-xs text-tx2">{label}</div>
                <div className="mt-px text-lg font-semibold tracking-[-.02em]">
                    {value}
                </div>
            </div>
        </div>
    );
}

export function KpiGrid({ children }: { children: React.ReactNode }) {
    return (
        <div className="grid grid-cols-[repeat(auto-fill,minmax(190px,1fr))] gap-2.5">
            {children}
        </div>
    );
}
