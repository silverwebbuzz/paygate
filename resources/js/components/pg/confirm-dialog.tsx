import * as DialogPrimitive from '@radix-ui/react-dialog';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Confirmation modal (design: approve / hold / decline). When `input` is set,
 * the user must fill it (e.g. bank UTR, decline reason) before confirming.
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    tone = 'primary',
    input,
    processing = false,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    confirmLabel: string;
    tone?: 'primary' | 'danger' | 'warning';
    input?: { label: string; placeholder?: string; required?: boolean };
    processing?: boolean;
    onConfirm: (value: string) => void;
}) {
    const [value, setValue] = useState('');
    const blocked =
        processing || (input?.required === true && value.trim() === '');
    const toneClass = {
        primary: 'bg-brand',
        danger: 'bg-er',
        warning: 'bg-hd',
    }[tone];

    return (
        <DialogPrimitive.Root
            open={open}
            onOpenChange={(next) => {
                if (!next) setValue('');
                onOpenChange(next);
            }}
        >
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-[rgba(10,15,28,.45)]" />
                <DialogPrimitive.Content className="fixed top-1/2 left-1/2 z-50 flex w-[min(440px,calc(100vw-32px))] -translate-1/2 flex-col gap-4 rounded-xl border border-ln bg-sf p-5 text-tx shadow-[0_18px_48px_rgba(15,23,42,.18)]">
                    <div>
                        <DialogPrimitive.Title className="text-base font-semibold">
                            {title}
                        </DialogPrimitive.Title>
                        {description && (
                            <DialogPrimitive.Description className="mt-1 text-[13px] text-tx2">
                                {description}
                            </DialogPrimitive.Description>
                        )}
                    </div>
                    {input && (
                        <label className="flex flex-col gap-1.5 text-[12.5px] font-medium text-tx2">
                            {input.label}
                            <input
                                autoFocus
                                value={value}
                                onChange={(event) =>
                                    setValue(event.target.value)
                                }
                                placeholder={input.placeholder}
                                className="h-10 rounded-lg border border-ln bg-sf px-3 text-sm text-tx outline-none focus:border-ac focus:ring-[3px] focus:ring-acs"
                            />
                        </label>
                    )}
                    <div className="flex justify-end gap-2">
                        <DialogPrimitive.Close className="h-8 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium">
                            Cancel
                        </DialogPrimitive.Close>
                        <button
                            type="button"
                            disabled={blocked}
                            onClick={() => onConfirm(value.trim())}
                            className={cn(
                                'h-8 rounded-[7px] px-3.5 text-[13px] font-medium text-white disabled:opacity-50',
                                toneClass,
                            )}
                        >
                            {confirmLabel}
                        </button>
                    </div>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
