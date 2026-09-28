import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { ConfirmDialog } from '@/components/pg/confirm-dialog';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, SelectInput, TextArea, TextInput } from '@/components/pg/field';
import { ViewTabs } from '@/components/pg/filter-bar';
import { FormDialog } from '@/components/pg/form-dialog';
import { PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import adminAdjustments from '@/routes/admin/adjustments';
import adminSettlements from '@/routes/admin/settlements';

type Tab = 'pending' | 'approved' | 'rejected';
type Party = { id: string; code: string; name: string };

type Row = {
    id: string;
    reference: string;
    type: 'topup' | 'correction' | 'goodwill';
    partner: { code: string; name: string };
    branch: { code: string; name: string };
    side: 'partner' | 'branch';
    amount: number;
    reason: string;
    case: string | null;
    status: Tab;
    requested_by: string;
    requested_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    can_decide: boolean;
    own: boolean;
};

type Props = {
    tab: Tab;
    items: {
        data: Row[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    counts: Record<Tab, number>;
    partners: Party[];
    branches: Party[];
    mappings: { partner_id: string; branch_id: string }[];
    prefill_case: {
        id: string;
        reference: string;
        branch_id: string;
        transaction_id: string | null;
    } | null;
    can: { create: boolean };
};

const TYPES: Record<Row['type'], [string, string]> = {
    topup: [
        'Partner top-up',
        'The partner paid PayGate outside the platform to fund payouts at a branch. Adds to the partner’s balance there.',
    ],
    correction: [
        'Correction',
        'Fixes a wrong position (e.g. a matching or booking error). In either direction; the difference is booked to platform adjustments.',
    ],
    goodwill: [
        'Goodwill',
        'A credit PayGate gives a party. Booked to platform adjustments.',
    ],
};

/**
 * Adjustments: one admin requests, a different admin approves or rejects
 * (maker–checker). Only approved adjustments change a balance.
 */
export default function Adjustments(props: Props) {
    const { tab, items, counts, can } = props;
    const [creating, setCreating] = useState(props.prefill_case !== null);
    const [deciding, setDeciding] = useState<null | {
        row: Row;
        approve: boolean;
    }>(null);

    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: 'Adjustment',
            cell: (row) => (
                <div>
                    <div className="font-mono text-xs font-medium">
                        {row.reference}
                    </div>
                    <div className="text-xs text-tx3">
                        {TYPES[row.type][0]}
                        {row.case ? ` · case ${row.case}` : ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'pair',
            header: 'Whose position',
            cell: (row) => (
                <div className="text-xs">
                    <div className="font-medium">
                        {row.side === 'partner'
                            ? `${row.partner.name} (${row.partner.code})`
                            : `${row.branch.name} (${row.branch.code})`}
                    </div>
                    <div className="text-tx3">
                        pair {row.partner.code} ↔ {row.branch.code}
                    </div>
                </div>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap">
                    <div
                        className={cn(
                            'text-[13px] font-medium',
                            row.amount < 0 ? 'text-er' : 'text-ok',
                        )}
                    >
                        {row.amount < 0 ? '−' : '+'}
                        {formatPaise(Math.abs(row.amount))}
                    </div>
                    <div className="text-tx3">
                        {row.amount < 0
                            ? `${row.side} owes more`
                            : `PayGate owes ${row.side} more`}
                    </div>
                </div>
            ),
        },
        {
            key: 'reason',
            header: 'Reason',
            cell: (row) => (
                <div className="max-w-[280px] text-xs">
                    <div>{row.reason}</div>
                    {row.decision_note && (
                        <div className="mt-0.5 text-tx3">
                            Checker: {row.decision_note}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'who',
            header: 'Requested · Decided',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap">
                    <div>
                        {row.requested_by} · {formatDateTime(row.requested_at)}
                    </div>
                    <div className="text-tx3">
                        {row.decided_by
                            ? `${row.decided_by} · ${formatDateTime(row.decided_at)}`
                            : 'Waiting for a second admin'}
                    </div>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) =>
                row.status === 'pending' && row.can_decide ? (
                    <div className="flex gap-1.5">
                        <PgButton
                            variant="danger"
                            className="h-7 px-2 text-xs"
                            onClick={() => setDeciding({ row, approve: false })}
                        >
                            Reject
                        </PgButton>
                        <PgButton
                            variant="primary"
                            className="h-7 px-2 text-xs"
                            onClick={() => setDeciding({ row, approve: true })}
                        >
                            Approve
                        </PgButton>
                    </div>
                ) : (
                    <div className="flex flex-col items-start gap-1">
                        <StatusBadge
                            status={
                                row.status === 'rejected'
                                    ? 'declined'
                                    : row.status
                            }
                            label={
                                row.status === 'pending'
                                    ? 'Awaiting approval'
                                    : undefined
                            }
                        />
                        {row.status === 'pending' && row.own && (
                            <span className="text-xs text-tx3">
                                You requested it
                            </span>
                        )}
                    </div>
                ),
        },
    ];

    return (
        <>
            <Head title="Adjustments" />
            <PageHeader
                title="Adjustments"
                description="Partner top-ups, corrections and goodwill. One admin requests with a reason; a different admin approves before any balance changes. They appear in the next settlement."
                actions={
                    <>
                        <Link
                            href={adminSettlements.index().url}
                            className="inline-flex h-8 items-center rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                        >
                            Settlements
                        </Link>
                        {can.create && (
                            <PgButton
                                variant="primary"
                                onClick={() => setCreating(true)}
                            >
                                <Plus className="size-3.5" /> New adjustment
                            </PgButton>
                        )}
                    </>
                }
            />

            <Panel className="overflow-hidden">
                <ViewTabs
                    views={[
                        {
                            key: 'pending',
                            label: 'Awaiting approval',
                            count: counts.pending,
                        },
                        {
                            key: 'approved',
                            label: 'Approved',
                            count: counts.approved,
                        },
                        {
                            key: 'rejected',
                            label: 'Rejected',
                            count: counts.rejected,
                        },
                    ]}
                    active={tab}
                    onChange={(key) =>
                        router.get(
                            adminAdjustments.index({ query: { tab: key } }).url,
                            {},
                            { preserveState: true },
                        )
                    }
                />
                <DataTable
                    columns={columns}
                    rows={items.data}
                    rowKey={(row) => row.id}
                    empty={
                        <EmptyState
                            title={
                                tab === 'pending'
                                    ? 'Nothing waiting for approval'
                                    : 'No adjustments'
                            }
                        />
                    }
                />
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

            {creating && (
                <NewAdjustment {...props} onClose={() => setCreating(false)} />
            )}

            {deciding && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setDeciding(null)}
                    title={`${deciding.approve ? 'Approve' : 'Reject'} ${deciding.row.reference}?`}
                    description={
                        deciding.approve
                            ? `${deciding.row.amount < 0 ? '−' : '+'}${formatPaise(Math.abs(deciding.row.amount))} on the ${deciding.row.side} position of ${deciding.row.partner.code} ↔ ${deciding.row.branch.code} is booked now. This can’t be undone; a mistake needs a new adjustment.`
                            : 'Nothing is booked. The person who requested it sees your note.'
                    }
                    confirmLabel={deciding.approve ? 'Approve' : 'Reject'}
                    tone={deciding.approve ? 'primary' : 'danger'}
                    input={{
                        label: deciding.approve
                            ? 'Note (optional)'
                            : 'Why (required)',
                        required: !deciding.approve,
                    }}
                    onConfirm={(note) =>
                        router.post(
                            (deciding.approve
                                ? adminAdjustments.approve
                                : adminAdjustments.reject)(deciding.row.id).url,
                            { note },
                            {
                                preserveScroll: true,
                                onFinish: () => setDeciding(null),
                            },
                        )
                    }
                />
            )}
        </>
    );
}

function NewAdjustment({
    partners,
    branches,
    mappings,
    prefill_case,
    onClose,
}: Props & { onClose: () => void }) {
    const form = useForm({
        type: 'topup' as Row['type'],
        partner_id: '',
        branch_id: prefill_case?.branch_id ?? '',
        side: 'partner' as Row['side'],
        sign: 'credit' as 'credit' | 'debit',
        amount: '',
        reason: '',
        case: prefill_case?.reference ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const mappedBranches = branches.filter((branch) =>
        mappings.some(
            (mapping) =>
                mapping.partner_id === form.data.partner_id &&
                mapping.branch_id === branch.id,
        ),
    );
    const fixed = form.data.type !== 'correction';

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            width={520}
            title="New adjustment"
            description="It changes nothing until a different admin approves it."
            submitLabel="Request adjustment"
            processing={form.processing}
            onSubmit={() =>
                form.post(adminAdjustments.store().url, { onSuccess: onClose })
            }
        >
            <Field label="Type" hint={TYPES[form.data.type][1]}>
                <SelectInput
                    value={form.data.type}
                    onChange={(event) => {
                        const type = event.target.value as Row['type'];
                        form.setData({
                            ...form.data,
                            type,
                            side: type === 'topup' ? 'partner' : form.data.side,
                            sign:
                                type === 'correction'
                                    ? form.data.sign
                                    : 'credit',
                        });
                    }}
                >
                    {Object.entries(TYPES).map(([key, [label]]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </SelectInput>
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Partner" error={errors.partner_id}>
                    <SelectInput
                        required
                        value={form.data.partner_id}
                        onChange={(event) =>
                            form.setData({
                                ...form.data,
                                partner_id: event.target.value,
                                branch_id: '',
                            })
                        }
                    >
                        <option value="">Choose…</option>
                        {partners.map((partner) => (
                            <option key={partner.id} value={partner.id}>
                                {partner.code} · {partner.name}
                            </option>
                        ))}
                    </SelectInput>
                </Field>
                <Field
                    label="Branch"
                    hint="Mapped to the partner"
                    error={errors.branch_id}
                >
                    <SelectInput
                        required
                        value={form.data.branch_id}
                        onChange={(event) =>
                            form.setData('branch_id', event.target.value)
                        }
                    >
                        <option value="">Choose…</option>
                        {mappedBranches.map((branch) => (
                            <option key={branch.id} value={branch.id}>
                                {branch.code} · {branch.name}
                            </option>
                        ))}
                    </SelectInput>
                </Field>
            </div>
            {form.data.type !== 'topup' && (
                <Field label="Whose position changes">
                    <Segmented
                        options={[
                            { value: 'partner', label: 'Partner' },
                            { value: 'branch', label: 'Branch' },
                        ]}
                        value={form.data.side}
                        onChange={(side) => form.setData('side', side)}
                    />
                </Field>
            )}
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Direction">
                    <SelectInput
                        disabled={fixed}
                        value={form.data.sign}
                        onChange={(event) =>
                            form.setData(
                                'sign',
                                event.target.value as 'credit' | 'debit',
                            )
                        }
                    >
                        <option value="credit">
                            PayGate owes the {form.data.side} more
                        </option>
                        <option value="debit">
                            The {form.data.side} owes PayGate more
                        </option>
                    </SelectInput>
                </Field>
                <Field label="Amount ₹" error={errors.amount}>
                    <TextInput
                        required
                        inputMode="decimal"
                        placeholder="0.00"
                        value={form.data.amount}
                        onChange={(event) =>
                            form.setData('amount', event.target.value)
                        }
                    />
                </Field>
            </div>
            <Field
                label="Reason"
                hint="Shown to the approver and in the audit log, e.g. the bank reference of a top-up."
                error={errors.reason}
            >
                <TextArea
                    required
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                />
            </Field>
            <Field
                label="Unsettled case (optional)"
                hint="Approving the adjustment closes this case as adjusted."
                error={errors.case}
            >
                <TextInput
                    className="font-mono"
                    placeholder="RC…"
                    value={form.data.case}
                    onChange={(event) =>
                        form.setData('case', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}
