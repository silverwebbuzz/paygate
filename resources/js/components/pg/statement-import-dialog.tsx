import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import adminImports from '@/routes/admin/statement-imports';
import branchImports from '@/routes/branch/statement-imports';
import type { UserType } from '@/types';
import { Field, SelectInput, TextInput } from './field';
import { FormDialog } from './form-dialog';
import { Segmented } from './segmented';

export type StatementAccount = {
    id: string;
    label: string;
    bank: string | null;
    number: string | null;
    branch?: string;
};

type Mapping = {
    header_row: number;
    date: number | null;
    date_format: string;
    description: number | null;
    utr: number | null;
    credit: number | null;
    debit: number | null;
    amount: number | null;
    type: number | null;
    balance: number | null;
};

/** Flashed by StatementImportController::preview (step 1 → 2). */
type Preview = {
    token: string;
    file: string;
    account: StatementAccount;
    headers: string[];
    rows: string[][];
    mapping: Mapping;
    layout: string | null;
    date_formats: string[];
};

const ROLES: [keyof Mapping, string][] = [
    ['date', 'Date'],
    ['description', 'Description'],
    ['utr', 'UTR'],
    ['credit', 'Credit'],
    ['debit', 'Debit'],
    ['amount', 'Amount'],
    ['type', 'Cr/Dr'],
    ['balance', 'Balance'],
];

/**
 * Statement import (design: "Import statement"): 1) choose the account and
 * the bank's CSV / Excel file; 2) check which column is which (guessed from
 * the headers, or the mapping saved for this bank's layout), then import.
 */
export function StatementImportDialog({
    portal,
    accounts,
    open,
    onOpenChange,
}: {
    portal: UserType;
    accounts: StatementAccount[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const routes = portal === 'admin' ? adminImports : branchImports;
    const flashed =
        (usePage().flash as { import_preview?: Preview }).import_preview ??
        null;
    const [preview, setPreview] = useState<Preview | null>(null);
    const [dismissed, setDismissed] = useState<string | null>(null);
    const upload = useForm<{ payment_account_id: string; file: File | null }>({
        payment_account_id: accounts.length === 1 ? accounts[0].id : '',
        file: null,
    });

    // Keep the preview once it arrives: later reloads carry no flash data.
    useEffect(() => {
        if (
            flashed &&
            flashed.token !== preview?.token &&
            flashed.token !== dismissed
        ) {
            setPreview(flashed);
        }
    }, [flashed, preview?.token, dismissed]);

    const finish = () => {
        setDismissed(preview?.token ?? null);
        setPreview(null);
        upload.reset('file');
        onOpenChange(false);
    };

    const close = () => {
        if (preview) {
            router.delete(routes.cancel(preview.token).url, {
                preserveScroll: true,
                preserveState: true,
            });
        }

        finish();
    };

    // A flashed preview opens the mapping step even if the page re-rendered.
    if (preview) {
        return (
            <MappingStep
                portal={portal}
                preview={preview}
                onCancel={close}
                onDone={finish}
            />
        );
    }

    if (!open) return null;

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && close()}
            title="Import statement"
            description="Upload the account’s statement exactly as the bank gives it (CSV or Excel). Lines already in the statement are skipped, so overlapping periods are fine."
            submitLabel={upload.processing ? 'Reading…' : 'Continue'}
            processing={upload.processing || !upload.data.file}
            onSubmit={() =>
                upload.post(routes.preview().url, {
                    preserveScroll: true,
                    preserveState: true,
                })
            }
        >
            <Field label="Account" error={upload.errors.payment_account_id}>
                <SelectInput
                    required
                    value={upload.data.payment_account_id}
                    onChange={(event) =>
                        upload.setData('payment_account_id', event.target.value)
                    }
                >
                    <option value="">Choose…</option>
                    {accounts.map((account) => (
                        <option key={account.id} value={account.id}>
                            {accountLabel(account)}
                        </option>
                    ))}
                </SelectInput>
            </Field>
            <Field
                label="Statement file"
                hint=".csv, .xls or .xlsx, up to 5 MB and 5,000 lines."
                error={upload.errors.file}
            >
                <input
                    type="file"
                    required
                    accept=".csv,.txt,.xls,.xlsx"
                    onChange={(event) =>
                        upload.setData('file', event.target.files?.[0] ?? null)
                    }
                    className="text-[13px] file:mr-3 file:h-8 file:rounded-[7px] file:border file:border-ln file:bg-sf file:px-3 file:text-[13px] file:font-medium"
                />
            </Field>
        </FormDialog>
    );
}

export function accountLabel(account: StatementAccount): string {
    return [
        account.branch,
        account.label,
        account.bank && `${account.bank} ${account.number ?? ''}`.trim(),
    ]
        .filter(Boolean)
        .join(' · ');
}

function MappingStep({
    portal,
    preview,
    onCancel,
    onDone,
}: {
    portal: UserType;
    preview: Preview;
    onCancel: () => void;
    onDone: () => void;
}) {
    const routes = portal === 'admin' ? adminImports : branchImports;
    const form = useForm({
        token: preview.token,
        layout: preview.layout ?? preview.account.bank ?? '',
        mapping: preview.mapping,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const mapping = form.data.mapping;
    const [mode, setMode] = useState<'split' | 'single'>(
        preview.mapping.amount !== null ? 'single' : 'split',
    );
    const headerIndex = Math.max(1, mapping.header_row) - 1;
    const headers = preview.rows[headerIndex] ?? [];
    const width = Math.max(...preview.rows.map((row) => row.length), 0);
    const columns = Array.from({ length: width }, (_, index) => index);
    const set = (key: keyof Mapping, value: number | string | null) =>
        form.setData('mapping', { ...mapping, [key]: value });
    const roleOf = (column: number) =>
        ROLES.filter(
            ([key]) =>
                mapping[key] === column &&
                (mode === 'single'
                    ? key !== 'credit' && key !== 'debit'
                    : key !== 'amount' && key !== 'type'),
        ).map(([, label]) => label);

    const columnSelect = (key: keyof Mapping, label: string, hint?: string) => (
        <Field label={label} hint={hint} error={errors[`mapping.${key}`]}>
            <SelectInput
                value={mapping[key] ?? ''}
                onChange={(event) =>
                    set(
                        key,
                        event.target.value === ''
                            ? null
                            : Number(event.target.value),
                    )
                }
            >
                <option value="">— none —</option>
                {columns.map((column) => (
                    <option key={column} value={column}>
                        {columnName(column)} · {headers[column] || '(empty)'}
                    </option>
                ))}
            </SelectInput>
        </Field>
    );

    const submit = () => {
        form.transform((data) => ({
            ...data,
            mapping: {
                ...data.mapping,
                ...(mode === 'single'
                    ? { credit: null, debit: null }
                    : { amount: null, type: null }),
            },
        }));
        form.post(routes.store().url, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onCancel()}
            width={860}
            title="Check the columns"
            description={
                <>
                    <b>{preview.file}</b> for {accountLabel(preview.account)}.{' '}
                    {preview.layout
                        ? `Using the saved layout “${preview.layout}”.`
                        : 'Columns were guessed from the headers: check them once; the layout is remembered for the next file from this bank.'}
                </>
            }
            submitLabel={form.processing ? 'Importing…' : 'Import lines'}
            processing={form.processing}
            onSubmit={submit}
        >
            {(errors.file || errors.mapping || errors.token) && (
                <div className="rounded-lg bg-erb px-3 py-2.5 text-[12.5px] text-er">
                    {errors.file ?? errors.mapping ?? errors.token}
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-3">
                <Field label="Header row" hint="The row with the column names.">
                    <TextInput
                        type="number"
                        min={1}
                        max={preview.rows.length}
                        value={mapping.header_row}
                        onChange={(event) =>
                            set(
                                'header_row',
                                Math.max(1, Number(event.target.value) || 1),
                            )
                        }
                    />
                </Field>
                {columnSelect('date', 'Date column')}
                <Field label="Date format" hint="As written in the file.">
                    <SelectInput
                        value={mapping.date_format}
                        onChange={(event) =>
                            set('date_format', event.target.value)
                        }
                    >
                        {preview.date_formats.map((format) => (
                            <option key={format} value={format}>
                                {format
                                    .replace('d', 'dd')
                                    .replace('m', 'mm')
                                    .replace('M', 'Mon')
                                    .replace('Y', 'yyyy')
                                    .replace('y', 'yy')}
                            </option>
                        ))}
                    </SelectInput>
                </Field>
                {columnSelect('description', 'Description / narration')}
                {columnSelect(
                    'utr',
                    'UTR / reference column',
                    'Optional: the UTR is also found in the description.',
                )}
                {columnSelect('balance', 'Balance column', 'Optional.')}
            </div>

            <div className="flex flex-col gap-3 rounded-lg border border-ln p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-[12.5px] font-medium text-tx2">
                        How the file shows amounts
                    </span>
                    <Segmented
                        options={[
                            {
                                value: 'split',
                                label: 'Credit and debit columns',
                            },
                            { value: 'single', label: 'One amount column' },
                        ]}
                        value={mode}
                        onChange={setMode}
                    />
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    {mode === 'split' ? (
                        <>
                            {columnSelect('credit', 'Credit (money in)')}
                            {columnSelect('debit', 'Debit (money out)')}
                        </>
                    ) : (
                        <>
                            {columnSelect('amount', 'Amount')}
                            {columnSelect(
                                'type',
                                'Cr / Dr column',
                                'Optional: without it, a minus sign means debit.',
                            )}
                        </>
                    )}
                </div>
            </div>

            <div className="overflow-x-auto rounded-lg border border-ln">
                <table className="w-full min-w-[600px] border-collapse text-xs">
                    <thead>
                        <tr className="bg-sf2 text-left text-tx3">
                            <th className="px-2 py-1.5 font-medium">Row</th>
                            {columns.map((column) => (
                                <th
                                    key={column}
                                    className="px-2 py-1.5 font-medium whitespace-nowrap"
                                >
                                    {columnName(column)}
                                    {roleOf(column).length > 0 && (
                                        <span className="ml-1 rounded bg-acs px-1.5 py-px text-[11px] text-act">
                                            {roleOf(column).join(', ')}
                                        </span>
                                    )}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {preview.rows
                            .slice(headerIndex, headerIndex + 8)
                            .map((row, index) => (
                                <tr
                                    key={index}
                                    className={cn(
                                        'border-t border-ln2',
                                        index === 0 && 'font-semibold',
                                    )}
                                >
                                    <td className="px-2 py-1.5 text-tx3">
                                        {headerIndex + index + 1}
                                    </td>
                                    {columns.map((column) => (
                                        <td
                                            key={column}
                                            className={cn(
                                                'max-w-[220px] truncate px-2 py-1.5',
                                                roleOf(column).length > 0 &&
                                                    'bg-acs/40',
                                            )}
                                        >
                                            {row[column] ?? ''}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                    </tbody>
                </table>
            </div>

            <Field
                label="Layout name"
                hint="Remembered for files with the same columns, e.g. “HDFC Bank”."
                error={errors.layout}
            >
                <TextInput
                    value={form.data.layout}
                    onChange={(event) =>
                        form.setData('layout', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}

/** 0 → A, 25 → Z, 26 → AA (spreadsheet column letters). */
function columnName(index: number): string {
    let name = '';

    for (let n = index + 1; n > 0; n = Math.floor((n - 1) / 26)) {
        name = String.fromCharCode(65 + ((n - 1) % 26)) + name;
    }

    return name;
}
