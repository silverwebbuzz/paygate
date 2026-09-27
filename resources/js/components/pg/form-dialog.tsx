import * as DialogPrimitive from '@radix-ui/react-dialog';
import type { FormEvent, ReactNode } from 'react';

/**
 * Centred modal holding a small form (design: modal, 440px, title,
 * description, fields, Cancel + primary action).
 */
export function FormDialog({
    open,
    onOpenChange,
    title,
    description,
    submitLabel,
    processing = false,
    onSubmit,
    children,
    width = 440,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    submitLabel: string;
    processing?: boolean;
    onSubmit: () => void;
    children: ReactNode;
    width?: number;
}) {
    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSubmit();
    };

    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-[rgba(10,15,28,.45)]" />
                <DialogPrimitive.Content
                    style={{ width: `min(${width}px, calc(100vw - 32px))` }}
                    className="fixed top-1/2 left-1/2 z-50 max-h-[calc(100vh-32px)] -translate-1/2 overflow-y-auto rounded-xl border border-ln bg-sf p-5 text-tx shadow-[0_18px_48px_rgba(15,23,42,.18)]"
                >
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div>
                            <DialogPrimitive.Title className="text-base font-semibold">
                                {title}
                            </DialogPrimitive.Title>
                            {description ? (
                                <DialogPrimitive.Description className="mt-1 text-[13px] text-tx2">
                                    {description}
                                </DialogPrimitive.Description>
                            ) : (
                                <DialogPrimitive.Description className="sr-only">
                                    {title}
                                </DialogPrimitive.Description>
                            )}
                        </div>
                        {children}
                        <div className="flex justify-end gap-2">
                            <DialogPrimitive.Close
                                type="button"
                                className="h-8 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                            >
                                Cancel
                            </DialogPrimitive.Close>
                            <button
                                type="submit"
                                disabled={processing}
                                className="h-8 rounded-[7px] bg-ac px-3.5 text-[13px] font-medium text-white disabled:opacity-50"
                            >
                                {submitLabel}
                            </button>
                        </div>
                    </form>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
