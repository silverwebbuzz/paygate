import * as DialogPrimitive from '@radix-ui/react-dialog';
import { Check, Copy, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { useClipboard } from '@/hooks/use-clipboard';

export type Credentials = {
    partner: string;
    key_id: string | null;
    secret: string | null;
    file_url?: string | null;
};

/**
 * Shows a newly generated API secret once. It can't be displayed again, so
 * the dialog only closes after the person confirms they saved it.
 */
export function CredentialsDialog({
    credentials,
    onClose,
}: {
    credentials: Credentials | null;
    onClose: () => void;
}) {
    const [saved, setSaved] = useState(false);

    return (
        <DialogPrimitive.Root
            open={credentials !== null && credentials.secret !== null}
            onOpenChange={(open) => {
                if (!open && saved) {
                    setSaved(false);
                    onClose();
                }
            }}
        >
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-[rgba(10,15,28,.45)]" />
                <DialogPrimitive.Content
                    onEscapeKeyDown={(event) =>
                        !saved && event.preventDefault()
                    }
                    onPointerDownOutside={(event) => event.preventDefault()}
                    className="fixed top-1/2 left-1/2 z-50 flex w-[min(520px,calc(100vw-32px))] -translate-1/2 flex-col gap-4 rounded-xl border border-ln bg-sf p-5 text-tx shadow-[0_18px_48px_rgba(15,23,42,.18)]"
                >
                    <div>
                        <DialogPrimitive.Title className="text-base font-semibold">
                            API credentials for {credentials?.partner}
                        </DialogPrimitive.Title>
                        <DialogPrimitive.Description className="mt-1 text-[13px] text-tx2">
                            Give these to the partner’s developer over a secure
                            channel. They sign every API request with the
                            secret.
                        </DialogPrimitive.Description>
                    </div>
                    <div className="flex items-start gap-2 rounded-lg bg-wnb px-3 py-2.5 text-[12.5px] text-wn">
                        <TriangleAlert className="mt-0.5 size-4 flex-none" />
                        This is the only time the secret is shown. PayGate
                        stores it encrypted and can’t display it again; if it is
                        lost, generate a new one.
                    </div>
                    <SecretRow
                        label="Key ID"
                        value={credentials?.key_id ?? ''}
                    />
                    <SecretRow
                        label="Secret"
                        value={credentials?.secret ?? ''}
                    />
                    {credentials?.file_url && (
                        <div className="flex flex-col items-start gap-1">
                            <a
                                href={credentials.file_url}
                                className="inline-flex h-8 items-center justify-center rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                            >
                                Download partner file
                            </a>
                            <p className="text-xs text-tx3">
                                This copy includes the secret. A later download
                                from the partner list does not.
                            </p>
                        </div>
                    )}
                    <label className="flex items-center gap-2 text-[13px]">
                        <input
                            type="checkbox"
                            checked={saved}
                            onChange={(event) => setSaved(event.target.checked)}
                            className="size-4 accent-ac"
                        />
                        I have copied the secret to a safe place
                    </label>
                    <div className="flex justify-end">
                        <DialogPrimitive.Close
                            disabled={!saved}
                            className="h-8 rounded-[7px] bg-brand px-3.5 text-[13px] font-medium text-white disabled:opacity-50"
                        >
                            Done
                        </DialogPrimitive.Close>
                    </div>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

function SecretRow({ label, value }: { label: string; value: string }) {
    const [copied, copy] = useClipboard();

    return (
        <div>
            <div className="mb-1 text-xs text-tx3">{label}</div>
            <div className="flex items-center gap-2 rounded-lg border border-ln bg-sf2 px-3 py-2">
                <code className="min-w-0 flex-1 font-mono text-[12.5px] break-all">
                    {value}
                </code>
                <button
                    type="button"
                    onClick={() => copy(value)}
                    className="inline-flex h-7 flex-none items-center gap-1 rounded-md border border-ln bg-sf px-2 text-xs font-medium"
                >
                    {copied === value ? (
                        <Check className="size-3.5 text-ok" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                    {copied === value ? 'Copied' : 'Copy'}
                </button>
            </div>
        </div>
    );
}
