import { formatDate } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import { StatusBadge } from './status-badge';

/** Shapes from App\Http\Shared\Reconciliation\StatementPresenter. */
export type LineTxn = {
    id: string;
    reference: string;
    direction: 'payin' | 'payout';
    amount: number;
    status: string;
    partner: string;
    branch: string | null;
    customer_utr: string | null;
    bank_utr: string | null;
    decided_by: string | null;
    created_at: string | null;
    submitted_at: string | null;
    decided_at: string | null;
    can_decide: boolean;
};

export type CaseSummary = {
    id: string;
    reference: string;
    type: string;
    type_label: string;
    status: string; // open / resolved
    resolution: string | null;
    resolution_label: string | null;
    notes: string | null;
    late: boolean;
};

export type StatementLine = {
    id: string;
    value_date: string;
    direction: 'credit' | 'debit';
    amount: number;
    utr: string | null;
    description: string | null;
    status: string;
    display: 'pending' | 'hold' | 'approved' | 'unsettled' | 'ignored';
    account: {
        id: string;
        label: string;
        bank: string | null;
        number: string | null;
        upi: string | null;
    };
    branch: { code: string; name: string };
    source: 'manual' | 'import';
    entered_by: string | null;
    file: string | null;
    created_at: string;
    matched_at: string | null;
    transaction: LineTxn | null;
    linked: boolean;
    branch_match: 'matched' | 'mismatch' | 'none';
    case: CaseSummary | null;
};

export const DISPLAY_LABELS: Record<StatementLine['display'], string> = {
    pending: 'Pending',
    hold: 'Payment hold',
    approved: 'Approved',
    unsettled: 'Unsettled',
    ignored: 'Closed',
};

export const MATCH_LABELS: Record<StatementLine['branch_match'], string> = {
    matched: 'Matched',
    mismatch: 'Mismatch',
    none: 'Not matched',
};

/** The line's amount, signed and coloured: + credit, − debit. */
export function LineAmount({
    line,
    className,
}: {
    line: Pick<StatementLine, 'direction' | 'amount'>;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'font-medium whitespace-nowrap',
                line.direction === 'credit' ? 'text-ok' : 'text-er',
                className,
            )}
        >
            {line.direction === 'credit' ? '+ ' : '− '}
            {formatPaise(line.amount)}
        </span>
    );
}

/**
 * Design "compare": the transaction's branch against the bank line's branch,
 * with the result in the middle.
 */
export function CompareBlock({
    transactionBranch,
    transactionSub,
    lineBranch,
    lineSub,
    state,
}: {
    transactionBranch: string | null;
    transactionSub: string;
    lineBranch: string;
    lineSub: string;
    state: StatementLine['branch_match'];
}) {
    const tone = {
        matched: 'border-ok bg-okb text-ok',
        mismatch: 'border-er bg-erb text-er',
        none: 'border-hd bg-hdb text-hd',
    }[state];

    return (
        <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
            <div className="rounded-lg border border-ln p-3">
                <div className="text-[11.5px] text-tx3">Transaction branch</div>
                <div className="font-medium">{transactionBranch ?? '—'}</div>
                <div className="text-xs text-tx3">{transactionSub}</div>
            </div>
            <div
                className={cn(
                    'flex flex-col items-center rounded-lg border px-2.5 py-1.5 text-[11px] font-semibold tracking-[.04em]',
                    tone,
                )}
            >
                <span className="text-base leading-none">
                    {{ matched: '✓', mismatch: '≠', none: '?' }[state]}
                </span>
                {
                    {
                        matched: 'MATCHED',
                        mismatch: 'MISMATCH',
                        none: 'NO TRANSACTION',
                    }[state]
                }
            </div>
            <div className="rounded-lg border border-ln p-3">
                <div className="text-[11.5px] text-tx3">Bank entry branch</div>
                <div className="font-medium">{lineBranch}</div>
                <div className="text-xs text-tx3">{lineSub}</div>
            </div>
        </div>
    );
}

/** One bank line in a compact box (cases, candidates). */
export function LineSummary({ line }: { line: StatementLine }) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border border-ln p-3 text-[12.5px]">
            <div className="flex items-center justify-between gap-2">
                <LineAmount line={line} className="text-base" />
                <StatusBadge
                    status={line.display}
                    label={DISPLAY_LABELS[line.display]}
                />
            </div>
            <div className="font-mono text-xs">{line.utr ?? 'no UTR'}</div>
            <div className="truncate text-tx3">{line.description ?? '—'}</div>
            <div className="text-xs text-tx3">
                {formatDate(line.value_date)} · {line.branch.code} ·{' '}
                {line.account.label}
                {line.account.number ? ` · ${line.account.number}` : ''}
            </div>
        </div>
    );
}
