import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronRight, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { PgButton } from '@/components/pg/button';
import { ConfirmDialog } from '@/components/pg/confirm-dialog';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { Drawer, KeyValues } from '@/components/pg/drawer';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { ViewTabs } from '@/components/pg/filter-bar';
import { FormDialog } from '@/components/pg/form-dialog';
import { PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDateTime, formatRelative } from '@/lib/dates';
import { PORTAL_LABELS } from '@/lib/portal-nav';
import adminUsers from '@/routes/admin/users';
import branchUsers from '@/routes/branch/users';
import partnerUsers from '@/routes/partner/users';
import type { UserType } from '@/types';

type Organisation = { id: string; name: string; code: string };
type RoleOption = { id: string; name: string; user_type: UserType };

type UserRow = {
    id: string;
    name: string;
    email: string;
    type: UserType;
    organisation: { name: string; code: string } | null;
    role: { id: string; name: string };
    status: 'active' | 'invited' | 'suspended';
    two_factor: boolean;
    last_login_at: string | null;
    last_login_ip: string | null;
    created_at: string | null;
    can: { update: boolean };
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Filters = {
    type: UserType | null;
    status: 'active' | 'invited' | 'suspended' | null;
    search: string;
};

type Props = {
    portal: UserType;
    users: Paginated<UserRow>;
    filters: Filters;
    counts: Record<string, number>;
    roles: RoleOption[];
    organisations: Record<'partner' | 'branch', Organisation[]> | null;
    can: { create: boolean };
};

/** Same screen in every portal; each portal has its own routes. */
const ROUTES = {
    admin: adminUsers,
    partner: partnerUsers,
    branch: branchUsers,
};

const STATUS_TABS = [
    { key: 'all', label: 'All' },
    { key: 'active', label: 'Active' },
    { key: 'invited', label: 'Invited' },
    { key: 'suspended', label: 'Suspended' },
] as const;

const firstError = (errors: Record<string, string>) =>
    toast.error(Object.values(errors)[0] ?? 'Something went wrong.');

export default function UsersIndex({
    portal,
    users,
    filters,
    counts,
    roles,
    organisations,
    can,
}: Props) {
    const routes = ROUTES[portal];
    const isAdmin = portal === 'admin';
    const [search, setSearch] = useState(filters.search);
    const [openId, setOpenId] = useState<string | null>(null);
    const [dialog, setDialog] = useState<
        | null
        | 'create'
        | 'edit'
        | 'password'
        | 'suspend'
        | 'reactivate'
        | 'two-factor'
    >(null);
    const [processing, setProcessing] = useState(false);
    const open = users.data.find((user) => user.id === openId) ?? null;

    const applyFilters = (next: Partial<Filters>) => {
        const merged = { ...filters, search, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(
                ([, value]) => value !== null && value !== '',
            ),
        );

        router.get(
            routes.index({ query }).url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    // Search as you type (debounced).
    const firstRender = useRef(true);
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timer = setTimeout(() => applyFilters({ search }), 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const act = (
        method: 'put' | 'post' | 'delete',
        url: string,
        data: Record<string, string> = {},
    ) =>
        router[method](url, data, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setDialog(null),
            onError: firstError,
        });

    const columns: Column<UserRow>[] = [
        {
            key: 'user',
            header: 'User',
            cell: (user) => (
                <div className="min-w-0">
                    <div className="font-medium">{user.name}</div>
                    <div className="text-xs text-tx3">{user.email}</div>
                </div>
            ),
        },
        ...(isAdmin
            ? [
                  {
                      key: 'portal',
                      header: 'Portal',
                      cell: (user: UserRow) => PORTAL_LABELS[user.type],
                  },
                  {
                      key: 'organisation',
                      header: 'Partner / branch',
                      cell: (user: UserRow) =>
                          user.organisation ? (
                              <span>
                                  {user.organisation.name}{' '}
                                  <span className="font-mono text-xs text-tx3">
                                      {user.organisation.code}
                                  </span>
                              </span>
                          ) : (
                              <span className="text-tx3">—</span>
                          ),
                  },
              ]
            : []),
        { key: 'role', header: 'Role', cell: (user) => user.role.name },
        {
            key: 'status',
            header: 'Status',
            cell: (user) => <StatusBadge status={user.status} />,
        },
        {
            key: '2fa',
            header: '2FA',
            cell: (user) =>
                user.two_factor ? (
                    <span className="text-ok">On</span>
                ) : (
                    <span className="text-tx3">Off</span>
                ),
        },
        {
            key: 'last_login',
            header: 'Last login',
            cell: (user) => (
                <span className="text-tx2">
                    {formatRelative(user.last_login_at)}
                </span>
            ),
        },
        {
            key: 'open',
            header: '',
            align: 'right',
            cell: () => <ChevronRight className="inline size-4 text-tx3" />,
        },
    ];

    return (
        <>
            <Head title="Users" />

            <PageHeader
                title="Users"
                description={
                    isAdmin
                        ? 'Everyone who can log in to the Admin, Partner and Branch portals. Users are suspended, never deleted.'
                        : `People who can log in to your ${PORTAL_LABELS[portal].toLowerCase()} portal.`
                }
                actions={
                    can.create && (
                        <PgButton
                            variant="primary"
                            onClick={() => setDialog('create')}
                        >
                            Add user
                        </PgButton>
                    )
                }
            />

            <Panel>
                <ViewTabs
                    views={STATUS_TABS.map((tab) => ({
                        key: tab.key,
                        label: tab.label,
                        count: counts[tab.key],
                    }))}
                    active={filters.status ?? 'all'}
                    onChange={(key) =>
                        applyFilters({
                            status:
                                key === 'all'
                                    ? null
                                    : (key as Filters['status']),
                        })
                    }
                />
                <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    <label className="flex h-[30px] w-[260px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                        <Search className="size-3.5" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search name or email"
                            className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                        />
                    </label>
                    {isAdmin && (
                        <Segmented
                            options={[
                                {
                                    value: 'all',
                                    label: `All portals · ${counts.all ?? 0}`,
                                },
                                ...(
                                    ['admin', 'partner', 'branch'] as const
                                ).map((type) => ({
                                    value: type,
                                    label: `${PORTAL_LABELS[type]} · ${counts[type] ?? 0}`,
                                })),
                            ]}
                            value={filters.type ?? 'all'}
                            onChange={(value) =>
                                applyFilters({
                                    type: value === 'all' ? null : value,
                                })
                            }
                        />
                    )}
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">
                        {users.total} {users.total === 1 ? 'user' : 'users'}
                    </span>
                </div>
                <DataTable
                    columns={columns}
                    rows={users.data}
                    rowKey={(user) => user.id}
                    onRowClick={(user) => setOpenId(user.id)}
                    empty={
                        <EmptyState
                            title="No users found"
                            description="Try another search or filter."
                        />
                    }
                />
                {users.last_page > 1 && (
                    <div className="flex items-center justify-between px-3 py-2.5 text-xs text-tx3">
                        <span>
                            Showing {users.from}–{users.to} of {users.total}
                        </span>
                        <div className="flex gap-2">
                            {users.prev_page_url && (
                                <Link
                                    href={users.prev_page_url}
                                    preserveScroll
                                    className="rounded-[7px] border border-ln px-2.5 py-1 text-tx"
                                >
                                    Previous
                                </Link>
                            )}
                            {users.next_page_url && (
                                <Link
                                    href={users.next_page_url}
                                    preserveScroll
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
                    kind="User"
                    title={open.name}
                    monoTitle={false}
                    status={<StatusBadge status={open.status} />}
                    subtitle={open.email}
                    summaries={[
                        { label: 'Role', value: open.role.name },
                        {
                            label: 'Two-factor',
                            value: open.two_factor ? 'On' : 'Off',
                            tone: open.two_factor ? 'text-ok' : 'text-tx3',
                        },
                        {
                            label: 'Last login',
                            value: formatRelative(open.last_login_at),
                        },
                    ]}
                >
                    <KeyValues
                        items={[
                            {
                                label: 'Portal',
                                value: PORTAL_LABELS[open.type],
                            },
                            {
                                label: 'Partner / branch',
                                value: open.organisation
                                    ? `${open.organisation.name} (${open.organisation.code})`
                                    : null,
                            },
                            { label: 'Email', value: open.email },
                            {
                                label: 'Last login at',
                                value: formatDateTime(open.last_login_at),
                            },
                            {
                                label: 'Last login IP',
                                value: open.last_login_ip,
                                mono: true,
                            },
                            {
                                label: 'Created',
                                value: formatDateTime(open.created_at),
                            },
                        ]}
                    />

                    {open.can.update ? (
                        <div className="flex flex-wrap gap-2 border-t border-ln2 pt-4">
                            <PgButton onClick={() => setDialog('edit')}>
                                Edit
                            </PgButton>
                            <PgButton onClick={() => setDialog('password')}>
                                Set password
                            </PgButton>
                            {open.status === 'invited' && (
                                <PgButton
                                    disabled={processing}
                                    onClick={() =>
                                        act(
                                            'post',
                                            routes.invitation(open.id).url,
                                        )
                                    }
                                >
                                    Resend invitation
                                </PgButton>
                            )}
                            {open.two_factor && (
                                <PgButton
                                    onClick={() => setDialog('two-factor')}
                                >
                                    Reset two-factor
                                </PgButton>
                            )}
                            {open.status === 'suspended' ? (
                                <PgButton
                                    onClick={() => setDialog('reactivate')}
                                >
                                    Reactivate
                                </PgButton>
                            ) : (
                                <PgButton
                                    variant="danger"
                                    onClick={() => setDialog('suspend')}
                                >
                                    Suspend
                                </PgButton>
                            )}
                        </div>
                    ) : (
                        <p className="border-t border-ln2 pt-4 text-xs text-tx3">
                            You can’t change this user: it is your own account
                            (use Profile & settings), or their role has
                            permissions you don’t hold.
                        </p>
                    )}
                </Drawer>
            )}

            {dialog === 'create' && (
                <UserFormDialog
                    portal={portal}
                    roles={roles}
                    organisations={organisations}
                    url={routes.store().url}
                    onClose={() => setDialog(null)}
                />
            )}

            {dialog === 'edit' && open && (
                <UserFormDialog
                    portal={portal}
                    roles={roles}
                    organisations={organisations}
                    user={open}
                    url={routes.update(open.id).url}
                    onClose={() => setDialog(null)}
                />
            )}

            {dialog === 'password' && open && (
                <PasswordDialog
                    user={open}
                    url={routes.password(open.id).url}
                    onClose={() => setDialog(null)}
                />
            )}

            {open && (
                <>
                    <ConfirmDialog
                        open={dialog === 'suspend'}
                        onOpenChange={(next) => !next && setDialog(null)}
                        title={`Suspend ${open.name}?`}
                        description="They are logged out immediately and can’t log in until reactivated. Their history is kept."
                        confirmLabel="Suspend user"
                        tone="danger"
                        input={{
                            label: 'Reason (kept in the audit log)',
                            required: true,
                        }}
                        processing={processing}
                        onConfirm={(reason) =>
                            act('put', routes.status(open.id).url, {
                                status: 'suspended',
                                reason,
                            })
                        }
                    />
                    <ConfirmDialog
                        open={dialog === 'reactivate'}
                        onOpenChange={(next) => !next && setDialog(null)}
                        title={`Reactivate ${open.name}?`}
                        description="They can log in again with their current role."
                        confirmLabel="Reactivate"
                        input={{
                            label: 'Reason (kept in the audit log)',
                            required: true,
                        }}
                        processing={processing}
                        onConfirm={(reason) =>
                            act('put', routes.status(open.id).url, {
                                status: 'active',
                                reason,
                            })
                        }
                    />
                    <ConfirmDialog
                        open={dialog === 'two-factor'}
                        onOpenChange={(next) => !next && setDialog(null)}
                        title={`Reset two-factor for ${open.name}?`}
                        description="Use this when they lost their phone. They must set up two-factor again at their next login."
                        confirmLabel="Reset two-factor"
                        tone="warning"
                        input={{
                            label: 'Reason (kept in the audit log)',
                            required: true,
                        }}
                        processing={processing}
                        onConfirm={(reason) =>
                            act('delete', routes.twoFactor(open.id).url, {
                                reason,
                            })
                        }
                    />
                </>
            )}
        </>
    );
}

/** Add a new user with a password, or edit one (name, email, role). */
function UserFormDialog({
    portal,
    roles,
    organisations,
    user,
    url,
    onClose,
}: {
    portal: UserType;
    roles: RoleOption[];
    organisations: Props['organisations'];
    user?: UserRow;
    url: string;
    onClose: () => void;
}) {
    const editing = user !== undefined;
    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        type: user?.type ?? portal,
        role_id: user?.role.id ?? '',
        organisation_id: '',
        password: '',
        password_confirmation: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const roleOptions = roles.filter(
        (role) => role.user_type === form.data.type,
    );
    const organisationOptions =
        form.data.type === 'admin'
            ? []
            : (organisations?.[form.data.type] ?? []);
    // The current role may be one the editor can't hand out; keep it selectable.
    const currentRoleMissing =
        user !== undefined &&
        !roleOptions.some((role) => role.id === user.role.id);

    const submit = () => {
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onClose,
        };

        form.transform((data) => ({
            name: data.name,
            email: data.email,
            role_id: data.role_id,
            ...(!editing && organisations && data.type !== 'admin'
                ? { organisation_id: data.organisation_id }
                : {}),
            ...(!editing
                ? {
                      password: data.password,
                      password_confirmation: data.password_confirmation,
                  }
                : {}),
        }));

        if (editing) {
            form.put(url, options);
        } else {
            form.post(url, options);
        }
    };

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            title={editing ? `Edit ${user.name}` : 'Add user'}
            description={
                editing
                    ? 'A changed email address must be verified again by the user.'
                    : 'They can log in straight away with this email and password. Pass the password on securely; they can change it under Profile & settings.'
            }
            submitLabel={editing ? 'Save' : 'Add user'}
            processing={form.processing}
            onSubmit={submit}
        >
            <Field label="Full name" error={errors.name}>
                <TextInput
                    autoFocus
                    required
                    value={form.data.name}
                    invalid={!!errors.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </Field>
            <Field label="Email" error={errors.email}>
                <TextInput
                    type="email"
                    required
                    value={form.data.email}
                    invalid={!!errors.email}
                    onChange={(event) =>
                        form.setData('email', event.target.value.toLowerCase())
                    }
                />
            </Field>
            {!editing && organisations && (
                <Field label="Portal">
                    <SelectInput
                        value={form.data.type}
                        onChange={(event) =>
                            form.setData({
                                ...form.data,
                                type: event.target.value as UserType,
                                role_id: '',
                                organisation_id: '',
                            })
                        }
                    >
                        <option value="admin">Admin (our staff)</option>
                        <option value="partner">Partner</option>
                        <option value="branch">Branch</option>
                    </SelectInput>
                </Field>
            )}
            {!editing && organisations && form.data.type !== 'admin' && (
                <Field
                    label={form.data.type === 'partner' ? 'Partner' : 'Branch'}
                    error={errors.organisation_id}
                >
                    <SelectInput
                        required
                        value={form.data.organisation_id}
                        invalid={!!errors.organisation_id}
                        onChange={(event) =>
                            form.setData('organisation_id', event.target.value)
                        }
                    >
                        <option value="">Choose…</option>
                        {organisationOptions.map((organisation) => (
                            <option
                                key={organisation.id}
                                value={organisation.id}
                            >
                                {organisation.name} ({organisation.code})
                            </option>
                        ))}
                    </SelectInput>
                </Field>
            )}
            <Field
                label="Role"
                hint="Only roles with permissions you hold yourself are listed."
                error={errors.role_id}
            >
                <SelectInput
                    required
                    value={form.data.role_id}
                    invalid={!!errors.role_id}
                    onChange={(event) =>
                        form.setData('role_id', event.target.value)
                    }
                >
                    <option value="">Choose…</option>
                    {currentRoleMissing && (
                        <option value={user.role.id}>{user.role.name}</option>
                    )}
                    {roleOptions.map((role) => (
                        <option key={role.id} value={role.id}>
                            {role.name}
                        </option>
                    ))}
                </SelectInput>
            </Field>
            {!editing && (
                <PasswordFields
                    password={form.data.password}
                    confirmation={form.data.password_confirmation}
                    error={errors.password}
                    onChange={(field, value) => form.setData(field, value)}
                />
            )}
        </FormDialog>
    );
}

/** Set a new password for another user (forgotten, or never received the invitation). */
function PasswordDialog({
    user,
    url,
    onClose,
}: {
    user: UserRow;
    url: string;
    onClose: () => void;
}) {
    const form = useForm({ password: '', password_confirmation: '' });

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onClose()}
            title={`Set password for ${user.name}`}
            description="Their current password stops working. Pass the new one on securely; they can change it under Profile & settings."
            submitLabel="Set password"
            processing={form.processing}
            onSubmit={() =>
                form.put(url, { preserveScroll: true, onSuccess: onClose })
            }
        >
            <PasswordFields
                password={form.data.password}
                confirmation={form.data.password_confirmation}
                error={form.errors.password}
                onChange={(field, value) => form.setData(field, value)}
            />
        </FormDialog>
    );
}

function PasswordFields({
    password,
    confirmation,
    error,
    onChange,
}: {
    password: string;
    confirmation: string;
    error?: string;
    onChange: (
        field: 'password' | 'password_confirmation',
        value: string,
    ) => void;
}) {
    return (
        <>
            <Field
                label="Password"
                hint="At least 12 characters with upper and lower case letters, a number and a symbol."
                error={error}
            >
                <TextInput
                    type="password"
                    required
                    autoComplete="new-password"
                    value={password}
                    invalid={!!error}
                    onChange={(event) =>
                        onChange('password', event.target.value)
                    }
                />
            </Field>
            <Field label="Confirm password">
                <TextInput
                    type="password"
                    required
                    autoComplete="new-password"
                    value={confirmation}
                    onChange={(event) =>
                        onChange('password_confirmation', event.target.value)
                    }
                />
            </Field>
        </>
    );
}
