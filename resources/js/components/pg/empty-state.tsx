import type { ReactNode } from 'react';

export function EmptyState({
    title,
    description,
    action,
}: {
    title: string;
    description?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 px-6 py-14 text-center">
            <div className="grid size-10 place-items-center rounded-[10px] bg-acs font-semibold text-act">
                ∅
            </div>
            <div className="text-sm font-semibold">{title}</div>
            {description && (
                <div className="max-w-md text-[13px] text-tx2">
                    {description}
                </div>
            )}
            {action}
        </div>
    );
}
