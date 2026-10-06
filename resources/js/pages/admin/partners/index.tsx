import { Head, Link, router, usePage } from '@inertiajs/react';
import { Copy, Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { PgButton } from '@/components/pg/button';
import { ConfirmDialog } from '@/components/pg/confirm-dialog';
import { CredentialsDialog } from '@/components/pg/credentials-dialog';
import type { Credentials } from '@/components/pg/credentials-dialog';
import { ApiKeyTable } from '@/components/pg/api-key-table';
import type { ApiKey } from '@/components/pg/api-key-table';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { Drawer, KeyValues } from '@/components/pg/drawer';
import { EmptyState } from '@/components/pg/empty-state';
import { ViewTabs } from '@/components/pg/filter-bar';
import { PageHeader } from '@/components/pg/page-header';
import { SecureActionDialog } from '@/components/pg/secure-action-dialog';
import { SimpleTable } from '@/components/pg/simple-table';
import { StatusBadge } from '@/components/pg/status-badge';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatDateTime, formatRelative } from '@/lib/dates';
import { formatLimit } from '@/lib/money';
import partnersRoutes from '@/routes/admin/partners';
import apiKeysRoutes from '@/routes/admin/partners/api-keys';

type Row = {
    id: string;
    name: string;
    code: string;
    email: string;
    return_url: string | null;
    status: string;
    is_payin_enabled: boolean;
    is_payout_enabled: boolean;
    key: { key_id: string; last4: string } | null;
    rates: { deposit?: string; withdrawal?: string };
    branches_count: number;
    users_count: number;
};

type Detail = {
    id: string;
    website_url: string | null;
    callback_url: string | null;
    payin_webhook_url: string | null;
    payout_webhook_url: string | null;
    api_version: string;
    manual_payment_type: string | null;
    methods: string[];
    limits: { deposit: (number | null)[]; withdrawal: (number | null)[] };
    payout_limit_type: string;
    is_auto_withdrawal: boolean;
    is_partial_withdrawal: boolean;
    session_ttl_minutes: number;
    verified_at: string | null;
    created_at: string | null;
    keys: ApiKey[];
    ips: string[];
    rate_history: {
        direction: string;
        rate: string;
        from: string;
        to: string | null;
    }[];
    branches: {
        code: string;
        name: string;
        status: string;
        deposit: boolean;
        withdrawal: boolean;
    }[];
    activity: {
        action: string;
        actor: string;
        at: string;
        reason: string | null;
    }[];
    transitions: string[];
    blockers: string[];
};

type Props = {
    partners: {
        data: Row[];
        total: number;
        from: number | null;
        to: number | null;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { status: string | null; search: string };
    counts: Record<string, number>;
    selected: string | null;
    detail?: Detail | null;
    can: {
        create: boolean;
        update: boolean;
        users: boolean;
        issue_keys: boolean;
        revoke_keys: boolean;
    };
};

const STATUS_TABS = [
    ['all', 'All'],
    ['active', 'Active'],
    ['draft', 'Draft'],
    ['suspended', 'Suspended'],
    ['offboarded', 'Offboarded'],
] as const;

const TRANSITION_LABELS: Record<
    string,
    {
        label: string;
        tone: 'primary' | 'danger' | 'warning';
        description: string;
    }
> = {
    active: {
        label: 'Activate',
        tone: 'primary',
        description:
            'The partner can create pay-ins and payouts through the API.',
    },
    suspended: {
        label: 'Suspend',
        tone: 'warning',
        description:
            'New API requests are refused until the partner is reactivated. Existing transactions continue.',
    },
    offboarded: {
        label: 'Offboard',
        tone: 'danger',
        description:
            'The partner stops for good. History and balances are kept for settlement.',
    },
};

const PAYMENT_TYPES: Record<string, string> = {
    bank_details: 'Manual bank details',
    intent: 'UPI intent',
    dynamic_qr: 'Dynamic QR',
};

const initials = (name: string) =>
    name
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase())
        .join('');

export default function PartnersIndex({
    partners,
    filters,
    counts,
    selected,
    detail,
    can,
}: Props) {
    const page = usePage();
    const flashCredentials =
        (page.flash as { credentials?: Credentials }).credentials ?? null;
    const [credentials, setCredentials] = useState<Credentials | null>(
        flashCredentials,
    );
    const [search, setSearch] = useState(filters.search);
    // With new credentials on screen, the drawer waits until they are closed
    // (two dialogs at once would hide one from screen readers).
    const [openId, setOpenId] = useState<string | null>(
        flashCredentials ? null : selected,
    );
    const [tab, setTab] = useState('overview');
    const [transition, setTransition] = useState<string | null>(null);
    const [keyAction, setKeyAction] = useState<
        null | { type: 'issue' } | { type: 'revoke'; key: ApiKey }
    >(null);
    const [, copy] = useClipboard();

    const open = partners.data.find((partner) => partner.id === openId) ?? null;
    const loaded = detail && detail.id === openId ? detail : null;

    // Keep the secret on screen until closed: later requests (e.g. loading the
    // drawer) carry no flash data and must not clear it.
    useEffect(() => {
        if (flashCredentials) setCredentials(flashCredentials);
    }, [flashCredentials]);

    const query = (
        next: Partial<{
            status: string | null;
            search: string;
            partner: string | null;
        }>,
    ) =>
        Object.fromEntries(
            Object.entries({
                ...filters,
                search,
                partner: openId,
                ...next,
            }).filter(([, value]) => value !== null && value !== ''),
        );

    const openPartner = (id: string | null) => {
        setOpenId(id);
        setTab('overview');

        if (id) {
            router.reload({
                only: ['detail', 'selected'],
                data: { partner: id },
            });
        }
    };

    // Opened from a redirect (e.g. right after creating a partner).
    useEffect(() => {
        if (selected && !detail) {
            router.reload({ only: ['detail'] });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (search === filters.search) return;

        const timer = setTimeout(
            () =>
                router.get(
                    partnersRoutes.index({
                        query: query({ search, partner: null }),
                    }).url,
                    {},
                    { preserveState: true, replace: true },
                ),
            300,
        );

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const columns: Column<Row>[] = [
        {
            key: 'partner',
            header: 'Partner',
            cell: (p) => (
                <div className="flex items-center gap-2.5">
                    <span className="grid size-8 flex-none place-items-center rounded-lg bg-acs text-xs font-semibold text-act">
                        {initials(p.name)}
                    </span>
                    <div className="min-w-0">
                        <div className="font-medium">
                            {p.name}{' '}
                            <span className="font-mono text-xs text-tx3">
                                {p.code}
                            </span>
                        </div>
                        <div className="text-xs text-tx3">{p.email}</div>
                    </div>
                </div>
            ),
        },
        {
            key: 'key',
            header: 'API key',
            cell: (p) =>
                p.key ? (
                    <span className="inline-flex items-center gap-1.5 font-mono text-xs">
                        {p.key.key_id.slice(0, 12)}…{' '}
                        <span className="text-tx3">••••{p.key.last4}</span>
                        <button
                            type="button"
                            title="Copy key ID"
                            onClick={(event) => {
                                event.stopPropagation();
                                void copy(p.key!.key_id);
                            }}
                            className="text-tx3 hover:text-tx"
                        >
                            <Copy className="size-3.5" />
                        </button>
                    </span>
                ) : (
                    <span className="text-xs text-tx3">No key</span>
                ),
        },
        {
            key: 'return_url',
            header: 'Return URL',
            cell: (p) => (
                <span className="block max-w-[200px] truncate text-xs text-tx2">
                    {p.return_url ?? '—'}
                </span>
            ),
        },
        {
            key: 'payin',
            header: 'Pay-in',
            cell: (p) => <Enabled on={p.is_payin_enabled} />,
        },
        {
            key: 'payout',
            header: 'Pay-out',
            cell: (p) => <Enabled on={p.is_payout_enabled} />,
        },
        {
            key: 'commission',
            header: 'Commission',
            cell: (p) => (
                <div className="text-xs">
                    <div>
                        Deposit{' '}
                        <b className="font-semibold">
                            {p.rates.deposit
                                ? `${Number(p.rates.deposit)}%`
                                : '—'}
                        </b>
                    </div>
                    <div className="text-tx3">
                        Withdraw{' '}
                        {p.rates.withdrawal
                            ? `${Number(p.rates.withdrawal)}%`
                            : '—'}
                    </div>
                </div>
            ),
        },
        {
            key: 'branches',
            header: 'Branches',
            cell: (p) => (
                <span className="text-ac">
                    {p.branches_count}{' '}
                    {p.branches_count === 1 ? 'branch' : 'branches'} ›
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (p) => <StatusBadge status={p.status} />,
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            cell: (p) => (
                <div className="flex justify-end gap-1.5">
                    {can.users && (
                        <Link
                            href={partnersRoutes.users.index(p.id).url}
                            onClick={(event) => event.stopPropagation()}
                            className="rounded-[7px] border border-ln px-2.5 py-1 text-xs font-medium hover:bg-sf2"
                        >
                            Users
                        </Link>
                    )}
                    {can.update && (
                        <Link
                            href={partnersRoutes.edit(p.id).url}
                            onClick={(event) => event.stopPropagation()}
                            className="rounded-[7px] border border-ln px-2.5 py-1 text-xs font-medium hover:bg-sf2"
                        >
                            Edit
                        </Link>
                    )}
                </div>
            ),
        },
    ];

    const keys = loaded?.keys ?? [];
    const hasActiveKey = keys.some((key) => key.status === 'active');

    return (
        <>
            <Head title="Partners" />

            <PageHeader
                title="Partners"
                description={`${counts.all ?? 0} merchants · ${counts.active ?? 0} active. Credentials, payment methods and branch routing per partner.`}
                actions={
                    can.create && (
                        <Link
                            href={partnersRoutes.create().url}
                            className="inline-flex h-8 items-center gap-1.5 rounded-[7px] bg-brand px-3 text-[13px] font-medium text-white"
                        >
                            <Plus className="size-4" /> Create partner
                        </Link>
                    )
                }
            />

            <Panel>
                <ViewTabs
                    views={STATUS_TABS.map(([key, label]) => ({
                        key,
                        label,
                        count: counts[key] ?? 0,
                    }))}
                    active={filters.status ?? 'all'}
                    onChange={(key) =>
                        router.get(
                            partnersRoutes.index({
                                query: query({
                                    status: key === 'all' ? null : key,
                                    partner: null,
                                }),
                            }).url,
                        )
                    }
                />
                <div className="flex items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] w-[260px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search name, code or email"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">
                        {partners.total} partners
                    </span>
                </div>
                <DataTable
                    columns={columns}
                    rows={partners.data}
                    rowKey={(p) => p.id}
                    onRowClick={(p) => openPartner(p.id)}
                    empty={
                        <EmptyState
                            title="No partners found"
                            description={
                                filters.search || filters.status
                                    ? 'Try another search or filter.'
                                    : 'Create the first partner to start integrating.'
                            }
                        />
                    }
                />
                {partners.last_page > 1 && (
                    <div className="flex items-center justify-between px-3 py-2.5 text-xs text-tx3">
                        <span>
                            Showing {partners.from}–{partners.to} of{' '}
                            {partners.total}
                        </span>
                        <div className="flex gap-2">
                            {partners.prev_page_url && (
                                <Link
                                    href={partners.prev_page_url}
                                    className="rounded-[7px] border border-ln px-2.5 py-1 text-tx"
                                >
                                    Previous
                                </Link>
                            )}
                            {partners.next_page_url && (
                                <Link
                                    href={partners.next_page_url}
                                    className="rounded-[7px] border border-ln px-2.5 py-1 text-tx"
                                >
                                    Next
                                </Link>
                            )}
                        </div>
                    </div>
                )}
            </Panel>

            {open && (
                <Drawer
                    open
                    onOpenChange={(next) => !next && setOpenId(null)}
                    kind="Partner"
                    title={open.code}
                    status={<StatusBadge status={open.status} />}
                    subtitle={`${open.name} · ${open.email}`}
                    actions={
                        <div className="flex gap-2">
                            {can.users && (
                                <Link
                                    href={
                                        partnersRoutes.users.index(open.id).url
                                    }
                                    className="inline-flex h-8 items-center rounded-[7px] border border-ln px-3 text-[13px] font-medium hover:bg-sf2"
                                >
                                    Users ({open.users_count})
                                </Link>
                            )}
                            {can.update && (
                                <Link
                                    href={partnersRoutes.edit(open.id).url}
                                    className="inline-flex h-8 items-center rounded-[7px] border border-ln px-3 text-[13px] font-medium hover:bg-sf2"
                                >
                                    Edit
                                </Link>
                            )}
                        </div>
                    }
                    summaries={[
                        {
                            label: 'Deposit commission',
                            value: open.rates.deposit
                                ? `${Number(open.rates.deposit)}%`
                                : '—',
                        },
                        {
                            label: 'Withdrawal commission',
                            value: open.rates.withdrawal
                                ? `${Number(open.rates.withdrawal)}%`
                                : '—',
                        },
                        {
                            label: 'Mapped branches',
                            value: open.branches_count,
                        },
                    ]}
                    tabs={[
                        { key: 'overview', label: 'Overview' },
                        { key: 'api', label: 'API & security' },
                        { key: 'commission', label: 'Commission history' },
                        { key: 'branches', label: 'Branches' },
                        { key: 'activity', label: 'Activity' },
                    ]}
                    activeTab={tab}
                    onTabChange={setTab}
                >
                    {!loaded ? (
                        <div className="py-10 text-center text-xs text-tx3">
                            Loading…
                        </div>
                    ) : (
                        <>
                            {tab === 'overview' && (
                                <>
                                    {loaded.blockers.length > 0 && (
                                        <div className="rounded-lg bg-inb px-3 py-2.5 text-[12.5px] text-in">
                                            Not ready to go live:{' '}
                                            {loaded.blockers.join('; ')}.
                                        </div>
                                    )}
                                    <KeyValues
                                        items={[
                                            {
                                                label: 'Website',
                                                value: loaded.website_url,
                                            },
                                            {
                                                label: 'Return URL',
                                                value: open.return_url,
                                            },
                                            {
                                                label: 'Manual payment type',
                                                value: loaded.manual_payment_type
                                                    ? PAYMENT_TYPES[
                                                          loaded
                                                              .manual_payment_type
                                                      ]
                                                    : null,
                                            },
                                            {
                                                label: 'Pay-in methods',
                                                value:
                                                    loaded.methods.join(
                                                        ' · ',
                                                    ) || null,
                                            },
                                            {
                                                label: 'Payment page expiry',
                                                value: `${loaded.session_ttl_minutes} min`,
                                            },
                                            {
                                                label: 'Deposit min / max',
                                                value: `${formatLimit(loaded.limits.deposit[0])} – ${formatLimit(loaded.limits.deposit[1])}`,
                                            },
                                            {
                                                label: 'Deposit daily limit',
                                                value: formatLimit(
                                                    loaded.limits.deposit[2],
                                                ),
                                            },
                                            {
                                                label: 'Withdrawal min / max',
                                                value: `${formatLimit(loaded.limits.withdrawal[0])} – ${formatLimit(loaded.limits.withdrawal[1])}`,
                                            },
                                            {
                                                label: 'Withdrawal daily limit',
                                                value: formatLimit(
                                                    loaded.limits.withdrawal[2],
                                                ),
                                            },
                                            {
                                                label: 'Payout limit type',
                                                value:
                                                    loaded.payout_limit_type ===
                                                    'topup'
                                                        ? 'Top-up balance'
                                                        : 'Daily (00:00 IST)',
                                            },
                                            {
                                                label: 'Auto / partial withdrawal',
                                                value: `${loaded.is_auto_withdrawal ? 'Auto' : 'Manual'} · ${loaded.is_partial_withdrawal ? 'partial allowed' : 'no partial'}`,
                                            },
                                            {
                                                label: 'Portal users',
                                                value: open.users_count,
                                            },
                                            {
                                                label: 'Went live',
                                                value: formatDateTime(
                                                    loaded.verified_at,
                                                ),
                                            },
                                            {
                                                label: 'Created',
                                                value: formatDateTime(
                                                    loaded.created_at,
                                                ),
                                            },
                                        ]}
                                    />
                                    {can.update &&
                                        loaded.transitions.length > 0 && (
                                            <div className="flex flex-wrap gap-2 border-t border-ln2 pt-4">
                                                {loaded.transitions.map(
                                                    (status) => (
                                                        <PgButton
                                                            key={status}
                                                            variant={
                                                                status ===
                                                                'active'
                                                                    ? 'primary'
                                                                    : status ===
                                                                        'offboarded'
                                                                      ? 'danger'
                                                                      : 'secondary'
                                                            }
                                                            disabled={
                                                                status ===
                                                                    'active' &&
                                                                loaded.blockers
                                                                    .length > 0
                                                            }
                                                            onClick={() =>
                                                                setTransition(
                                                                    status,
                                                                )
                                                            }
                                                        >
                                                            {open.status ===
                                                                'suspended' &&
                                                            status === 'active'
                                                                ? 'Reactivate'
                                                                : TRANSITION_LABELS[
                                                                      status
                                                                  ].label}
                                                        </PgButton>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                </>
                            )}

                            {tab === 'api' && (
                                <>
                                    <Section
                                        title="API keys"
                                        action={
                                            can.issue_keys && (
                                                <PgButton
                                                    onClick={() =>
                                                        setKeyAction({
                                                            type: 'issue',
                                                        })
                                                    }
                                                >
                                                    {hasActiveKey
                                                        ? 'Rotate secret'
                                                        : 'Generate key'}
                                                </PgButton>
                                            )
                                        }
                                    >
                                        <ApiKeyTable
                                            keys={keys}
                                            onRevoke={
                                                can.revoke_keys
                                                    ? (key) =>
                                                          setKeyAction({
                                                              type: 'revoke',
                                                              key,
                                                          })
                                                    : undefined
                                            }
                                        />
                                    </Section>
                                    <Section title="Endpoints">
                                        <KeyValues
                                            items={[
                                                {
                                                    label: 'API version',
                                                    value: loaded.api_version,
                                                },
                                                {
                                                    label: 'Pay-in callback',
                                                    value: loaded.callback_url,
                                                    mono: true,
                                                },
                                                {
                                                    label: 'Pay-in webhook',
                                                    value: loaded.payin_webhook_url,
                                                    mono: true,
                                                },
                                                {
                                                    label: 'Payout webhook',
                                                    value: loaded.payout_webhook_url,
                                                    mono: true,
                                                },
                                            ]}
                                        />
                                    </Section>
                                    <Section title="Allowed IPs">
                                        {loaded.ips.length ? (
                                            <div className="flex flex-wrap gap-1.5">
                                                {loaded.ips.map((ip) => (
                                                    <code
                                                        key={ip}
                                                        className="rounded-md bg-sf2 px-2 py-0.5 font-mono text-xs"
                                                    >
                                                        {ip}
                                                    </code>
                                                ))}
                                            </div>
                                        ) : (
                                            <p className="text-xs text-tx3">
                                                None yet.
                                            </p>
                                        )}
                                    </Section>
                                </>
                            )}

                            {tab === 'commission' && (
                                <SimpleTable
                                    empty="No commission set yet."
                                    headers={[
                                        'Direction',
                                        'Rate',
                                        'From',
                                        'Until',
                                    ]}
                                    rows={loaded.rate_history.map((r) => [
                                        r.direction === 'deposit'
                                            ? 'Deposit'
                                            : 'Withdrawal',
                                        `${Number(r.rate)}%`,
                                        formatDateTime(r.from),
                                        r.to ? (
                                            formatDateTime(r.to)
                                        ) : (
                                            <span className="text-ok">
                                                Current
                                            </span>
                                        ),
                                    ])}
                                />
                            )}

                            {tab === 'branches' && (
                                <SimpleTable
                                    empty="No branches mapped yet."
                                    headers={[
                                        'Branch',
                                        'Deposits',
                                        'Withdrawals',
                                        'Mapping',
                                    ]}
                                    rows={loaded.branches.map((b) => [
                                        <span key="b">
                                            <span className="font-mono text-xs text-tx3">
                                                {b.code}
                                            </span>{' '}
                                            {b.name}
                                        </span>,
                                        <Enabled key="d" on={b.deposit} />,
                                        <Enabled key="w" on={b.withdrawal} />,
                                        <StatusBadge
                                            key="s"
                                            status={b.status}
                                        />,
                                    ])}
                                />
                            )}

                            {tab === 'activity' && (
                                <SimpleTable
                                    empty="No activity yet."
                                    headers={['What', 'Who', 'When']}
                                    rows={loaded.activity.map((a) => [
                                        <span key="a">
                                            <span className="font-mono text-xs">
                                                {a.action}
                                            </span>
                                            {a.reason && (
                                                <span className="block text-xs text-tx3">
                                                    {a.reason}
                                                </span>
                                            )}
                                        </span>,
                                        a.actor,
                                        formatRelative(a.at),
                                    ])}
                                />
                            )}
                        </>
                    )}
                </Drawer>
            )}

            {open && transition && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setTransition(null)}
                    title={`${open.status === 'suspended' && transition === 'active' ? 'Reactivate' : TRANSITION_LABELS[transition].label} ${open.name}?`}
                    description={TRANSITION_LABELS[transition].description}
                    confirmLabel={
                        open.status === 'suspended' && transition === 'active'
                            ? 'Reactivate'
                            : TRANSITION_LABELS[transition].label
                    }
                    tone={TRANSITION_LABELS[transition].tone}
                    input={{
                        label: 'Reason (kept in the audit log)',
                        required: true,
                    }}
                    onConfirm={(reason) =>
                        router.put(
                            partnersRoutes.status(open.id).url,
                            { status: transition, reason },
                            {
                                preserveScroll: true,
                                onFinish: () => setTransition(null),
                            },
                        )
                    }
                />
            )}

            {open && (
                <SecureActionDialog
                    open={keyAction?.type === 'issue'}
                    onClose={() => setKeyAction(null)}
                    title={
                        hasActiveKey
                            ? `Rotate the secret of ${open.name}?`
                            : `Generate an API key for ${open.name}?`
                    }
                    description={
                        hasActiveKey
                            ? 'A new key and secret are created. The current one keeps working for 24 hours so the partner can switch, then stops.'
                            : 'The secret is shown once after you confirm.'
                    }
                    submitLabel={
                        hasActiveKey ? 'Rotate secret' : 'Generate key'
                    }
                    method="post"
                    url={apiKeysRoutes.store(open.id).url}
                    onSuccess={() =>
                        router.reload({ only: ['detail', 'partners'] })
                    }
                />
            )}

            {open && keyAction?.type === 'revoke' && (
                <SecureActionDialog
                    open
                    onClose={() => setKeyAction(null)}
                    title={`Revoke ${keyAction.key.key_id}?`}
                    description="API calls signed with this key are refused immediately. Use this if the secret may have leaked."
                    submitLabel="Revoke key"
                    method="delete"
                    askReason
                    url={
                        apiKeysRoutes.destroy({
                            partner: open.id,
                            key: keyAction.key.id,
                        }).url
                    }
                    onSuccess={() =>
                        router.reload({ only: ['detail', 'partners'] })
                    }
                />
            )}

            <CredentialsDialog
                credentials={credentials}
                onClose={() => {
                    setCredentials(null);
                    if (selected && !openId) openPartner(selected);
                }}
            />
        </>
    );
}

function Enabled({ on }: { on: boolean }) {
    return on ? (
        <span className="text-xs font-medium text-ok">● Enabled</span>
    ) : (
        <span className="text-xs text-tx3">○ Disabled</span>
    );
}

function Section({
    title,
    action,
    children,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
                <h3 className="text-[13px] font-semibold">{title}</h3>
                {action}
            </div>
            {children}
        </section>
    );
}
