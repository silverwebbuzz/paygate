import { router } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import adminWebhooks from '@/routes/admin/webhooks';
import { show as fileUrl } from '@/routes/files';
import partnerWebhooks from '@/routes/partner/webhooks';
import type { UserType } from '@/types';
import { PgButton } from './button';
import { Drawer, KeyValues } from './drawer';
import { SimpleTable } from './simple-table';
import { StatusBadge } from './status-badge';

/** Row shape from App\Http\Shared\Transactions\TransactionPresenter::row(). */
export type TxnRow = {
    id: string;
    reference: string;
    direction: string;
    order_id: string;
    partner: { name: string; code: string } | null;
    branch: { name: string; code: string } | null;
    account: {
        label: string;
        bank: string | null;
        number: string | null;
        upi: string | null;
    } | null;
    customer: { id: string; name: string | null; mobile: string | null } | null;
    beneficiary?: { type: string; name: string; masked: string } | null;
    amount: number;
    net: number | null;
    method: string | null;
    status: string;
    customer_utr: string | null;
    bank_utr: string | null;
    reason_code: string | null;
    note: string | null;
    created_at: string | null;
    submitted_at: string | null;
    decided_at: string | null;
    expires_at: string | null;
    can: { decide: boolean; process?: boolean };
};

export type TxnDetail = {
    id: string;
    commission: Partial<
        Record<'partner_rate' | 'branch_rate', string | null> &
            Record<'partner' | 'branch' | 'margin', number | null>
    >;
    timeline: {
        event: string;
        from: string | null;
        to: string | null;
        actor: string;
        reason: string | null;
        at: string;
        data: Record<string, unknown> | null;
    }[];
    proofs: {
        id: string;
        name: string | null;
        mime: string;
        size: number;
        at: string;
    }[];
    ledger:
        | {
              id: string;
              type: string;
              posted_at: string;
              entries: { kind: string; amount: number }[];
          }[]
        | null;
    webhooks:
        | {
              id: string;
              type: string;
              url: string;
              status: string;
              attempts: {
                  no: number;
                  status: number | null;
                  error: string | null;
                  ms: number | null;
                  at: string;
              }[];
              next_attempt_at: string | null;
              can_resend: boolean;
          }[]
        | null;
    audit:
        | {
              action: string;
              actor: string;
              ip: string | null;
              request_id: string | null;
              old: unknown;
              new: unknown;
              at: string;
          }[]
        | null;
};

export const METHOD_LABELS: Record<string, string> = {
    upi: 'UPI',
    qr: 'QR code',
    bank_transfer: 'Bank transfer',
    upi_intent: 'UPI app',
};

const EVENT_LABELS: Record<string, string> = {
    created: 'Pay-in created',
    allocated: 'Account assigned',
    method_changed: 'Customer switched method',
    allocation_released: 'Account capacity released',
    allocation_confirmed: 'Daily usage confirmed',
    proof_submitted: 'Customer submitted payment details',
    held: 'Put on hold',
    approved: 'Approved',
    declined: 'Declined',
    expired: 'Expired',
    cancelled: 'Cancelled',
    // payouts
    assigned: 'Sent to a branch (balance held)',
    reassigned: 'Moved to another branch',
    processing: 'Branch started paying',
    paid: 'Paid',
    failed: 'Could not be paid',
    reservation_released: 'Balance hold released',
    reservation_confirmed: 'Balance hold settled',
    session_closed: 'Customer left the payment page',
};

/** A timeline value as text (event data is loosely typed JSON). */
const text = (value: unknown) =>
    typeof value === 'string' || typeof value === 'number' ? String(value) : '';

const LEDGER_LABELS: Record<string, string> = {
    partner_position: 'Partner position',
    branch_position: 'Branch position',
    platform_margin: 'Platform margin',
    platform_adjustments: 'Adjustments',
    settlement_clearing: 'Settlement clearing',
};

/**
 * Transaction detail drawer (design: header, 3 figures, tabs). What each tab
 * holds depends on the portal: partners never see branch, proof or ledger;
 * branches never see webhooks or the partner's rate.
 */
export function TransactionDrawer({
    txn,
    detail,
    portal,
    onClose,
    actions,
}: {
    txn: TxnRow;
    detail: TxnDetail | null;
    portal: UserType;
    onClose: () => void;
    actions?: ReactNode;
}) {
    const [tab, setTab] = useState('overview');
    const tabs = [
        { key: 'overview', label: 'Overview' },
        { key: 'timeline', label: 'Timeline' },
        ...(portal !== 'partner' && txn.direction === 'payin'
            ? [{ key: 'proof', label: 'Proof' }]
            : []),
        ...(portal === 'admin' ? [{ key: 'ledger', label: 'Ledger' }] : []),
        ...(portal !== 'branch'
            ? [{ key: 'webhooks', label: 'Webhooks' }]
            : []),
        ...(portal === 'admin' ? [{ key: 'audit', label: 'Audit' }] : []),
    ];

    const commissionFigure =
        portal === 'branch'
            ? { label: 'Your commission', value: detail?.commission.branch }
            : portal === 'partner'
              ? { label: 'Fee', value: detail?.commission.partner }
              : { label: 'Platform margin', value: detail?.commission.margin };

    return (
        <Drawer
            open
            onOpenChange={(open) => !open && onClose()}
            kind={
                txn.direction === 'payout'
                    ? 'Payout'
                    : portal === 'branch'
                      ? 'Deposit'
                      : 'Pay-in'
            }
            title={txn.reference}
            status={<StatusBadge status={txn.status} />}
            subtitle={[
                txn.partner && `${txn.partner.name} (${txn.partner.code})`,
                txn.branch?.code,
                `order ${txn.order_id}`,
                `created ${formatDateTime(txn.created_at)}`,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={actions}
            summaries={[
                { label: 'Amount', value: formatPaise(txn.amount) },
                {
                    label:
                        txn.direction === 'payout'
                            ? portal === 'branch'
                                ? 'Owed to you'
                                : 'Charged'
                            : portal === 'branch'
                              ? 'Owed to platform'
                              : 'Net',
                    value: txn.net === null ? '—' : formatPaise(txn.net),
                },
                {
                    label: commissionFigure.label,
                    value:
                        commissionFigure.value === null ||
                        commissionFigure.value === undefined
                            ? '—'
                            : formatPaise(commissionFigure.value),
                    tone:
                        (commissionFigure.value ?? 0) < 0
                            ? 'text-er'
                            : undefined,
                },
            ]}
            tabs={tabs}
            activeTab={tab}
            onTabChange={setTab}
        >
            {!detail ? (
                <div className="py-10 text-center text-xs text-tx3">
                    Loading…
                </div>
            ) : (
                <>
                    {tab === 'overview' && (
                        <Overview txn={txn} detail={detail} portal={portal} />
                    )}
                    {tab === 'timeline' && <Timeline detail={detail} />}
                    {tab === 'proof' && <Proof txn={txn} detail={detail} />}
                    {tab === 'ledger' && <Ledger detail={detail} />}
                    {tab === 'webhooks' && (
                        <Webhooks detail={detail} portal={portal} />
                    )}
                    {tab === 'audit' && <Audit detail={detail} />}
                </>
            )}
        </Drawer>
    );
}

function Overview({
    txn,
    detail,
    portal,
}: {
    txn: TxnRow;
    detail: TxnDetail;
    portal: UserType;
}) {
    const c = detail.commission;

    return (
        <>
            {txn.status === 'rejected' && (
                <div className="rounded-lg bg-erb px-3 py-2.5 text-[12.5px] text-er">
                    Declined: {txn.reason_code?.replaceAll('_', ' ')}
                    {txn.note && ` — ${txn.note}`}
                </div>
            )}
            {txn.status === 'under_review' && txn.note && (
                <div className="rounded-lg bg-hdb px-3 py-2.5 text-[12.5px] text-hd">
                    On hold: {txn.note}
                </div>
            )}
            <Section title="Payment">
                <KeyValues
                    items={[
                        {
                            label: 'Method',
                            value: txn.method
                                ? METHOD_LABELS[txn.method]
                                : null,
                        },
                        {
                            label: 'Customer UTR',
                            value: txn.customer_utr,
                            mono: true,
                        },
                        {
                            label: 'Bank UTR (verified)',
                            value: txn.bank_utr,
                            mono: true,
                        },
                        {
                            label: 'Submitted',
                            value: formatDateTime(txn.submitted_at),
                        },
                        {
                            label: 'Decided',
                            value: formatDateTime(txn.decided_at),
                        },
                        {
                            label: 'Payment page expires',
                            value: formatDateTime(txn.expires_at),
                        },
                    ]}
                />
            </Section>
            {txn.beneficiary && (
                <Section title="Paid to">
                    <KeyValues
                        items={[
                            { label: 'Name', value: txn.beneficiary.name },
                            {
                                label:
                                    txn.beneficiary.type === 'upi'
                                        ? 'UPI'
                                        : 'Bank account',
                                value: txn.beneficiary.masked,
                                mono: true,
                            },
                        ]}
                    />
                </Section>
            )}
            <Section title="Customer">
                <KeyValues
                    items={[
                        {
                            label: 'Customer id',
                            value: txn.customer?.id,
                            mono: true,
                        },
                        { label: 'Name', value: txn.customer?.name },
                        { label: 'Mobile', value: txn.customer?.mobile },
                    ]}
                />
            </Section>
            {txn.account && (
                <Section title="Receiving account">
                    <KeyValues
                        items={[
                            {
                                label: 'Branch',
                                value: txn.branch
                                    ? `${txn.branch.name} (${txn.branch.code})`
                                    : null,
                            },
                            { label: 'Account', value: txn.account.label },
                            {
                                label: 'Bank',
                                value: txn.account.bank
                                    ? `${txn.account.bank} · ${txn.account.number}`
                                    : null,
                            },
                            {
                                label: 'UPI',
                                value: txn.account.upi,
                                mono: true,
                            },
                        ]}
                    />
                </Section>
            )}
            {(c.partner !== undefined || c.branch !== undefined) && (
                <Section title="Commission">
                    <KeyValues
                        items={[
                            ...(portal !== 'branch'
                                ? [
                                      {
                                          label: 'Partner pays',
                                          value:
                                              c.partner == null
                                                  ? '—'
                                                  : `${formatPaise(c.partner)} (${Number(c.partner_rate)}%)`,
                                      },
                                  ]
                                : []),
                            ...(portal !== 'partner'
                                ? [
                                      {
                                          label: 'Branch earns',
                                          value:
                                              c.branch == null
                                                  ? '—'
                                                  : `${formatPaise(c.branch)} (${Number(c.branch_rate)}%)`,
                                      },
                                  ]
                                : []),
                            ...(portal === 'admin'
                                ? [
                                      {
                                          label: 'Platform margin',
                                          value:
                                              c.margin == null
                                                  ? '—'
                                                  : formatPaise(c.margin),
                                      },
                                  ]
                                : []),
                        ]}
                    />
                </Section>
            )}
        </>
    );
}

function Timeline({ detail }: { detail: TxnDetail }) {
    return (
        <ol className="flex flex-col">
            {detail.timeline.map((event, index) => (
                <li key={index} className="flex gap-3">
                    <div className="flex flex-col items-center">
                        <span className="mt-1 size-2.5 rounded-full bg-ac" />
                        {index < detail.timeline.length - 1 && (
                            <span className="w-px flex-1 bg-ln" />
                        )}
                    </div>
                    <div className="flex-1 pb-4">
                        <div className="flex justify-between gap-3">
                            <span className="text-[13px] font-medium">
                                {EVENT_LABELS[event.event] ?? event.event}
                            </span>
                            <span className="text-xs whitespace-nowrap text-tx3">
                                {formatDateTime(event.at)}
                            </span>
                        </div>
                        <div className="text-xs text-tx3">
                            by {event.actor.replace('_', ' ')}
                            {event.reason && ` · ${event.reason}`}
                            {event.data?.utr
                                ? ` · UTR ${text(event.data.utr)}`
                                : ''}
                            {event.data?.bank_utr
                                ? ` · bank UTR ${text(event.data.bank_utr)}`
                                : ''}
                            {event.data?.reason_code
                                ? ` · ${text(event.data.reason_code).replaceAll('_', ' ')}`
                                : ''}
                        </div>
                        {event.data?.possible_duplicate_of ? (
                            <div className="mt-1 rounded-md bg-wnb px-2 py-1 text-xs text-wn">
                                This UTR was also claimed on{' '}
                                {text(event.data.possible_duplicate_of)} — check
                                before approving.
                            </div>
                        ) : null}
                    </div>
                </li>
            ))}
        </ol>
    );
}

function Proof({ txn, detail }: { txn: TxnRow; detail: TxnDetail }) {
    return (
        <>
            <KeyValues
                items={[
                    {
                        label: 'UTR the customer entered',
                        value: txn.customer_utr,
                        mono: true,
                    },
                ]}
            />
            {detail.proofs.length === 0 && (
                <p className="text-xs text-tx3">No screenshot uploaded.</p>
            )}
            {detail.proofs.map((proof) => (
                <div
                    key={proof.id}
                    className="flex flex-col gap-2 rounded-lg border border-ln p-3"
                >
                    {proof.mime.startsWith('image/') && (
                        <img
                            src={fileUrl(proof.id).url}
                            alt="Payment proof"
                            className="max-h-[420px] w-full rounded-md bg-sf2 object-contain"
                        />
                    )}
                    <div className="flex items-center justify-between text-xs text-tx3">
                        <span>
                            {proof.name} · {Math.round(proof.size / 1024)} KB ·{' '}
                            {formatDateTime(proof.at)}
                        </span>
                        <a
                            href={fileUrl(proof.id).url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 font-medium text-ac"
                        >
                            Open full size <ExternalLink className="size-3" />
                        </a>
                    </div>
                </div>
            ))}
        </>
    );
}

function Ledger({ detail }: { detail: TxnDetail }) {
    if (!detail.ledger || detail.ledger.length === 0) {
        return (
            <p className="text-xs text-tx3">
                Nothing booked yet: the ledger is written when the pay-in is
                approved.
            </p>
        );
    }

    return (
        <>
            {detail.ledger.map((journal) => (
                <Section
                    key={journal.id}
                    title={`${journal.type.replace('_', ' ')} · ${formatDateTime(journal.posted_at)}`}
                >
                    <SimpleTable
                        headers={['Account', 'Amount']}
                        rows={[
                            ...journal.entries.map((entry) => [
                                LEDGER_LABELS[entry.kind] ?? entry.kind,
                                <span
                                    key="a"
                                    className={cn(
                                        'font-mono',
                                        entry.amount < 0 && 'text-er',
                                    )}
                                >
                                    {entry.amount > 0 ? '+' : ''}
                                    {formatPaise(entry.amount)}
                                </span>,
                            ]),
                            [
                                <b key="s">Sum</b>,
                                <b key="z" className="font-mono">
                                    {formatPaise(
                                        journal.entries.reduce(
                                            (sum, entry) => sum + entry.amount,
                                            0,
                                        ),
                                    )}
                                </b>,
                            ],
                        ]}
                    />
                </Section>
            ))}
            <p className="text-xs text-tx3">
                Positive = the platform owes the party; negative = the party
                owes the platform.
            </p>
        </>
    );
}

function Webhooks({ detail, portal }: { detail: TxnDetail; portal: UserType }) {
    if (!detail.webhooks || detail.webhooks.length === 0) {
        return (
            <p className="text-xs text-tx3">
                No webhooks: none sent yet, or no webhook URL is set.
            </p>
        );
    }

    const resend = (id: string) =>
        router.post(
            (portal === 'admin' ? adminWebhooks : partnerWebhooks).resend(id)
                .url,
            {},
            { preserveScroll: true, only: ['detail'] },
        );

    return (
        <>
            {detail.webhooks.map((hook) => (
                <div
                    key={hook.id}
                    className="flex flex-col gap-2 rounded-lg border border-ln p-3"
                >
                    <div className="flex items-center justify-between gap-2">
                        <div>
                            <span className="font-mono text-[13px] font-medium">
                                {hook.type}
                            </span>
                            <div className="text-xs break-all text-tx3">
                                {hook.url}
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <StatusBadge
                                status={
                                    hook.status === 'delivered'
                                        ? 'success'
                                        : hook.status === 'failed'
                                          ? 'failed'
                                          : 'processing'
                                }
                                label={hook.status}
                            />
                            {hook.can_resend && (
                                <PgButton
                                    className="h-7 text-xs"
                                    onClick={() => resend(hook.id)}
                                >
                                    Resend
                                </PgButton>
                            )}
                        </div>
                    </div>
                    <SimpleTable
                        empty="Not attempted yet."
                        headers={['#', 'Response', 'Time', 'When']}
                        rows={hook.attempts.map((attempt) => [
                            String(attempt.no),
                            attempt.status ? (
                                <span
                                    key="s"
                                    className={
                                        attempt.status < 300
                                            ? 'text-ok'
                                            : 'text-er'
                                    }
                                >
                                    HTTP {attempt.status}
                                </span>
                            ) : (
                                <span key="e" className="text-er">
                                    {attempt.error}
                                </span>
                            ),
                            attempt.ms === null ? '—' : `${attempt.ms} ms`,
                            formatDateTime(attempt.at),
                        ])}
                    />
                    {hook.next_attempt_at && hook.status === 'retrying' && (
                        <div className="text-xs text-tx3">
                            Next try {formatDateTime(hook.next_attempt_at)}
                        </div>
                    )}
                </div>
            ))}
        </>
    );
}

function Audit({ detail }: { detail: TxnDetail }) {
    if (!detail.audit || detail.audit.length === 0) {
        return <p className="text-xs text-tx3">No manual actions yet.</p>;
    }

    return (
        <div className="flex flex-col gap-2">
            {detail.audit.map((entry, index) => (
                <div
                    key={index}
                    className="rounded-lg border border-ln p-3 text-xs"
                >
                    <div className="flex justify-between">
                        <span>
                            <b>{entry.actor}</b> —{' '}
                            <span className="font-mono">{entry.action}</span>
                        </span>
                        <span className="text-tx3">
                            {formatDateTime(entry.at)}
                        </span>
                    </div>
                    <div className="mt-1 font-mono text-[11px] break-all text-tx3">
                        {JSON.stringify(entry.old)} →{' '}
                        {JSON.stringify(entry.new)}
                    </div>
                    <div className="mt-1 text-tx3">
                        IP {entry.ip ?? '—'} · request {entry.request_id ?? '—'}
                    </div>
                </div>
            ))}
        </div>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-2.5">
            <h3 className="text-[12px] font-semibold tracking-[.04em] text-tx3 uppercase">
                {title}
            </h3>
            {children}
        </section>
    );
}
