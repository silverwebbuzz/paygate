import { Head, router, useForm } from '@inertiajs/react';
import { Check, Lock, Search } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { PgButton } from '@/components/pg/button';
import { ConfirmDialog } from '@/components/pg/confirm-dialog';
import { Panel } from '@/components/pg/data-table';
import { Field, SelectInput, TextArea, TextInput } from '@/components/pg/field';
import { ViewTabs } from '@/components/pg/filter-bar';
import { FormDialog } from '@/components/pg/form-dialog';
import { PageHeader } from '@/components/pg/page-header';
import { StatusBadge } from '@/components/pg/status-badge';
import { cn } from '@/lib/utils';
import rolesRoutes from '@/routes/admin/roles';

type PortalType = 'admin' | 'partner' | 'branch';

type Role = {
    id: string;
    name: string;
    description: string | null;
    is_system: boolean;
    is_locked: boolean;
    status: 'active' | 'inactive';
    users_count: number;
    permissions: string[];
    can: { update: boolean; delete: boolean };
};

type MenuRow = {
    key: string;
    label: string;
    group: string;
    permissions: { value: string; action: string }[];
};

type Props = {
    type: PortalType;
    counts: Partial<Record<PortalType, number>>;
    roles: Role[];
    selected: string | null;
    menus: MenuRow[];
    grantable: string[];
    can: { create: boolean };
};

/** Grid columns (design: View, Insert, Update, Delete); other actions go in "Other". */
const COLUMNS = [
    ['view', 'View'],
    ['create', 'Insert'],
    ['update', 'Update'],
    ['delete', 'Delete'],
] as const;

const SPECIAL_LABELS: Record<string, string> = {
    approve: 'Approve',
    verify: 'Verify',
    process: 'Process',
    resolve: 'Resolve',
    export: 'Export',
};

const TYPE_TABS: { key: PortalType; label: string }[] = [
    { key: 'admin', label: 'Admin roles' },
    { key: 'partner', label: 'Partner roles' },
    { key: 'branch', label: 'Branch roles' },
];

const firstError = (errors: Record<string, string>) =>
    toast.error(Object.values(errors)[0] ?? 'Something went wrong.');

export default function RolesIndex({
    type,
    counts,
    roles,
    selected,
    menus,
    grantable,
    can,
}: Props) {
    const [currentId, setCurrentId] = useState(
        (roles.find((role) => role.id === selected) ?? roles[0])?.id,
    );
    const current = roles.find((role) => role.id === currentId) ?? roles[0];
    const [granted, setGranted] = useState(
        () => new Set(current?.permissions ?? []),
    );
    const [search, setSearch] = useState('');
    const [dialog, setDialog] = useState<
        null | 'create' | 'duplicate' | 'edit'
    >(null);
    const [pendingRole, setPendingRole] = useState<Role | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [saving, setSaving] = useState(false);

    const available = menus.flatMap((menu) =>
        menu.permissions.map((permission) => permission.value),
    );
    const dirty =
        current !== undefined &&
        (granted.size !== current.permissions.length ||
            current.permissions.some((value) => !granted.has(value)));
    const editable = current?.can.update === true;

    const selectRole = (role: Role, force = false) => {
        if (dirty && !force) {
            setPendingRole(role);

            return;
        }

        setCurrentId(role.id);
        setGranted(new Set(role.permissions));
        setPendingRole(null);
    };

    const toggle = (values: string[], on: boolean) => {
        const next = new Set(granted);
        values
            .filter((value) => grantable.includes(value))
            .forEach((value) => (on ? next.add(value) : next.delete(value)));
        setGranted(next);
    };

    const save = () => {
        if (!current) return;

        router.put(
            rolesRoutes.update(current.id).url,
            {
                name: current.name,
                description: current.description,
                status: current.status,
                permissions: [...granted],
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: firstError,
            },
        );
    };

    const visibleRoles = roles.filter((role) =>
        role.name.toLowerCase().includes(search.trim().toLowerCase()),
    );

    return (
        <>
            <Head title="Roles & Permissions" />

            <PageHeader
                title="Roles & Permissions"
                description={`${roles.length} ${type} roles. Permissions apply per menu: View, Insert, Update, Delete, plus special actions such as Approve.`}
                actions={
                    <>
                        {can.create && (
                            <PgButton onClick={() => setDialog('create')}>
                                New role
                            </PgButton>
                        )}
                        {can.create && current && (
                            <PgButton onClick={() => setDialog('duplicate')}>
                                Duplicate role
                            </PgButton>
                        )}
                        {editable && (
                            <PgButton
                                variant="primary"
                                disabled={!dirty || saving}
                                onClick={save}
                            >
                                Save changes
                            </PgButton>
                        )}
                    </>
                }
            />

            <Panel>
                <ViewTabs
                    views={TYPE_TABS.map((tab) => ({
                        ...tab,
                        count: counts[tab.key] ?? 0,
                    }))}
                    active={type}
                    onChange={(key) =>
                        router.get(
                            rolesRoutes.index({ query: { type: key } }).url,
                        )
                    }
                />
            </Panel>

            <div className="grid items-start gap-3 lg:grid-cols-[260px_minmax(0,1fr)]">
                <Panel className="overflow-hidden">
                    <div className="border-b border-ln2 px-3 py-2.5">
                        <label className="flex h-[30px] items-center gap-2 rounded-[7px] border border-ln px-2.5 text-tx3 focus-within:border-ac">
                            <Search className="size-3.5" />
                            <input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Search roles"
                                className="w-full bg-transparent text-[12.5px] text-tx outline-none placeholder:text-tx3"
                            />
                        </label>
                    </div>
                    {visibleRoles.map((role) => (
                        <button
                            key={role.id}
                            type="button"
                            onClick={() => selectRole(role)}
                            className={cn(
                                'flex w-full items-center justify-between gap-2 border-b border-ln2 px-3.5 py-[9px] text-left last:border-b-0 hover:bg-sf2',
                                role.id === current?.id && 'bg-acs',
                            )}
                        >
                            <span className="min-w-0">
                                <span
                                    className={cn(
                                        'block truncate',
                                        role.id === current?.id
                                            ? 'font-semibold'
                                            : 'font-medium',
                                        role.status === 'inactive' &&
                                            'text-tx3 line-through',
                                    )}
                                >
                                    {role.name}
                                </span>
                                <span className="block text-xs text-tx3">
                                    {role.users_count}{' '}
                                    {role.users_count === 1 ? 'user' : 'users'}
                                </span>
                            </span>
                            <span className="flex items-center gap-1 text-[11px] text-tx3">
                                {role.is_locked && <Lock className="size-3" />}
                                {role.is_system && 'System'}
                            </span>
                        </button>
                    ))}
                    {visibleRoles.length === 0 && (
                        <div className="px-3.5 py-6 text-center text-xs text-tx3">
                            No roles match “{search}”.
                        </div>
                    )}
                </Panel>

                {current && (
                    <Panel className="overflow-hidden">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-ln2 px-4 py-3">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 text-sm font-semibold">
                                    {current.name}
                                    {current.status === 'inactive' && (
                                        <StatusBadge status="inactive" />
                                    )}
                                </div>
                                <div className="mt-0.5 text-xs text-tx3">
                                    {granted.size} of {available.length}{' '}
                                    permissions granted · {current.users_count}{' '}
                                    {current.users_count === 1
                                        ? 'user'
                                        : 'users'}
                                    {current.description &&
                                        ` · ${current.description}`}
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                {dirty && (
                                    <span className="text-xs font-medium text-wn">
                                        Unsaved changes
                                    </span>
                                )}
                                {editable && (
                                    <PgButton
                                        variant="ghost"
                                        onClick={() => setDialog('edit')}
                                    >
                                        Edit details
                                    </PgButton>
                                )}
                                {current.can.delete && (
                                    <PgButton
                                        variant="danger"
                                        onClick={() => setConfirmDelete(true)}
                                    >
                                        Delete
                                    </PgButton>
                                )}
                                {editable && !dirty && (
                                    <span className="text-xs text-tx3">
                                        Click a cell to toggle
                                    </span>
                                )}
                            </div>
                        </div>

                        {!editable && (
                            <div className="flex items-center gap-2 border-b border-ln2 bg-sf2 px-4 py-2 text-xs text-tx2">
                                <Lock className="size-3.5" />
                                {current.is_locked
                                    ? 'Built-in super admin role: it always has every permission and cannot be changed.'
                                    : 'Read-only: this role has permissions you do not hold, or you cannot edit roles.'}
                            </div>
                        )}

                        <PermissionGrid
                            menus={menus}
                            granted={granted}
                            grantable={grantable}
                            editable={editable}
                            onToggle={toggle}
                        />
                    </Panel>
                )}
            </div>

            <RoleDialog
                key={`${dialog}-${current?.id}`}
                mode={dialog}
                type={type}
                role={current}
                permissions={[...granted]}
                onClose={() => setDialog(null)}
            />

            <ConfirmDialog
                open={pendingRole !== null}
                onOpenChange={(open) => !open && setPendingRole(null)}
                title="Discard unsaved changes?"
                description={`Your changes to “${current?.name}” have not been saved.`}
                confirmLabel="Discard"
                tone="warning"
                onConfirm={() => pendingRole && selectRole(pendingRole, true)}
            />

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={`Delete role “${current?.name}”?`}
                description="The role has no users. This cannot be undone."
                confirmLabel="Delete role"
                tone="danger"
                onConfirm={() =>
                    current &&
                    router.delete(rolesRoutes.destroy(current.id).url, {
                        onFinish: () => setConfirmDelete(false),
                        onError: firstError,
                    })
                }
            />
        </>
    );
}

function PermissionGrid({
    menus,
    granted,
    grantable,
    editable,
    onToggle,
}: {
    menus: MenuRow[];
    granted: Set<string>;
    grantable: string[];
    editable: boolean;
    onToggle: (values: string[], on: boolean) => void;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] border-collapse text-[13px]">
                <thead>
                    <tr className="bg-sf2 text-xs text-tx3">
                        <th className="border-b border-ln px-4 py-2 text-left font-medium">
                            Menu
                        </th>
                        <th className="w-[70px] border-b border-ln p-2 font-medium">
                            All
                        </th>
                        {COLUMNS.map(([, label]) => (
                            <th
                                key={label}
                                className="w-[70px] border-b border-ln p-2 font-medium"
                            >
                                {label}
                            </th>
                        ))}
                        <th className="border-b border-ln p-2 text-left font-medium">
                            Other
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {menus.map((menu, index) => {
                        const values = menu.permissions.map((p) => p.value);
                        const all = values.every((value) => granted.has(value));
                        const specials = menu.permissions.filter(
                            (p) =>
                                !COLUMNS.some(
                                    ([action]) => action === p.action,
                                ),
                        );
                        const showGroup =
                            index === 0 ||
                            menus[index - 1].group !== menu.group;

                        return (
                            <tr key={menu.key} className="hover:bg-sf2">
                                <td className="border-b border-ln2 px-4 py-[7px]">
                                    {showGroup && (
                                        <span className="block text-[11px] text-tx3">
                                            {menu.group}
                                        </span>
                                    )}
                                    {menu.label}
                                </td>
                                <td className="border-b border-ln2 p-[7px] text-center">
                                    <Cell
                                        label={`All ${menu.label}`}
                                        checked={all}
                                        disabled={
                                            !editable ||
                                            !values.some((value) =>
                                                grantable.includes(value),
                                            )
                                        }
                                        onClick={() => onToggle(values, !all)}
                                    />
                                </td>
                                {COLUMNS.map(([action, label]) => {
                                    const permission = menu.permissions.find(
                                        (p) => p.action === action,
                                    );

                                    return (
                                        <td
                                            key={action}
                                            className="border-b border-ln2 p-[7px] text-center"
                                        >
                                            {permission ? (
                                                <Cell
                                                    label={`${label} ${menu.label}`}
                                                    checked={granted.has(
                                                        permission.value,
                                                    )}
                                                    disabled={
                                                        !editable ||
                                                        !grantable.includes(
                                                            permission.value,
                                                        )
                                                    }
                                                    onClick={() =>
                                                        onToggle(
                                                            [permission.value],
                                                            !granted.has(
                                                                permission.value,
                                                            ),
                                                        )
                                                    }
                                                />
                                            ) : (
                                                <span className="text-tx3">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                    );
                                })}
                                <td className="border-b border-ln2 p-[7px]">
                                    <div className="flex flex-wrap gap-3">
                                        {specials.map((permission) => (
                                            <span
                                                key={permission.value}
                                                className="inline-flex items-center gap-1.5 text-xs text-tx2"
                                            >
                                                <Cell
                                                    label={`${SPECIAL_LABELS[permission.action] ?? permission.action} ${menu.label}`}
                                                    checked={granted.has(
                                                        permission.value,
                                                    )}
                                                    disabled={
                                                        !editable ||
                                                        !grantable.includes(
                                                            permission.value,
                                                        )
                                                    }
                                                    onClick={() =>
                                                        onToggle(
                                                            [permission.value],
                                                            !granted.has(
                                                                permission.value,
                                                            ),
                                                        )
                                                    }
                                                />
                                                {SPECIAL_LABELS[
                                                    permission.action
                                                ] ?? permission.action}
                                            </span>
                                        ))}
                                    </div>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function Cell({
    label,
    checked,
    disabled,
    onClick,
}: {
    label: string;
    checked: boolean;
    disabled: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={checked}
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={onClick}
            className={cn(
                'inline-grid size-[18px] place-items-center rounded-[5px] border-[1.5px] text-white disabled:cursor-not-allowed',
                checked ? 'border-ac bg-ac' : 'border-ln bg-sf',
                disabled && 'opacity-60',
            )}
        >
            {checked && <Check className="size-3" strokeWidth={3} />}
        </button>
    );
}

function RoleDialog({
    mode,
    type,
    role,
    permissions,
    onClose,
}: {
    mode: null | 'create' | 'duplicate' | 'edit';
    type: PortalType;
    role: Role | undefined;
    permissions: string[];
    onClose: () => void;
}) {
    const form = useForm({
        name:
            mode === 'edit'
                ? (role?.name ?? '')
                : mode === 'duplicate'
                  ? `${role?.name ?? ''} copy`
                  : '',
        description: mode === 'create' ? '' : (role?.description ?? ''),
        status: role?.status ?? 'active',
    });

    const titles = {
        create: 'New role',
        duplicate: `Duplicate “${role?.name}”`,
        edit: `Edit “${role?.name}”`,
    };

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: onClose };

        if (mode === 'edit' && role) {
            form.transform((data) => ({ ...data, permissions }));
            form.put(rolesRoutes.update(role.id).url, options);

            return;
        }

        form.transform((data) => ({
            user_type: type,
            name: data.name,
            description: data.description,
            permissions: mode === 'duplicate' ? (role?.permissions ?? []) : [],
        }));
        form.post(rolesRoutes.store().url, options);
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormDialog
            open={mode !== null}
            onOpenChange={(open) => !open && onClose()}
            title={mode ? titles[mode] : ''}
            description={
                mode === 'create'
                    ? `A new ${type} role starts with no permissions; tick them in the grid afterwards.`
                    : mode === 'duplicate'
                      ? 'The new role starts with the same permissions.'
                      : undefined
            }
            submitLabel={mode === 'edit' ? 'Save' : 'Create role'}
            processing={form.processing}
            onSubmit={submit}
        >
            <Field label="Name" error={errors.name}>
                <TextInput
                    autoFocus
                    required
                    maxLength={100}
                    value={form.data.name}
                    invalid={!!errors.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </Field>
            <Field label="Description" error={errors.description}>
                <TextArea
                    maxLength={500}
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
            </Field>
            {mode === 'edit' && (
                <Field
                    label="Status"
                    hint="Users of an inactive role keep their login but have no permissions."
                    error={errors.status}
                >
                    <SelectInput
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData(
                                'status',
                                event.target.value as Role['status'],
                            )
                        }
                    >
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </SelectInput>
                </Field>
            )}
            {errors.permissions && (
                <p className="text-xs text-er">{errors.permissions}</p>
            )}
        </FormDialog>
    );
}
