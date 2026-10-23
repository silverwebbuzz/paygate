import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { ProofLinks } from './chargeback';
import type { TxnPayment } from './transaction-drawer';

const LINE_LABELS: Record<string, string> = {
    matched: 'In bank statement',
    reconciled: 'In bank statement',
};

/**
 * A payout's bank transfers (it may be paid in parts): amount, UTR, date
 * and the branch's proof. Partners get no recorder or bank-line details.
 */
export function PayoutPayments({
    payments,
    amount,
}: {
    payments: TxnPayment[];
    amount: number;
}) {
    const paid = payments.reduce((sum, part) => sum + part.amount, 0);

    if (payments.length === 0) {
        return <p className="text-xs text-tx3">No transfer recorded yet.</p>;
    }

    return (
        <div className="flex flex-col gap-2">
            {paid < amount && (
                <div className="rounded-md bg-sf2 px-2.5 py-1.5 text-xs">
                    Paid <b>{formatPaise(paid)}</b> of {formatPaise(amount)} ·{' '}
                    <b>{formatPaise(amount - paid)}</b> left to pay
                </div>
            )}
            {payments.map((part, index) => (
                <div
                    key={part.id}
                    className="flex flex-col gap-1 rounded-lg border border-ln p-2.5 text-xs"
                >
                    <div className="flex items-center justify-between gap-2">
                        <span className="font-medium">
                            Transfer {index + 1} · {formatPaise(part.amount)}
                        </span>
                        {part.returned !== null && (
                            <span className="rounded bg-rvb px-1.5 py-0.5 text-[11px] font-medium text-rv">
                                ↺ {formatPaise(part.returned)} came back
                            </span>
                        )}
                    </div>
                    <div className="font-mono text-tx2">UTR {part.utr}</div>
                    <div className="text-tx3">
                        Sent {formatDateTime(part.paid_at)}
                        {part.recorded_by &&
                            ` · recorded by ${part.recorded_by}`}
                        {part.bank_line &&
                            ` · ${LINE_LABELS[part.bank_line] ?? part.bank_line}`}
                    </div>
                    {part.note && <div className="text-tx2">{part.note}</div>}
                    {part.proof_file_id ? (
                        <ProofLinks fileId={part.proof_file_id} />
                    ) : (
                        <div className="text-tx3">
                            No proof (recorded before proofs were required)
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}
