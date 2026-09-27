import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type DrawerSummary = { label: string; value: ReactNode; tone?: string };

/**
 * Right-side detail drawer (design: 780px, header with id + status,
 * three summary figures, tabs, scrolling body).
 */
export function Drawer({
    open,
    onOpenChange,
    kind,
    title,
    monoTitle = true,
    status,
    subtitle,
    actions,
    summaries = [],
    tabs = [],
    activeTab,
    onTabChange,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    kind?: string;
    title: string;
    monoTitle?: boolean;
    status?: ReactNode;
    subtitle?: ReactNode;
    actions?: ReactNode;
    summaries?: DrawerSummary[];
    tabs?: { key: string; label: string }[];
    activeTab?: string;
    onTabChange?: (key: string) => void;
    children: ReactNode;
}) {
    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-40 bg-[rgba(10,15,28,.4)] data-[state=open]:animate-in data-[state=open]:fade-in-0" />
                <DialogPrimitive.Content className="fixed inset-y-0 right-0 z-[41] flex w-[min(780px,100vw)] flex-col bg-sf text-tx shadow-[-16px_0_48px_rgba(10,15,28,.22)] data-[state=open]:animate-in data-[state=open]:slide-in-from-right-8">
                    <div className="flex items-start gap-3 border-b border-ln2 px-[22px] pt-4 pb-3.5">
                        <div className="min-w-0 flex-1">
                            {kind && (
                                <div className="text-xs text-tx3">{kind}</div>
                            )}
                            <div className="mt-0.5 flex flex-wrap items-center gap-2.5">
                                <DialogPrimitive.Title
                                    className={cn(
                                        'text-[17px] font-medium',
                                        monoTitle
                                            ? 'font-mono'
                                            : 'font-semibold',
                                    )}
                                >
                                    {title}
                                </DialogPrimitive.Title>
                                {status}
                            </div>
                            {subtitle && (
                                <DialogPrimitive.Description className="mt-1 text-xs text-tx3">
                                    {subtitle}
                                </DialogPrimitive.Description>
                            )}
                        </div>
                        {actions}
                        <DialogPrimitive.Close className="grid size-8 place-items-center rounded-[7px] border border-ln bg-sf text-tx2 hover:bg-sf2">
                            <X className="size-4" />
                        </DialogPrimitive.Close>
                    </div>
                    {summaries.length > 0 && (
                        <div className="grid grid-cols-3 border-b border-ln2">
                            {summaries.map((summary) => (
                                <div
                                    key={summary.label}
                                    className="border-r border-ln2 px-[22px] py-3.5 last:border-r-0"
                                >
                                    <div className="text-xs text-tx3">
                                        {summary.label}
                                    </div>
                                    <div
                                        className={cn(
                                            'mt-0.5 text-[19px] font-semibold tracking-[-.02em]',
                                            summary.tone,
                                        )}
                                    >
                                        {summary.value}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    {tabs.length > 0 && (
                        <div className="flex flex-none gap-0.5 overflow-x-auto border-b border-ln2 px-4">
                            {tabs.map((tab) => (
                                <button
                                    key={tab.key}
                                    type="button"
                                    onClick={() => onTabChange?.(tab.key)}
                                    className={cn(
                                        'h-10 border-b-2 px-2.5 text-[13px] font-medium whitespace-nowrap',
                                        tab.key === activeTab
                                            ? 'border-ac text-tx'
                                            : 'border-transparent text-tx2',
                                    )}
                                >
                                    {tab.label}
                                </button>
                            ))}
                        </div>
                    )}
                    <div className="flex flex-1 flex-col gap-[18px] overflow-y-auto px-[22px] pt-[18px] pb-7">
                        {children}
                    </div>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

/** Label / value grid used inside drawers. */
export function KeyValues({
    items,
}: {
    items: { label: string; value: ReactNode; mono?: boolean }[];
}) {
    return (
        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
            {items.map((item) => (
                <div key={item.label}>
                    <dt className="text-xs text-tx3">{item.label}</dt>
                    <dd
                        className={cn(
                            'mt-0.5',
                            item.mono ? 'font-mono text-xs' : 'text-[13px]',
                        )}
                    >
                        {item.value ?? '—'}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
