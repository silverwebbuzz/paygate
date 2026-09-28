import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, TextInput } from '@/components/pg/field';
import { FormDialog } from '@/components/pg/form-dialog';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import admin from '@/routes/admin';

type Kind = 'chargeback' | 'refund' | 'return';

type Row = {
    id: string;
    reference: string;
    kind: Kind;
    bearer: 'partner' | 'branch' | null;
    amount: number;
    reason: string;
    external_reference: string | null;
    created_by: string;
    created_at: string;
    transaction: {
        id: string;
        reference: string;
        direction: string;
        partner: string;
        branch: string | null;
        status: string;
    };
};

type Candidate = {
    id: string;
    reference: string;
    direction: 'payin' | 'payout';
    status: string;
    amount: number;
    partner: string;
    branch: string | null;
    customer: string | null;
    succeeded_at: string | null;
    partner_commission: number | null;
    branch_commission: number | null;
    platform_margin: number | null;
};

type Props = {
    group: 'refunds' | 'chargebacks';
    items: {
        data: Row[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    totals: Partial<Record<Kind, { count: number; amount: number }>>;
    find: string;
    candidate: Candidate | null;
    can: { create: boolean };
};

const KIND_LABELS: Record<Kind, string> = {
    chargeback: 'Chargeback',
    refund: 'Pay-in refund',
    return: 'Returned payout',
};

/**
 * Refunds (deposits sent back, payouts returned) and Chargebacks (a
 * customer's bank took back a deposit), decided 2026-09-28 (G-20…G-22).
 * Recording one books it at once and tells the partner by webhook.
 */
export default function Reversals({
    group,
    items,
    totals,
    find,
    candidate,
    can,
}: Props) {
    const [recording, setRecording] = useState(find !== '');
    const [reference, setReference] = useState(find);
    const list =
        group === 'chargebacks' ? admin.chargebacks.index : admin.refunds.index;

    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: group === 'chargebacks' ? 'Chargeback' : 'Refund',
            cell: (row) => (
                <div>
                    <div className="font-mono text-xs font-medium">
                        {row.reference}
                    </div>
                    <div className="text-xs text-tx3">
                        {KIND_LABELS[row.kind]}
                    </div>
                </div>
            ),
        },
        {
            key: 'txn',
            header: 'Transaction',
            cell: (row) => (
                <div>
                    <div className="font-mono text-xs">
                        {row.transaction.reference}
                    </div>
                    <div className="text-xs text-tx3">
                        {row.transaction.partner} ↔ {row.transaction.branch}
                    </div>
                </div>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            cell: (row) => <b>{formatPaise(row.amount)}</b>,
        },
        ...(group === 'chargebacks'
            ? [
                  {
                      key: 'bearer',
                      header: 'Borne by',
                      cell: (row: Row) => (
                          <span className="capitalize">{row.bearer}</span>
                      ),
                  },
              ]
            : []),
        {
            key: 'reason',
            header: 'Reason',
            cell: (row) => (
                <div className="max-w-[280px] text-xs">
                    <div>{row.reason}</div>
                    {row.external_reference && (
                        <div className="text-tx3">
                            Ref {row.external_reference}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status now',
            cell: (row) => <StatusBadge status={row.transaction.status} />,
        },
        {
            key: 'when',
            header: 'Recorded',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap">
                    <div>{formatDateTime(row.created_at)}</div>
                    <div className="text-tx3">{row.created_by}</div>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title={group === 'chargebacks' ? 'Chargebacks' : 'Refunds'} />
            <PageHeader
                title={group === 'chargebacks' ? 'Chargebacks' : 'Refunds'}
                description={
                    group === 'chargebacks'
                        ? 'A customer’s bank took back an approved deposit. You choose who bears it: the partner (debited the amount; the branch owes that much less) or the branch (nothing changes). Fees already earned are kept.'
                        : 'A deposit sent back to the customer (the partner is debited the amount; fees kept), or a paid payout that came back (fully reversed: the partner gets amount + fee back).'
                }
                actions={
                    can.create && (
                        <PgButton
                            variant="primary"
                            onClick={() => setRecording(true)}
                        >
                            <Plus className="size-3.5" /> Record{' '}
                            {group === 'chargebacks'
                                ? 'chargeback'
                                : 'refund or return'}
                        </PgButton>
                    )
                }
            />
            <KpiGrid>
                {(
                    Object.entries(totals) as [
                        Kind,
                        { count: number; amount: number },
                    ][]
                ).map(([kind, total]) => (
                    <StatTile
                        key={kind}
                        label={`${KIND_LABELS[kind]}s`}
                        value={`${formatPaise(total.amount, 0)} · ${total.count}`}
                        icon="↺"
                        tone="rv"
                    />
                ))}
            </KpiGrid>
            <Panel className="overflow-hidden">
                <div className="overflow-x-auto">
                    <DataTable
                        columns={columns}
                        rows={items.data}
                        rowKey={(row) => row.id}
                        empty={
                            <EmptyState
                                title={
                                    group === 'chargebacks'
                                        ? 'No chargebacks'
                                        : 'No refunds or returns'
                                }
                            />
                        }
                    />
                </div>
                {(items.prev_page_url || items.next_page_url) && (
                    <div className="flex justify-end gap-2 px-3 py-2.5 text-xs">
                        {items.prev_page_url && (
                            <Link
                                href={items.prev_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                ‹ Newer
                            </Link>
                        )}
                        {items.next_page_url && (
                            <Link
                                href={items.next_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                Older ›
                            </Link>
                        )}
                    </div>
                )}
            </Panel>

            {recording && (
                <RecordDialog
                    group={group}
                    candidate={candidate}
                    reference={reference}
                    onReference={setReference}
                    onFind={() =>
                        router.get(
                            list({ query: { find: reference } }).url,
                            {},
                            { preserveState: true },
                        )
                    }
                    onClose={() => setRecording(false)}
                />
            )}
        </>
    );
}

function RecordDialog({
    group,
    candidate,
    reference,
    onReference,
    onFind,
    onClose,
}: {
    group: Props['group'];
    candidate: Candidate | null;
    reference: string;
    onReference: (value: string) => void;
    onFind: () => void;
    onClose: () => void;
}) {
    const defaultKind: Kind =
        group === 'chargebacks'
            ? 'chargeback'
            : candidate?.direction === 'payout'
              ? 'return'
              : 'refund';
    const form = useForm({
        transaction_id: candidate?.id ?? '',
        kind: defaultKind,
        bearer: 'partner' as 'partner' | 'branch',
        reason: '',
        external_reference: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const usable =
        candidate !== null &&
        candidate.status === 'success' &&
        (group === 'refunds' || candidate.direction === 'payin');
    const kind: Kind =
        group === 'chargebacks'
            ? 'chargeback'
            : candidate?.direction === 'payout'
              ? 'return'
              : 'refund';

    const effect =
        candidate === null
            ? ''
            : kind === 'return'
              ? `The partner gets back ${formatPaise(candidate.amount + (candidate.partner_commission ?? 0))} (amount + fee); the branch owes back ${formatPaise(candidate.amount + (candidate.branch_commission ?? 0))}; the margin of ${formatPaise(candidate.platform_margin)} is reversed.`
              : kind === 'refund' || form.data.bearer === 'partner'
                ? `The partner is debited ${formatPaise(candidate.amount)}; the branch owes ${formatPaise(candidate.amount)} less. Fees stay earned.`
                : 'Nothing is booked: the branch absorbs the loss. The partner keeps its credit.';

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            width={520}
            title={
                group === 'chargebacks'
                    ? 'Record a chargeback'
                    : 'Record a refund or returned payout'
            }
            description="Booked at once, can’t be undone, and the partner is told by webhook."
            submitLabel={form.processing ? 'Recording…' : 'Record'}
            processing={form.processing || !usable}
            onSubmit={() => {
                form.transform((data) => ({
                    ...data,
                    transaction_id: candidate?.id ?? '',
                    kind,
                }));
                form.post(admin.reversals.store().url, { onSuccess: onClose });
            }}
        >
            <div className="flex items-end gap-2">
                <Field label="Transaction id" error={errors.transaction}>
                    <TextInput
                        className="font-mono"
                        placeholder="PI… or PO…"
                        value={reference}
                        onChange={(event) => onReference(event.target.value)}
                    />
                </Field>
                <PgButton onClick={onFind} className="h-10">
                    <Search className="size-3.5" /> Find
                </PgButton>
            </div>
            {candidate && (
                <div className="rounded-lg border border-ln p-3 text-[13px]">
                    <div className="flex items-center justify-between">
                        <span className="font-mono text-xs font-medium">
                            {candidate.reference}
                        </span>
                        <StatusBadge status={candidate.status} />
                    </div>
                    <div className="mt-1 text-lg font-semibold">
                        {formatPaise(candidate.amount)}
                    </div>
                    <div className="text-xs text-tx3">
                        {candidate.direction === 'payin' ? 'Pay-in' : 'Payout'}{' '}
                        · {candidate.partner} ↔ {candidate.branch} · succeeded{' '}
                        {formatDateTime(candidate.succeeded_at)}
                    </div>
                    {!usable && (
                        <div className="mt-2 text-xs text-er">
                            {candidate.status !== 'success'
                                ? 'Only a successful transaction can be reversed.'
                                : 'A chargeback applies to deposits only.'}
                        </div>
                    )}
                </div>
            )}
            {searchedButMissing(reference, candidate) && (
                <p className="text-xs text-tx3">No transaction with this id.</p>
            )}
            {usable && (
                <>
                    {kind === 'chargeback' && (
                        <Field label="Who bears it" error={errors.bearer}>
                            <Segmented
                                options={[
                                    { value: 'partner', label: 'The partner' },
                                    { value: 'branch', label: 'The branch' },
                                ]}
                                value={form.data.bearer}
                                onChange={(bearer) =>
                                    form.setData('bearer', bearer)
                                }
                            />
                        </Field>
                    )}
                    <div className="rounded-lg bg-sf2 px-3 py-2.5 text-[12.5px] text-tx2">
                        {effect}
                    </div>
                    <Field label="Reason" error={errors.reason}>
                        <TextInput
                            required
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Bank / notice reference (optional)">
                        <TextInput
                            value={form.data.external_reference}
                            onChange={(event) =>
                                form.setData(
                                    'external_reference',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                </>
            )}
        </FormDialog>
    );
}

function searchedButMissing(
    reference: string,
    candidate: Candidate | null,
): boolean {
    const params = new URLSearchParams(
        typeof window === 'undefined' ? '' : window.location.search,
    );

    return (
        candidate === null &&
        params.get('find') !== null &&
        params.get('find') === reference
    );
}
