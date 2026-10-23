import { useForm } from '@inertiajs/react';
import { Check, Undo2, X } from 'lucide-react';
import type { MouseEvent } from 'react';
import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { TONE_CLASSES } from '@/lib/status';
import type { Tone } from '@/lib/status';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import branch from '@/routes/branch';
import partner from '@/routes/partner';
import type { UserType } from '@/types';
import { PgButton } from './button';
import { ProofLinks } from './chargeback';
import { Field, SelectInput, TextArea, TextInput } from './field';
import { FormDialog } from './form-dialog';
import type { TxnPayoutReturn, TxnRow } from './transaction-drawer';

const STATES: Record<TxnPayoutReturn['status'], [string, Tone]> = {
    requested: ['Not received: to check', 'wn'],
    approved: ['Returned', 'rv'],
    rejected: ['Return rejected', 'nt'],
};

/** What the list's buttons open. */
export type ReturnAction =
    | { kind: 'raise'; txn: TxnRow }
    | { kind: 'record'; txn: TxnRow }
    | { kind: 'confirm'; txn: TxnRow }
    | { kind: 'reject'; txn: TxnRow };

export function ReturnBadge({ status }: { status: TxnPayoutReturn['status'] }) {
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

function SmallButton({
    onClick,
    children,
}: {
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <PgButton
            className="h-7 px-2 text-xs"
            onClick={(event: MouseEvent) => {
                event.stopPropagation();
                onClick();
            }}
        >
            {children}
        </PgButton>
    );
}

/**
 * The payout list's Return column: the return's state (amount, UTR, dates,
 * the bank's proof) and the buttons this viewer may use.
 */
export function ReturnCell({
    txn,
    onAction,
}: {
    txn: TxnRow;
    onAction: (action: ReturnAction) => void;
}) {
    const rt = txn.payout_return;
    // All confirmed returns: a payout paid in parts may have one per transfer.
    const returned =
        txn.returned_total ??
        (rt?.status === 'approved' ? (rt.amount ?? 0) : 0);
    const returnedTransfers = (txn.payments ?? []).filter(
        (part) => part.returned !== null,
    ).length;
    const can = txn.can;
    const buttons = (
        <>
            {can.return_raise && (
                <SmallButton onClick={() => onAction({ kind: 'raise', txn })}>
                    <Undo2 className="size-3" /> Not received
                </SmallButton>
            )}
            {can.return_record && (
                <SmallButton onClick={() => onAction({ kind: 'record', txn })}>
                    <Undo2 className="size-3" /> Record return
                </SmallButton>
            )}
            {can.return_decide && (
                <div className="flex gap-1.5">
                    <SmallButton
                        onClick={() => onAction({ kind: 'confirm', txn })}
                    >
                        <Check className="size-3" /> Confirm
                    </SmallButton>
                    <SmallButton
                        onClick={() => onAction({ kind: 'reject', txn })}
                    >
                        <X className="size-3" /> Reject
                    </SmallButton>
                </div>
            )}
        </>
    );

    if (!rt) {
        return can.return_raise || can.return_record ? (
            buttons
        ) : (
            <span className="text-tx3">—</span>
        );
    }

    return (
        <div className="flex flex-col items-start gap-1 text-xs">
            <span title={rt.decision_note ?? rt.reason ?? undefined}>
                <ReturnBadge status={rt.status} />
            </span>
            {returned > 0 && (
                <div>
                    Came back <b>{formatPaise(returned)}</b>
                    {returned < txn.amount && (
                        <span className="text-tx3">
                            {' '}
                            of {formatPaise(txn.amount)}
                        </span>
                    )}
                    {returnedTransfers > 1 && (
                        <span className="text-tx3">
                            {' '}
                            · from {returnedTransfers} transfers
                        </span>
                    )}
                </div>
            )}
            {rt.utr && (
                <div className="font-mono text-tx3">
                    {returnedTransfers > 1 ? 'Latest UTR' : 'UTR'} {rt.utr}
                </div>
            )}
            {rt.returned_at && (
                <div className="whitespace-nowrap text-tx3">
                    Came back {formatDateTime(rt.returned_at)}
                </div>
            )}
            {rt.requested_at && (
                <div className="whitespace-nowrap text-tx3">
                    Raised {formatDateTime(rt.requested_at)}
                </div>
            )}
            {rt.decided_at && (
                <div className="whitespace-nowrap text-tx3">
                    {rt.status === 'approved' ? 'Confirmed' : 'Rejected'}{' '}
                    {formatDateTime(rt.decided_at)}
                </div>
            )}
            {rt.status === 'rejected' && rt.decision_note && (
                <div className="max-w-[220px] text-tx2">
                    “{rt.decision_note}”
                </div>
            )}
            {rt.proof_file_id && <ProofLinks fileId={rt.proof_file_id} />}
            {buttons}
        </div>
    );
}

function PayoutSummary({ txn }: { txn: TxnRow }) {
    const customer = txn.customer
        ? [txn.customer.name ?? txn.customer.id, txn.customer.mobile]
              .filter(Boolean)
              .join(' · ')
        : null;

    return (
        <div className="rounded-lg border border-ln p-3 text-[13px]">
            <div className="font-mono text-xs font-medium">{txn.reference}</div>
            <div className="text-xs text-tx3">Order {txn.order_id}</div>
            <div className="mt-1 text-lg font-semibold">
                {formatPaise(txn.amount)}
            </div>
            {(txn.partner || txn.branch) && (
                <div className="text-xs text-tx2">
                    {[
                        txn.partner && `Partner ${txn.partner.name}`,
                        txn.branch && `Branch ${txn.branch.name}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </div>
            )}
            {customer && (
                <div className="text-xs text-tx2">Customer {customer}</div>
            )}
            <div className="text-xs text-tx3">
                {txn.beneficiary
                    ? `${txn.beneficiary.name} · ${txn.beneficiary.masked} · `
                    : ''}
                paid {formatDateTime(txn.decided_at)}
                {txn.bank_utr ? ` · UTR ${txn.bank_utr}` : ''}
            </div>
            {txn.payout_return?.reason && (
                <div className="mt-2 text-xs text-tx2">
                    Raised: “{txn.payout_return.reason}”
                </div>
            )}
        </div>
    );
}

/** Partner (or Admin for it): the customer says the money never arrived. */
function RaiseDialog({
    txn,
    portal,
    onClose,
}: {
    txn: TxnRow;
    portal: UserType;
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const url =
        portal === 'admin'
            ? admin.transactions.return(txn.id).url
            : partner.payouts.return(txn.id).url;

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            width={500}
            title={`Payout ${txn.reference} not received`}
            description="The branch checks its bank. If money came back to it, it confirms how much: that amount is credited back to the partner’s balance the same day. What to do for the customer (pay them, or have them ask again) is up to the partner."
            submitLabel={form.processing ? 'Sending…' : 'Send to the branch'}
            processing={form.processing}
            onSubmit={() =>
                form.post(url, { preserveScroll: true, onSuccess: onClose })
            }
        >
            <PayoutSummary txn={txn} />
            {errors.transaction && (
                <p className="text-xs text-er">{errors.transaction}</p>
            )}
            <Field
                label="What the customer says"
                required
                error={errors.reason}
                hint="E.g. which part didn’t arrive, the date, the customer’s bank."
            >
                <TextArea
                    required
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}

/**
 * The branch (or Admin) confirms a raised return, or the branch records one
 * it found: the amount that came back, the credit's UTR and, if they have it, the proof.
 */
function ConfirmDialog({
    txn,
    portal,
    record,
    onClose,
}: {
    txn: TxnRow;
    portal: UserType;
    record: boolean;
    onClose: () => void;
}) {
    // Transfers that haven't come back yet (a payout may be paid in parts).
    const open = (txn.payments ?? []).filter((part) => part.returned === null);
    const only = open.length === 1 ? open[0] : null;
    const rupees = (paise: number) =>
        (paise / 100).toFixed(2).replace(/\.00$/, '');
    const form = useForm<{
        payout_payment_id: string;
        amount: string;
        utr: string;
        returned_at: string;
        note: string;
        proof: File | null;
    }>({
        payout_payment_id: only?.id ?? '',
        amount: only ? rupees(only.amount) : '',
        utr: '',
        returned_at: '',
        note: '',
        proof: null,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const returnId = txn.payout_return?.id ?? '';
    const url = record
        ? branch.payouts.return(txn.id).url
        : portal === 'admin'
          ? admin.payoutReturns.confirm(returnId).url
          : branch.payoutReturns.confirm(returnId).url;

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            width={500}
            title={
                record
                    ? `Record a return on ${txn.reference}`
                    : `Confirm the return on ${txn.reference}`
            }
            description="Choose the transfer that bounced and enter what actually came back to the branch’s account. It is booked today: the branch is debited that amount and the partner credited. Fees stay as they are. The partner is told by webhook."
            submitLabel={form.processing ? 'Booking…' : 'Confirm and book'}
            processing={form.processing}
            onSubmit={() =>
                form.post(url, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
        >
            <PayoutSummary txn={txn} />
            {(errors.transaction || errors.return) && (
                <p className="text-xs text-er">
                    {errors.transaction ?? errors.return}
                </p>
            )}
            {open.length > 1 && (
                <Field
                    label="Which transfer came back?"
                    required
                    error={errors.payout_payment_id}
                >
                    <SelectInput
                        required
                        value={form.data.payout_payment_id}
                        onChange={(event) => {
                            const part = open.find(
                                (candidate) =>
                                    candidate.id === event.target.value,
                            );
                            form.setData({
                                ...form.data,
                                payout_payment_id: event.target.value,
                                amount: part ? rupees(part.amount) : '',
                            });
                        }}
                    >
                        <option value="">Choose a transfer…</option>
                        {open.map((part) => (
                            <option key={part.id} value={part.id}>
                                {formatPaise(part.amount)} · UTR {part.utr} ·{' '}
                                {formatDateTime(part.paid_at)}
                            </option>
                        ))}
                    </SelectInput>
                </Field>
            )}
            {open.length <= 1 && errors.payout_payment_id && (
                <p className="text-xs text-er">{errors.payout_payment_id}</p>
            )}
            <Field
                label="Amount that came back (₹)"
                required
                error={errors.amount}
                hint="Up to that transfer’s amount."
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
                label="Credit UTR"
                required
                error={errors.utr}
                hint="The UTR of the money that came back to your account, as shown on the bank statement (not the payout’s UTR)."
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
                label="Date it came back"
                required
                error={errors.returned_at}
                hint="As on the bank statement. It is booked today either way."
            >
                <TextInput
                    type="datetime-local"
                    required
                    value={form.data.returned_at}
                    onChange={(event) =>
                        form.setData('returned_at', event.target.value)
                    }
                />
            </Field>
            <Field
                label={
                    portal === 'admin'
                        ? 'Bank proof (image or PDF)'
                        : 'Bank proof (optional, image or PDF)'
                }
                required={portal === 'admin'}
                error={errors.proof}
            >
                <input
                    type="file"
                    required={portal === 'admin'}
                    accept=".jpg,.jpeg,.png,.webp,.pdf"
                    onChange={(event) =>
                        form.setData('proof', event.target.files?.[0] ?? null)
                    }
                    className="text-[13px] file:mr-3 file:h-8 file:rounded-[7px] file:border file:border-ln file:bg-sf file:px-3 file:text-[13px] file:font-medium"
                />
            </Field>
            <Field label="Note (optional)" error={errors.note}>
                <TextArea
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}

/** Nothing came back: the partner sees the note and may raise it again. */
function RejectDialog({
    txn,
    portal,
    onClose,
}: {
    txn: TxnRow;
    portal: UserType;
    onClose: () => void;
}) {
    const form = useForm({ note: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const returnId = txn.payout_return?.id ?? '';
    const url =
        portal === 'admin'
            ? admin.payoutReturns.reject(returnId).url
            : branch.payoutReturns.reject(returnId).url;

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            width={460}
            title={`Reject the return on ${txn.reference}`}
            description="Nothing is booked. The partner sees your note and can raise it again."
            submitLabel={form.processing ? 'Rejecting…' : 'Reject'}
            processing={form.processing}
            onSubmit={() =>
                form.post(url, { preserveScroll: true, onSuccess: onClose })
            }
        >
            <PayoutSummary txn={txn} />
            {errors.return && (
                <p className="text-xs text-er">{errors.return}</p>
            )}
            <Field
                label="Why"
                required
                error={errors.note}
                hint="E.g. the statement shows no credit back for this payout."
            >
                <TextArea
                    required
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}

export function ReturnDialog({
    action,
    portal,
    onClose,
}: {
    action: ReturnAction;
    portal: UserType;
    onClose: () => void;
}) {
    switch (action.kind) {
        case 'raise':
            return (
                <RaiseDialog
                    txn={action.txn}
                    portal={portal}
                    onClose={onClose}
                />
            );
        case 'record':
        case 'confirm':
            return (
                <ConfirmDialog
                    txn={action.txn}
                    portal={portal}
                    record={action.kind === 'record'}
                    onClose={onClose}
                />
            );
        case 'reject':
            return (
                <RejectDialog
                    txn={action.txn}
                    portal={portal}
                    onClose={onClose}
                />
            );
    }
}
