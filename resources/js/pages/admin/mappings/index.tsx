import { Head, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { ViewTabs } from '@/components/pg/filter-bar';
import { FormDialog } from '@/components/pg/form-dialog';
import { PageHeader } from '@/components/pg/page-header';
import { StatusBadge } from '@/components/pg/status-badge';
import { SwitchField } from '@/components/pg/switch-field';
import { formatLimit } from '@/lib/money';
import { cn } from '@/lib/utils';
import mappingsRoutes from '@/routes/admin/mappings';

type Org = { id: string; code: string; name: string };

type PairRates = {
    partner: string | null;
    branch: string | null;
    partner_override: string | null;
    branch_override: string | null;
    margin: string | null;
};

type Mapping = {
    id: string;
    partner: Org;
    branch: Org;
    status: string;
    is_deposit_enabled: boolean;
    is_withdrawal_enabled: boolean;
    deposit_daily_limit: number | null;
    withdrawal_daily_limit: number | null;
    rates: { deposit: PairRates; withdrawal: PairRates };
};

type Props = {
    mappings: { data: Mapping[]; total: number };
    filters: {
        partner: string | null;
        branch: string | null;
        status: string | null;
    };
    partners: Org[];
    branches: Org[];
    can: { update: boolean; rates: boolean };
};

const NO_LIMIT = '-1';

const pct = (rate: string | null) => (rate === null ? '—' : `${Number(rate)}%`);

export default function Mappings({
    mappings,
    filters,
    partners,
    branches,
    can,
}: Props) {
    const [editing, setEditing] = useState<Mapping | null>(null);
    const [adding, setAdding] = useState(false);

    const visit = (next: Partial<Props['filters']>) =>
        router.get(
            mappingsRoutes.index({
                query: Object.fromEntries(
                    Object.entries({ ...filters, ...next }).filter(
                        ([, v]) => v !== null && v !== '',
                    ),
                ),
            }).url,
            {},
            { preserveState: true, replace: true },
        );

    const rateCell = (r: PairRates) => (
        <div className="text-xs whitespace-nowrap">
            <div>
                {pct(r.partner)}
                {r.partner_override && <Override />} → {pct(r.branch)}
                {r.branch_override && <Override />}
            </div>
            {r.margin !== null && (
                <div
                    className={cn(
                        Number(r.margin) < 0
                            ? 'font-medium text-er'
                            : 'text-tx3',
                    )}
                >
                    margin {Number(r.margin) > 0 ? '+' : ''}
                    {Number(r.margin)}%
                </div>
            )}
        </div>
    );

    const columns: Column<Mapping>[] = [
        {
            key: 'partner',
            header: 'Partner',
            cell: (m) => (
                <span>
                    <span className="font-mono text-xs text-tx3">
                        {m.partner.code}
                    </span>{' '}
                    {m.partner.name}
                </span>
            ),
        },
        {
            key: 'branch',
            header: 'Branch',
            cell: (m) => (
                <span>
                    <span className="font-mono text-xs text-tx3">
                        {m.branch.code}
                    </span>{' '}
                    {m.branch.name}
                </span>
            ),
        },
        {
            key: 'deposit',
            header: 'Deposit (partner → branch)',
            cell: (m) =>
                m.is_deposit_enabled ? (
                    rateCell(m.rates.deposit)
                ) : (
                    <span className="text-xs text-tx3">Off</span>
                ),
        },
        {
            key: 'withdrawal',
            header: 'Withdrawal (partner → branch)',
            cell: (m) =>
                m.is_withdrawal_enabled ? (
                    rateCell(m.rates.withdrawal)
                ) : (
                    <span className="text-xs text-tx3">Off</span>
                ),
        },
        {
            key: 'limits',
            header: 'Pair daily limits',
            cell: (m) => (
                <span className="text-xs text-tx2">
                    {m.deposit_daily_limit === null &&
                    m.withdrawal_daily_limit === null
                        ? 'None'
                        : `${formatLimit(m.deposit_daily_limit)} in · ${formatLimit(m.withdrawal_daily_limit)} out`}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (m) => <StatusBadge status={m.status} />,
        },
        {
            key: 'edit',
            header: '',
            align: 'right',
            cell: (m) =>
                can.update && (
                    <PgButton
                        className="h-7 text-xs"
                        onClick={() => setEditing(m)}
                    >
                        Edit
                    </PgButton>
                ),
        },
    ];

    return (
        <>
            <Head title="Branch mapping" />
            <PageHeader
                title="Partner ↔ branch mapping"
                description="Which branches serve which partners, per direction, and the rates that apply to each pair. A pair rate (◆) overrides the partner’s or branch’s own rate for that pair only."
                actions={
                    can.update && (
                        <PgButton
                            variant="primary"
                            onClick={() => setAdding(true)}
                        >
                            <Plus className="size-4" /> Map a pair
                        </PgButton>
                    )
                }
            />

            <Panel className="overflow-hidden">
                <ViewTabs
                    views={[
                        { key: 'all', label: 'All' },
                        { key: 'active', label: 'Active' },
                        { key: 'inactive', label: 'Inactive' },
                    ]}
                    active={filters.status ?? 'all'}
                    onChange={(key) =>
                        visit({ status: key === 'all' ? null : key })
                    }
                />
                <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <OrgSelect
                        label="All partners"
                        items={partners}
                        value={filters.partner}
                        onChange={(partner) => visit({ partner })}
                    />
                    <OrgSelect
                        label="All branches"
                        items={branches}
                        value={filters.branch}
                        onChange={(branch) => visit({ branch })}
                    />
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">
                        {mappings.total} pairs
                    </span>
                </div>
                <DataTable
                    columns={columns}
                    rows={mappings.data}
                    rowKey={(m) => m.id}
                    empty={
                        <EmptyState
                            title="No pairs yet"
                            description="Map branches from the partner wizard, the branch form, or with “Map a pair”."
                        />
                    }
                />
            </Panel>

            {adding && (
                <AddDialog
                    partners={partners}
                    branches={branches}
                    onClose={() => setAdding(false)}
                />
            )}
            {editing && (
                <EditDialog
                    mapping={editing}
                    canRates={can.rates}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function Override() {
    return (
        <span
            title="Pair rate (overrides the default)"
            className="ml-0.5 text-ac"
        >
            ◆
        </span>
    );
}

function OrgSelect({
    label,
    items,
    value,
    onChange,
}: {
    label: string;
    items: Org[];
    value: string | null;
    onChange: (id: string | null) => void;
}) {
    return (
        <SelectInput
            className="h-[30px] w-[220px] text-[12.5px]"
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value || null)}
        >
            <option value="">{label}</option>
            {items.map((item) => (
                <option key={item.id} value={item.id}>
                    {item.code} · {item.name}
                </option>
            ))}
        </SelectInput>
    );
}

function AddDialog({
    partners,
    branches,
    onClose,
}: {
    partners: Org[];
    branches: Org[];
    onClose: () => void;
}) {
    const form = useForm({ partner_id: '', branch_id: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title="Map a pair"
            description="The branch may then serve this partner’s customers (both directions on by default)."
            submitLabel="Map"
            processing={form.processing}
            onSubmit={() =>
                form.post(mappingsRoutes.store().url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
        >
            {(['partner', 'branch'] as const).map((kind) => (
                <Field
                    key={kind}
                    label={kind === 'partner' ? 'Partner' : 'Branch'}
                    error={errors[`${kind}_id`]}
                >
                    <SelectInput
                        required
                        value={form.data[`${kind}_id`]}
                        onChange={(event) =>
                            form.setData(`${kind}_id`, event.target.value)
                        }
                    >
                        <option value="">Choose…</option>
                        {(kind === 'partner' ? partners : branches).map(
                            (item) => (
                                <option key={item.id} value={item.id}>
                                    {item.code} · {item.name}
                                </option>
                            ),
                        )}
                    </SelectInput>
                </Field>
            ))}
        </FormDialog>
    );
}

function EditDialog({
    mapping,
    canRates,
    onClose,
}: {
    mapping: Mapping;
    canRates: boolean;
    onClose: () => void;
}) {
    const rupees = (paise: number | null) =>
        paise === null ? NO_LIMIT : String(paise / 100);
    const form = useForm({
        status: mapping.status,
        is_deposit_enabled: mapping.is_deposit_enabled,
        is_withdrawal_enabled: mapping.is_withdrawal_enabled,
        deposit_daily_limit: rupees(mapping.deposit_daily_limit),
        withdrawal_daily_limit: rupees(mapping.withdrawal_daily_limit),
        overrides: {
            partner: {
                deposit: mapping.rates.deposit.partner_override ?? '',
                withdrawal: mapping.rates.withdrawal.partner_override ?? '',
            },
            branch: {
                deposit: mapping.rates.deposit.branch_override ?? '',
                withdrawal: mapping.rates.withdrawal.branch_override ?? '',
            },
        },
    });
    const errors = form.errors as Record<string, string | undefined>;

    const override = (
        side: 'partner' | 'branch',
        direction: 'deposit' | 'withdrawal',
    ) => {
        const key = `overrides.${side}.${direction}`;
        const current = mapping.rates[direction];
        const fallback =
            side === 'partner'
                ? current.partner_override
                    ? null
                    : current.partner
                : current.branch_override
                  ? null
                  : current.branch;

        return (
            <Field
                key={key}
                label={`${side === 'partner' ? 'Partner pays' : 'Branch earns'} · ${direction}`}
                hint={
                    fallback !== null
                        ? `Empty = default ${Number(fallback)}%`
                        : 'Empty = the default rate'
                }
                error={errors[key]}
            >
                <TextInput
                    inputMode="decimal"
                    disabled={!canRates}
                    placeholder="Default"
                    value={form.data.overrides[side][direction]}
                    onChange={(event) =>
                        form.setData('overrides', {
                            ...form.data.overrides,
                            [side]: {
                                ...form.data.overrides[side],
                                [direction]: event.target.value,
                            },
                        })
                    }
                />
            </Field>
        );
    };

    return (
        <FormDialog
            open
            width={600}
            onOpenChange={(open) => !open && onClose()}
            title={`${mapping.partner.code} ↔ ${mapping.branch.code}`}
            description={`${mapping.partner.name} served by ${mapping.branch.name}.`}
            submitLabel="Save"
            processing={form.processing}
            onSubmit={() => {
                form.transform((data) =>
                    canRates ? data : { ...data, overrides: undefined },
                );
                form.put(mappingsRoutes.update(mapping.id).url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <SwitchField
                    label="Active"
                    checked={form.data.status === 'active'}
                    onChange={(on) =>
                        form.setData('status', on ? 'active' : 'inactive')
                    }
                />
                <div />
                <SwitchField
                    label="Deposits"
                    checked={form.data.is_deposit_enabled}
                    onChange={(on) => form.setData('is_deposit_enabled', on)}
                />
                <SwitchField
                    label="Withdrawals"
                    checked={form.data.is_withdrawal_enabled}
                    onChange={(on) => form.setData('is_withdrawal_enabled', on)}
                />
                <Field
                    label="Pair daily deposit limit (₹)"
                    hint={`Enter ${NO_LIMIT} for unlimited`}
                    error={errors.deposit_daily_limit}
                    required
                >
                    <TextInput
                        inputMode="decimal"
                        required
                        pattern="-1|\d{1,11}(\.\d{1,2})?"
                        placeholder={NO_LIMIT}
                        value={form.data.deposit_daily_limit}
                        onChange={(event) =>
                            form.setData(
                                'deposit_daily_limit',
                                event.target.value,
                            )
                        }
                    />
                </Field>
                <Field
                    label="Pair daily withdrawal limit (₹)"
                    hint={`Enter ${NO_LIMIT} for unlimited`}
                    error={errors.withdrawal_daily_limit}
                    required
                >
                    <TextInput
                        inputMode="decimal"
                        required
                        pattern="-1|\d{1,11}(\.\d{1,2})?"
                        placeholder={NO_LIMIT}
                        value={form.data.withdrawal_daily_limit}
                        onChange={(event) =>
                            form.setData(
                                'withdrawal_daily_limit',
                                event.target.value,
                            )
                        }
                    />
                </Field>
                <div className="border-t border-ln2 pt-3 text-xs font-semibold tracking-[.04em] text-tx3 uppercase sm:col-span-2">
                    Pair rates (%)
                </div>
                {override('partner', 'deposit')}
                {override('branch', 'deposit')}
                {override('partner', 'withdrawal')}
                {override('branch', 'withdrawal')}
            </div>
        </FormDialog>
    );
}
