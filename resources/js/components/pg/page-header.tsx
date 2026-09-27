import type { ReactNode } from 'react';

/** Page title block (design: 22px title, description, actions on the right). */
export function PageHeader({
    title,
    description,
    eyebrow,
    actions,
}: {
    title: string;
    description?: ReactNode;
    eyebrow?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-4">
            <div>
                {eyebrow}
                <h1 className="mt-1 text-[22px] font-semibold tracking-[-.015em]">
                    {title}
                </h1>
                {description && (
                    <p className="mt-1 text-[13px] text-tx2">{description}</p>
                )}
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

/** "Live · refreshed 12s ago" style indicator. */
export function LiveIndicator({ children }: { children: ReactNode }) {
    return (
        <div className="flex items-center gap-2 text-xs font-medium text-ok">
            <span className="size-[7px] animate-[pg-pulse_1.6s_infinite] rounded-full bg-ok" />
            {children}
        </div>
    );
}
