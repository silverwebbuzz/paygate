import { Head, Link, router, useForm } from '@inertiajs/react';
import { Calculator, Settings2, SlidersHorizontal } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { PgButton } from '@/components/pg/button';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { Drawer, KeyValues } from '@/components/pg/drawer';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { ViewTabs } from '@/components/pg/filter-bar';
import { FormDialog } from '@/components/pg/form-dialog';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { SimpleTable } from '@/components/pg/simple-table';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import adminAdjustments from '@/routes/admin/adjustments';
import adminSettlements from '@/routes/admin/settlements';
import branchSettlements from '@/routes/branch/settlements';
import partnerSettlements from '@/routes/partner/settlements';
import type { UserType } from '@/types';

type Figures = {
    opening: number;
    gross_payin: number;
    gross_payout: number;
    partner_commission: number | null;
    branch_commission: number | null;
    platform_margin: number | null;
    adjustments: number;
    settlements: number;
    closing: number;
};

type Direction = 'party_to_platform' | 'platform_to_party' | 'none';

type Row = {
    id: string;
    reference: string;
    party_type: 'partner' | 'branch';
    party: { id: string; code: string; name: string };
    run_type: 'daily' | 'on_demand';
    period_start: string;
    period_end: string;
    figures: Figures;
    net_amount: number;
    direction: Direction;
    settled_amount: number;
    status: string;
    carried_forward: boolean;
    can_pay: boolean;
    calculated_at: string;
    calculated_by: string;
};

type Line = {
    id: string;
    counterpart: { code: string; name: string };
    figures: Figures;
    net_amount: number;
    direction: Direction;
    settled_amount: number;
    remaining: number;
};

type Detail = {
    id: string;
    notes: string | null;
    lines: Line[];
    payments: {
        id: string;
        line_id: string | null;
        amount: number;
        direction: Direction;
        reference: string | null;
        paid_at: string;
        recorded_by: string | null;
        recorded_at: string;
    }[];
};

type Party = { id: string; code: string; name: string };

type Props = {
    portal: UserType;
    tab: 'open' | 'settled' | 'all';
    items: {
        data: Row[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { party: 'partner' | 'branch' | null; id: string | null };
    counts: Record<'open' | 'settled' | 'all', number>;
    kpis: { to_receive: number; to_pay: number; paid_today: number | null };
    cutoff: { timezone: string; time: string };
    parties: Record<'partner' | 'branch', Party[]> | null;
    can: { calculate: boolean; adjustments: boolean; settings: boolean };
    selected: string | null;
    detail?: Detail | null;
};

const LIST = {
    admin: adminSettlements.index,
    branch: branchSettlements.index,
    partner: partnerSettlements.index,
};

/**
 * Who pays whom, in words, from the viewer's side (Admin: the party's name;
 * the party itself: "you").
 */
function directionText(
    direction: Direction,
    portal: UserType,
    party: string,
): string {
    if (direction === 'none') return 'Nothing to settle';

    if (portal === 'admin') {
        return direction === 'party_to_platform'
            ? `${party} pays PayGate`
            : `PayGate pays ${party}`;
    }

    return direction === 'party_to_platform'
        ? 'You pay PayGate'
        : 'PayGate pays you';
}

/** A signed position: + the platform owes the party, − the party owes. */
function Signed({ value }: { value: number }) {
    return (
        <span
            className={cn(
                'whitespace-nowrap',
                value < 0 ? 'text-er' : value > 0 ? 'text-ok' : 'text-tx3',
            )}
        >
            {value > 0 ? '+' : value < 0 ? '−' : ''}
            {formatPaise(Math.abs(value))}
        </span>
    );
}

/**
 * Settlement: what each party and PayGate owe each other per period, with
 * payments recorded by Admin. Partners and branches see their own.
 */
export default function Settlements(props: Props) {
    const { portal, tab, items, filters, counts, kpis, cutoff, parties, can } =
        props;
    const [openId, setOpenId] = useState<string | null>(props.selected);
    const [calculating, setCalculating] = useState(false);
    const [paying, setPaying] = useState<Row | null>(null);
    const open = items.data.find((row) => row.id === openId) ?? null;

    const visit = (next: Record<string, string | null>) =>
        router.get(
            LIST[portal]({
                query: Object.fromEntries(
                    Object.entries({ tab, ...filters, ...next }).filter(
                        ([, value]) => value,
                    ),
                ) as Record<string, string>,
            }).url,
            {},
            { preserveState: true, replace: true },
        );

    useEffect(() => {
        if (props.selected && !props.detail)
            router.reload({ only: ['detail'] });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const openRow = (id: string) => {
        setOpenId(id);
        router.reload({
            only: ['detail', 'selected'],
            data: { settlement: id },
        });
    };

    const commissionKey =
        portal === 'branch' ? 'branch_commission' : 'partner_commission';

    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: 'Settlement',
            cell: (row) => (
                <div>
                    <div className="font-mono text-xs font-medium">
                        {row.reference}
                    </div>
                    <div className="text-xs text-tx3">
                        {row.run_type === 'daily' ? 'Daily' : 'On demand'}
                    </div>
                </div>
            ),
        },
        ...(portal === 'admin'
            ? [
                  {
                      key: 'party',
                      header: 'Party',
                      cell: (row: Row) => (
                          <div className="whitespace-nowrap">
                              <div className="font-medium">
                                  {row.party.name}
                              </div>
                              <div className="text-xs text-tx3">
                                  {row.party_type === 'partner'
                                      ? 'Partner'
                                      : 'Branch'}{' '}
                                  · {row.party.code}
                              </div>
                          </div>
                      ),
                  },
              ]
            : []),
        {
            key: 'period',
            header: 'Period',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap">
                    <div>{formatDateTime(row.period_start)}</div>
                    <div className="text-tx3">
                        to {formatDateTime(row.period_end)}
                    </div>
                </div>
            ),
        },
        {
            key: 'in',
            header: 'Pay-ins · Payouts',
            align: 'right',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap">
                    <div>{formatPaise(row.figures.gross_payin)}</div>
                    <div className="text-tx3">
                        {formatPaise(row.figures.gross_payout)}
                    </div>
                </div>
            ),
        },
        {
            key: 'commission',
            header: portal === 'admin' ? 'Commission · Margin' : 'Commission',
            align: 'right',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap">
                    <div>
                        {formatPaise(
                            row.figures[
                                row.party_type === 'branch'
                                    ? 'branch_commission'
                                    : commissionKey
                            ],
                        )}
                    </div>
                    {portal === 'admin' && (
                        <div className="text-tx3">
                            {formatPaise(row.figures.platform_margin)}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'closing',
            header: 'Position at the end',
            align: 'right',
            cell: (row) => <Signed value={row.figures.closing} />,
        },
        {
            key: 'net',
            header: 'To settle',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap">
                    <div className="font-medium">
                        {formatPaise(row.net_amount)}
                    </div>
                    <div className="text-tx3">
                        {directionText(row.direction, portal, row.party.code)}
                    </div>
                    {row.settled_amount > 0 && (
                        <div className="text-ok">
                            paid {formatPaise(row.settled_amount)}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => (
                <div className="flex flex-col items-start gap-1.5">
                    {row.carried_forward ? (
                        <StatusBadge
                            status="unsettled"
                            label="Carried forward"
                        />
                    ) : (
                        <StatusBadge status={row.status} />
                    )}
                    {row.can_pay && (
                        <PgButton
                            variant="primary"
                            className="h-7 px-2 text-xs"
                            onClick={(event) => {
                                event.stopPropagation();
                                openRow(row.id);
                                setPaying(row);
                            }}
                        >
                            Record payment
                        </PgButton>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title={portal === 'partner' ? 'Settlements' : 'Settlement'} />
            <PageHeader
                title={portal === 'partner' ? 'Settlements' : 'Settlement'}
                description={
                    portal === 'admin'
                        ? 'What each partner and branch and PayGate owe each other, per period. Money moves outside PayGate; record it here. Unpaid amounts carry forward.'
                        : 'What you and PayGate owe each other, per period. Payments are made outside PayGate and recorded by PayGate; anything unpaid carries forward.'
                }
                actions={
                    portal === 'admin' && (
                        <>
                            {can.settings && (
                                <Link
                                    href={admin.settings.index().url}
                                    className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                                    title="Change the daily cut-off"
                                >
                                    <Settings2 className="size-3.5" /> Daily at{' '}
                                    {cutoff.time} ({cutoff.timezone})
                                </Link>
                            )}
                            {can.adjustments && (
                                <Link
                                    href={adminAdjustments.index().url}
                                    className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                                >
                                    <SlidersHorizontal className="size-3.5" />{' '}
                                    Adjustments
                                </Link>
                            )}
                            {can.calculate && (
                                <PgButton
                                    variant="primary"
                                    onClick={() => setCalculating(true)}
                                >
                                    <Calculator className="size-3.5" />{' '}
                                    Calculate now
                                </PgButton>
                            )}
                        </>
                    )
                }
            />

            <KpiGrid>
                <StatTile
                    label={portal === 'admin' ? 'To receive' : 'You owe'}
                    value={formatPaise(kpis.to_receive)}
                    icon="↓"
                    tone="wn"
                />
                <StatTile
                    label={portal === 'admin' ? 'To pay' : 'Owed to you'}
                    value={formatPaise(kpis.to_pay)}
                    icon="↑"
                    tone="in"
                />
                <StatTile
                    label="Open settlements"
                    value={String(counts.open)}
                    icon="◷"
                    tone="hd"
                />
                {kpis.paid_today !== null && (
                    <StatTile
                        label="Recorded today"
                        value={formatPaise(kpis.paid_today)}
                        icon="✓"
                        tone="ok"
                    />
                )}
            </KpiGrid>

            <Panel className="overflow-hidden">
                <ViewTabs
                    views={[
                        { key: 'open', label: 'Open', count: counts.open },
                        {
                            key: 'settled',
                            label: 'Settled',
                            count: counts.settled,
                        },
                        { key: 'all', label: 'All', count: counts.all },
                    ]}
                    active={tab}
                    onChange={(key) => visit({ tab: key })}
                />
                {portal === 'admin' && parties && (
                    <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                        <SelectInput
                            className="h-[30px] w-[210px] text-[12.5px]"
                            value={filters.party ?? ''}
                            onChange={(event) =>
                                visit({
                                    party: event.target.value || null,
                                    id: null,
                                })
                            }
                        >
                            <option value="">Partners and branches</option>
                            <option value="partner">Partners</option>
                            <option value="branch">Branches</option>
                        </SelectInput>
                        {filters.party && (
                            <SelectInput
                                className="h-[30px] w-[230px] text-[12.5px]"
                                value={filters.id ?? ''}
                                onChange={(event) =>
                                    visit({ id: event.target.value || null })
                                }
                            >
                                <option value="">All</option>
                                {parties[filters.party].map((party) => (
                                    <option key={party.id} value={party.id}>
                                        {party.code} · {party.name}
                                    </option>
                                ))}
                            </SelectInput>
                        )}
                    </div>
                )}
                <div className="overflow-x-auto">
                    <DataTable
                        columns={columns}
                        rows={items.data}
                        rowKey={(row) => row.id}
                        onRowClick={(row) => openRow(row.id)}
                        empty={
                            <EmptyState
                                title={
                                    tab === 'open'
                                        ? 'Nothing open'
                                        : 'No settlements'
                                }
                                description={`Settlements are calculated every day at ${cutoff.time} (${cutoff.timezone})${portal === 'admin' ? ', or now with “Calculate now”' : ''}.`}
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

            {open && (
                <SettlementDrawer
                    row={open}
                    portal={portal}
                    detail={
                        props.detail && props.detail.id === open.id
                            ? props.detail
                            : null
                    }
                    onPay={() => setPaying(open)}
                    onClose={() => setOpenId(null)}
                />
            )}

            {paying && props.detail && props.detail.id === paying.id && (
                <PaymentDialog
                    row={paying}
                    detail={props.detail}
                    onClose={() => setPaying(null)}
                />
            )}

            {calculating && parties && (
                <CalculateDialog
                    parties={parties}
                    onClose={() => setCalculating(false)}
                />
            )}
        </>
    );
}

function SettlementDrawer({
    row,
    portal,
    detail,
    onPay,
    onClose,
}: {
    row: Row;
    portal: UserType;
    detail: Detail | null;
    onPay: () => void;
    onClose: () => void;
}) {
    const [tab, setTab] = useState('overview');
    const f = row.figures;
    const lineName = (id: string | null) =>
        detail?.lines.find((line) => line.id === id)?.counterpart.code ?? '—';

    return (
        <Drawer
            open
            onOpenChange={(next) => !next && onClose()}
            kind={`${row.party_type === 'partner' ? 'Partner' : 'Branch'} settlement`}
            title={row.reference}
            monoTitle
            status={
                row.carried_forward ? (
                    <StatusBadge status="unsettled" label="Carried forward" />
                ) : (
                    <StatusBadge status={row.status} />
                )
            }
            subtitle={`${row.party.name} (${row.party.code}) · ${formatDateTime(row.period_start)} – ${formatDateTime(row.period_end)} · ${row.run_type === 'daily' ? 'daily' : 'on demand'}, by ${row.calculated_by}`}
            actions={
                row.can_pay && (
                    <PgButton variant="primary" onClick={onPay}>
                        Record payment
                    </PgButton>
                )
            }
            summaries={[
                {
                    label: directionText(row.direction, portal, row.party.code),
                    value: formatPaise(row.net_amount),
                },
                { label: 'Paid', value: formatPaise(row.settled_amount) },
                {
                    label: 'Still open',
                    value: formatPaise(row.net_amount - row.settled_amount),
                },
            ]}
            tabs={[
                { key: 'overview', label: 'Overview' },
                ...(portal !== 'partner'
                    ? [{ key: 'lines', label: 'By pair' }]
                    : []),
                { key: 'payments', label: 'Payments' },
            ]}
            activeTab={tab}
            onTabChange={setTab}
        >
            {row.carried_forward && (
                <div className="rounded-lg bg-hdb px-3 py-2.5 text-[12.5px] text-hd">
                    What was left unpaid here is part of a newer settlement for{' '}
                    {row.party.code}; payments are recorded there.
                </div>
            )}
            {tab === 'overview' && (
                <Section title="How the position moved">
                    <KeyValues
                        items={[
                            {
                                label: 'Position at the start',
                                value: <Signed value={f.opening} />,
                            },
                            {
                                label: 'Pay-ins (gross)',
                                value: formatPaise(f.gross_payin),
                            },
                            {
                                label: 'Payouts (gross)',
                                value: formatPaise(f.gross_payout),
                            },
                            ...(f.partner_commission !== null
                                ? [
                                      {
                                          label: 'Partner commission',
                                          value: formatPaise(
                                              f.partner_commission,
                                          ),
                                      },
                                  ]
                                : []),
                            ...(f.branch_commission !== null
                                ? [
                                      {
                                          label: 'Branch commission',
                                          value: formatPaise(
                                              f.branch_commission,
                                          ),
                                      },
                                  ]
                                : []),
                            ...(f.platform_margin !== null
                                ? [
                                      {
                                          label: 'Platform margin',
                                          value: formatPaise(f.platform_margin),
                                      },
                                  ]
                                : []),
                            {
                                label: 'Adjustments',
                                value: <Signed value={f.adjustments} />,
                            },
                            {
                                label: 'Settlement payments recorded',
                                value: <Signed value={f.settlements} />,
                            },
                            {
                                label: 'Position at the end',
                                value: <Signed value={f.closing} />,
                            },
                        ]}
                    />
                    <p className="text-xs text-tx3">
                        + means PayGate owes{' '}
                        {portal === 'admin' ? 'the party' : 'you'}; − means{' '}
                        {portal === 'admin' ? 'the party owes' : 'you owe'}{' '}
                        PayGate. The position includes anything left unpaid from
                        earlier settlements.
                    </p>
                    {detail?.notes && (
                        <p className="text-xs whitespace-pre-line text-tx2">
                            Notes: {detail.notes}
                        </p>
                    )}
                </Section>
            )}
            {tab === 'lines' &&
                (!detail ? (
                    <Loading />
                ) : (
                    <SimpleTable
                        headers={[
                            row.party_type === 'partner' ? 'Branch' : 'Partner',
                            'Start',
                            'End',
                            'To settle',
                            'Paid',
                        ]}
                        rows={detail.lines.map((line) => [
                            <span key="c">
                                {line.counterpart.name}{' '}
                                <span className="font-mono text-xs text-tx3">
                                    {line.counterpart.code}
                                </span>
                            </span>,
                            <Signed key="o" value={line.figures.opening} />,
                            <Signed key="e" value={line.figures.closing} />,
                            <span key="n" className="whitespace-nowrap">
                                {formatPaise(line.net_amount)}
                                <span className="block text-xs text-tx3">
                                    {directionText(
                                        line.direction,
                                        portal,
                                        row.party.code,
                                    )}
                                </span>
                            </span>,
                            formatPaise(line.settled_amount),
                        ])}
                    />
                ))}
            {tab === 'payments' &&
                (!detail ? (
                    <Loading />
                ) : (
                    <SimpleTable
                        empty="No payment recorded yet."
                        headers={[
                            'Paid on',
                            ...(portal !== 'partner' ? ['Pair'] : []),
                            'Amount',
                            'Reference',
                            ...(portal === 'admin' ? ['Recorded by'] : []),
                        ]}
                        rows={detail.payments.map((payment) => [
                            payment.paid_at,
                            ...(portal !== 'partner'
                                ? [lineName(payment.line_id)]
                                : []),
                            <span key="a" className="whitespace-nowrap">
                                {formatPaise(payment.amount)}
                                <span className="block text-xs text-tx3">
                                    {directionText(
                                        payment.direction,
                                        portal,
                                        row.party.code,
                                    )}
                                </span>
                            </span>,
                            payment.reference ?? '—',
                            ...(portal === 'admin'
                                ? [
                                      `${payment.recorded_by} · ${formatDateTime(payment.recorded_at)}`,
                                  ]
                                : []),
                        ])}
                    />
                ))}
        </Drawer>
    );
}

/** Admin's "tick as settled": an amount per pair (partial is fine). */
function PaymentDialog({
    row,
    detail,
    onClose,
}: {
    row: Row;
    detail: Detail;
    onClose: () => void;
}) {
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kolkata',
    }).format(new Date());
    const open = detail.lines.filter((line) => line.remaining > 0);
    const form = useForm({
        amounts: Object.fromEntries(
            open.map((line) => [line.id, (line.remaining / 100).toFixed(2)]),
        ) as Record<string, string>,
        paid_at: today,
        reference: '',
        note: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const total = Object.values(form.data.amounts).reduce(
        (sum, value) => sum + Math.round((Number(value) || 0) * 100),
        0,
    );

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            width={560}
            title={`Record payment · ${row.reference}`}
            description={`${row.party.name} (${row.party.code}). Enter what was actually paid outside PayGate for each pair; anything left carries forward. This books the payment and can’t be undone.`}
            submitLabel={form.processing ? 'Recording…' : 'Record as settled'}
            processing={form.processing || total === 0}
            onSubmit={() =>
                form.post(adminSettlements.pay(row.id).url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
        >
            {errors.amounts && (
                <div className="rounded-lg bg-erb px-3 py-2.5 text-[12.5px] text-er">
                    {errors.amounts}
                </div>
            )}
            <div className="flex flex-col gap-2">
                {open.map((line) => (
                    <Field
                        key={line.id}
                        label={`${line.counterpart.name} (${line.counterpart.code}) · open ${formatPaise(line.remaining)} · ${directionText(line.direction, 'admin', row.party.code)}`}
                        error={errors[`amounts.${line.id}`]}
                    >
                        <TextInput
                            inputMode="decimal"
                            value={form.data.amounts[line.id] ?? ''}
                            onChange={(event) =>
                                form.setData('amounts', {
                                    ...form.data.amounts,
                                    [line.id]: event.target.value,
                                })
                            }
                        />
                    </Field>
                ))}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Paid on" error={errors.paid_at}>
                    <TextInput
                        type="date"
                        max={today}
                        value={form.data.paid_at}
                        onChange={(event) =>
                            form.setData('paid_at', event.target.value)
                        }
                    />
                </Field>
                <Field label="Bank reference (optional)">
                    <TextInput
                        value={form.data.reference}
                        onChange={(event) =>
                            form.setData('reference', event.target.value)
                        }
                    />
                </Field>
            </div>
            <Field label="Note (optional)">
                <TextInput
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
            </Field>
            <div className="text-right text-[13px]">
                Total recorded: <b>{formatPaise(total)}</b>
            </div>
        </FormDialog>
    );
}

/** On demand: one party, from its last settlement up to now. */
function CalculateDialog({
    parties,
    onClose,
}: {
    parties: Record<'partner' | 'branch', Party[]>;
    onClose: () => void;
}) {
    const form = useForm<{
        party_type: 'partner' | 'branch';
        party_id: string;
    }>({ party_type: 'partner', party_id: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            title="Calculate a settlement now"
            description="From the end of the party’s last settlement up to this moment. The daily run continues from here."
            submitLabel="Calculate"
            processing={form.processing || !form.data.party_id}
            onSubmit={() =>
                form.post(adminSettlements.calculate().url, {
                    onSuccess: onClose,
                })
            }
        >
            <Segmented
                options={[
                    { value: 'partner', label: 'Partner' },
                    { value: 'branch', label: 'Branch' },
                ]}
                value={form.data.party_type}
                onChange={(value) =>
                    form.setData({ party_type: value, party_id: '' })
                }
            />
            <Field
                label={
                    form.data.party_type === 'partner' ? 'Partner' : 'Branch'
                }
                error={errors.party ?? errors.party_id}
            >
                <SelectInput
                    required
                    value={form.data.party_id}
                    onChange={(event) =>
                        form.setData('party_id', event.target.value)
                    }
                >
                    <option value="">Choose…</option>
                    {parties[form.data.party_type].map((party) => (
                        <option key={party.id} value={party.id}>
                            {party.code} · {party.name}
                        </option>
                    ))}
                </SelectInput>
            </Field>
        </FormDialog>
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

function Loading() {
    return <div className="py-10 text-center text-xs text-tx3">Loading…</div>;
}
