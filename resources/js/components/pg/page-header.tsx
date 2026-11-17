import type { ReactNode } from 'react';

/** Page title block (design: 22px title, description, actions on the right). */
export function PageHeader({
    title,
    description,
    eyebrow,
    actions,
    oneLine = false,
}: {
    title: string;
    description?: ReactNode;
    eyebrow?: ReactNode;
    actions?: ReactNode;
    /** Keep the description on one line (full text on hover), so pages
     * sharing tabs have the same header height. */
    oneLine?: boolean;
}) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-4">
            <div className={oneLine ? 'min-w-0 flex-1' : undefined}>
                {eyebrow}
                <h1 className="mt-1 text-[22px] font-semibold tracking-[-.015em]">
                    {title}
                </h1>
                {description && (
                    <p
                        className={`mt-1 text-[13px] text-tx2${oneLine ? ' truncate' : ''}`}
                        title={
                            oneLine && typeof description === 'string'
                                ? description
                                : undefined
                        }
                    >
                        {description}
                    </p>
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
