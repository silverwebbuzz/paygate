import { useForm } from '@inertiajs/react';
import { Download, Eye, Undo2 } from 'lucide-react';
import type { MouseEvent } from 'react';
import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { TONE_CLASSES } from '@/lib/status';
import type { Tone } from '@/lib/status';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import branch from '@/routes/branch';
import { show as fileUrl } from '@/routes/files';
import type { UserType } from '@/types';
import { PgButton } from './button';
import { Field, TextArea, TextInput } from './field';
import { FormDialog } from './form-dialog';
import type { TxnChargeback, TxnRow } from './transaction-drawer';

const STATES: Record<TxnChargeback['status'], [string, Tone]> = {
    requested: ['Chargeback raised', 'wn'],
    approved: ['Chargeback', 'er'],
    rejected: ['Chargeback rejected', 'nt'],
};

export function ChargebackBadge({
    status,
}: {
    status: TxnChargeback['status'];
}) {
    const [label, tone] = STATES[status];

    return (
        <span
            className={cn(
                'inline-flex h-[22px] items-center gap-1.5 rounded-md px-2 text-xs font-medium whitespace-nowrap',
                TONE_CLASSES[tone],
            )}
        >
            <span aria-hidden className="text-[11px]">
                ↺
            </span>
            {label}
        </span>
    );
}

/** Preview opens the bank's notice in a tab; Download saves it. */
export function ProofLinks({ fileId }: { fileId: string }) {
    const stop = (event: MouseEvent) => event.stopPropagation();

    return (
        <div className="flex gap-1.5">
            <a
                href={fileUrl(fileId).url}
                target="_blank"
                rel="noreferrer"
                onClick={stop}
                className="inline-flex h-6 items-center gap-1 rounded-md border border-ln px-2 text-[11.5px] font-medium hover:bg-sf2"
            >
                <Eye className="size-3" /> Preview
            </a>
            <a
                href={fileUrl(fileId, { query: { download: 1 } }).url}
                onClick={stop}
                className="inline-flex h-6 items-center gap-1 rounded-md border border-ln px-2 text-[11.5px] font-medium hover:bg-sf2"
            >
                <Download className="size-3" /> Download
            </a>
        </div>
    );
}

/**
 * The list's Chargeback column: the chargeback's state, amount and dates
 * with the bank's notice, or a button to raise one on a successful deposit.
 */
export function ChargebackCell({
    txn,
    onRaise,
}: {
    txn: TxnRow;
    onRaise: (txn: TxnRow) => void;
}) {
    const cb = txn.chargeback;

    if (!cb || cb.status === 'rejected') {
        return (
            <div className="flex flex-col items-start gap-1">
                {cb && (
                    <span title={cb.decision_note ?? undefined}>
                        <ChargebackBadge status={cb.status} />
                    </span>
                )}
                {txn.can.chargeback ? (
                    <PgButton
                        className="h-7 px-2 text-xs"
                        onClick={(event) => {
                            event.stopPropagation();
                            onRaise(txn);
                        }}
                    >
                        <Undo2 className="size-3" /> Chargeback
                    </PgButton>
                ) : (
                    !cb && <span className="text-tx3">—</span>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col items-start gap-1 text-xs">
            <ChargebackBadge status={cb.status} />
            <div>
                Amount <b>{formatPaise(cb.amount)}</b>
            </div>
            {cb.requested_at && (
                <div className="whitespace-nowrap text-tx3">
                    Raised {formatDateTime(cb.requested_at)}
                </div>
            )}
            {cb.decided_at && (
                <div className="whitespace-nowrap text-tx3">
                    Approved {formatDateTime(cb.decided_at)}
                </div>
            )}
            {cb.proof_file_id && <ProofLinks fileId={cb.proof_file_id} />}
        </div>
    );
}

/**
 * Raise a chargeback (branch, or Admin for it): the amount (starts as the
 * deposit's), the transaction's UTR and the bank's notice. Admin approves.
 */
export function RaiseChargebackDialog({
    txn,
    portal,
    onClose,
}: {
    txn: TxnRow;
    portal: UserType;
    onClose: () => void;
}) {
    const form = useForm<{
        amount: string;
        utr: string;
        reason: string;
        proof: File | null;
    }>({
        amount: (txn.amount / 100).toFixed(2).replace(/\.00$/, ''),
        utr: '',
        reason: '',
        proof: null,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const url =
        portal === 'admin'
            ? admin.transactions.chargeback(txn.id).url
            : branch.payins.chargeback(txn.id).url;

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            width={500}
            title={`Raise a chargeback on ${txn.reference}`}
            description="The customer’s bank took this deposit back. Admin checks the bank’s notice and approves it; it is then booked on that day: the partner is debited and your branch owes that much less."
            submitLabel={form.processing ? 'Sending…' : 'Send for approval'}
            processing={form.processing}
            onSubmit={() =>
                form.post(url, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
        >
            <div className="rounded-lg border border-ln p-3 text-[13px]">
                <div className="font-mono text-xs font-medium">
                    {txn.reference}
                </div>
                <div className="mt-1 text-lg font-semibold">
                    {formatPaise(txn.amount)}
                </div>
                <div className="text-xs text-tx3">
                    {txn.partner?.name} · approved{' '}
                    {formatDateTime(txn.decided_at)}
                </div>
            </div>
            {errors.transaction && (
                <p className="text-xs text-er">{errors.transaction}</p>
            )}
            <Field
                label="Chargeback amount (₹)"
                required
                error={errors.amount}
                hint="Usually the full deposit."
            >
                <TextInput
                    inputMode="decimal"
                    required
                    value={form.data.amount}
                    onChange={(event) =>
                        form.setData('amount', event.target.value)
                    }
                />
            </Field>
            <Field
                label="UTR of the transaction"
                required
                error={errors.utr}
                hint="As shown on the bank’s notice; it must match this deposit."
            >
                <TextInput
                    className="font-mono"
                    required
                    value={form.data.utr}
                    onChange={(event) =>
                        form.setData('utr', event.target.value)
                    }
                />
            </Field>
            <Field
                label="Bank’s notice (image or PDF)"
                required
                error={errors.proof}
            >
                <input
                    type="file"
                    required
                    accept=".jpg,.jpeg,.png,.webp,.pdf"
                    onChange={(event) =>
                        form.setData('proof', event.target.files?.[0] ?? null)
                    }
                    className="text-[13px] file:mr-3 file:h-8 file:rounded-[7px] file:border file:border-ln file:bg-sf file:px-3 file:text-[13px] file:font-medium"
                />
            </Field>
            <Field label="Note (optional)" error={errors.reason}>
                <TextArea
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}
