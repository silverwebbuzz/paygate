import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { formatDate } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
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

/** One line of the file, read with the confirmed columns. */
type Line = {
    row: number;
    date: string;
    direction: 'credit' | 'debit';
    amount: number;
    utr: string | null;
    description: string | null;
    /**
     * Never imported: stored = already on this account; elsewhere = the UTR
     * is already on another bank line in PayGate (another account, branch
     * or amount: one UTR is one bank transfer); repeated = twice in this file.
     */
    duplicate: 'stored' | 'elsewhere' | 'repeated' | null;
    /**
     * What the import will do with it (ImportStatement::check): ok = it
     * will match; warn = it will go to Unsettled UTR, and why. Null for
     * duplicates.
     */
    check: { level: 'ok' | 'warn'; message: string } | null;
    /** The deposit / payout carrying this line's UTR, if any. */
    transaction: { reference: string; status: string } | null;
};

/** Flashed by StatementImportController::lines (step 2 → 3). */
type Lines = {
    token: string;
    lines: Line[];
    /**
     * Rows that break a rule (date, amount, no proper UTR) and are never
     * stored, with the field, the bad value and their cells (`text`).
     */
    errors: {
        row: number;
        field: 'date' | 'amount' | 'utr';
        value: string;
        message: string;
        text: string;
    }[];
    failed: number;
    /** Rows not imported, per rule, over the whole file. */
    failed_by: Partial<Record<'date' | 'amount' | 'utr', number>>;
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
 * the headers, or the mapping saved for this bank's layout); 3) tick the
 * lines that belong in PayGate (all unticked: a statement also carries
 * the owner's personal transfers, which are never stored).
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
    const form = useForm<{
        token: string;
        layout: string;
        mapping: Mapping;
        rows: number[];
    }>({
        token: preview.token,
        layout: preview.layout ?? preview.account.bank ?? '',
        mapping: preview.mapping,
        rows: [],
    });
    const flashedLines =
        (usePage().flash as { import_lines?: Lines }).import_lines ?? null;
    const [lines, setLines] = useState<Lines | null>(null);

    // The lines arrive as flash data after "Show lines".
    useEffect(() => {
        if (flashedLines && flashedLines.token === preview.token) {
            setLines(flashedLines);
            form.setData('rows', []);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flashedLines, preview.token]);
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

    const effective = (data: Mapping): Mapping => ({
        ...data,
        ...(mode === 'single'
            ? { credit: null, debit: null }
            : { amount: null, type: null }),
    });

    // Step 2 → 3: read every line with these columns.
    const showLines = () => {
        form.transform((data) => ({
            token: data.token,
            mapping: effective(data.mapping),
        }));
        form.post(routes.lines().url, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const importLines = () => {
        form.transform((data) => ({
            ...data,
            mapping: effective(data.mapping),
        }));
        form.post(routes.store().url, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    if (lines) {
        return (
            <LinesStep
                preview={preview}
                lines={lines}
                selected={form.data.rows}
                onSelect={(rows) => form.setData('rows', rows)}
                error={errors.rows ?? errors.mapping ?? errors.file}
                processing={form.processing}
                onBack={() => setLines(null)}
                onCancel={onCancel}
                onSubmit={importLines}
            />
        );
    }

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
            submitLabel={form.processing ? 'Reading…' : 'Show lines'}
            processing={form.processing}
            onSubmit={showLines}
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
                                    .replace(/d/, 'dd')
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
                    'UPI / IMPS: 12 digits. NEFT / RTGS: bank reference, e.g. HDFCN52026092812. If this column has none, the UTR is read from the narration; rows without a UTR are not imported.',
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

type Filter = 'all' | 'credit' | 'debit' | 'match' | 'problems';

/** A table row: a line of the file, or a row that couldn't be read. */
type TableRow =
    | { kind: 'line'; row: number; line: Line }
    | { kind: 'error'; row: number; error: Lines['errors'][number] };

/**
 * Step 3: every line of the file with a checkbox, all unticked. The branch
 * ticks the lines that belong in PayGate. Each line says beforehand what
 * the import will do with it (client request 2026-11-18): match a deposit
 * / payout, or go to Unsettled UTR and why. Lines already in PayGate and
 * rows that couldn't be read are shown in place and can't be ticked.
 */
function LinesStep({
    preview,
    lines,
    selected,
    onSelect,
    error,
    processing,
    onBack,
    onCancel,
    onSubmit,
}: {
    preview: Preview;
    lines: Lines;
    selected: number[];
    onSelect: (rows: number[]) => void;
    error?: string;
    processing: boolean;
    onBack: () => void;
    onCancel: () => void;
    onSubmit: () => void;
}) {
    const [filter, setFilter] = useState<Filter>('all');
    const [search, setSearch] = useState('');
    const ticked = useMemo(() => new Set(selected), [selected]);
    const count = (test: (line: Line) => boolean) =>
        lines.lines.filter(test).length;
    const matching = count((line) => line.check?.level === 'ok');
    const unsettled = count((line) => line.check?.level === 'warn');
    const duplicates = count((line) => line.duplicate !== null);
    const query = search.trim().toLowerCase();

    const rows: TableRow[] = useMemo(
        () =>
            [
                ...lines.lines.map((line): TableRow => ({
                    kind: 'line',
                    row: line.row,
                    line,
                })),
                ...lines.errors.map((problem): TableRow => ({
                    kind: 'error',
                    row: problem.row,
                    error: problem,
                })),
            ].sort((a, b) => a.row - b.row),
        [lines],
    );

    const shown = rows.filter((item) => {
        if (item.kind === 'error') {
            return (
                (filter === 'all' || filter === 'problems') &&
                (query === '' ||
                    item.error.text.toLowerCase().includes(query) ||
                    item.error.message.toLowerCase().includes(query))
            );
        }

        const line = item.line;
        const inFilter =
            filter === 'all' ||
            (filter === 'match'
                ? line.check?.level === 'ok'
                : filter === 'problems'
                  ? line.check?.level !== 'ok'
                  : line.direction === filter);

        return (
            inFilter &&
            (query === '' ||
                [
                    line.utr,
                    line.description,
                    String(line.amount / 100),
                    line.transaction?.reference,
                    line.check?.message,
                ].some((value) => value?.toLowerCase().includes(query)))
        );
    });
    const selectable = shown.flatMap((item) =>
        item.kind === 'line' && !item.line.duplicate ? [item.line] : [],
    );
    const allShownTicked =
        selectable.length > 0 &&
        selectable.every((line) => ticked.has(line.row));
    const chosen = lines.lines.filter((line) => ticked.has(line.row));
    const chosenUnsettled = chosen.filter(
        (line) => line.check?.level === 'warn',
    ).length;
    const credit = chosen
        .filter((line) => line.direction === 'credit')
        .reduce((sum, line) => sum + line.amount, 0);
    const debit = chosen
        .filter((line) => line.direction === 'debit')
        .reduce((sum, line) => sum + line.amount, 0);

    const toggle = (row: number) =>
        onSelect(
            ticked.has(row)
                ? selected.filter((value) => value !== row)
                : [...selected, row],
        );
    const toggleShown = () => {
        const shownRows = new Set(selectable.map((line) => line.row));

        onSelect(
            allShownTicked
                ? selected.filter((row) => !shownRows.has(row))
                : [...new Set([...selected, ...shownRows])],
        );
    };

    return (
        <FormDialog
            open
            onOpenChange={(next) => !next && onCancel()}
            width={1100}
            title="Choose the lines to import"
            description={
                <>
                    <b>{preview.file}</b> for {accountLabel(preview.account)}.
                    Tick only the lines that belong in PayGate: lines left
                    unticked (personal transfers…) are never stored. The Check
                    column says what will happen to each line. The same file can
                    be uploaded again later to add more.
                </>
            }
            submitLabel={
                processing
                    ? 'Importing…'
                    : `Import ${chosen.length} ${chosen.length === 1 ? 'line' : 'lines'}`
            }
            processing={processing || chosen.length === 0}
            onSubmit={onSubmit}
        >
            {error && (
                <div className="rounded-lg bg-erb px-3 py-2.5 text-[12.5px] text-er">
                    {error}
                </div>
            )}

            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-tx2">
                <span>
                    <b className="text-tx">{lines.lines.length}</b> lines
                </span>
                <span className="text-ok">
                    ✓ <b>{matching}</b> will match a deposit / payout
                </span>
                {unsettled > 0 && (
                    <span className="text-wn">
                        ⚠ <b>{unsettled}</b> would go to Unsettled UTR
                    </span>
                )}
                {duplicates > 0 && (
                    <span>
                        <b className="text-tx">{duplicates}</b> already in
                        PayGate (skipped)
                    </span>
                )}
                {lines.failed > 0 && (
                    <span className="text-er">
                        ✕ <b>{lines.failed}</b> can’t be imported
                    </span>
                )}
                <button
                    type="button"
                    onClick={onBack}
                    className="ml-auto text-[12.5px] font-medium text-act hover:underline"
                >
                    ← Change columns
                </button>
            </div>

            {lines.failed > 0 && (
                <div className="rounded-lg bg-erb px-3 py-2.5 text-[12.5px] text-er">
                    <b>
                        {lines.failed}{' '}
                        {lines.failed === 1 ? 'row breaks' : 'rows break'} our
                        rules and won’t be imported
                    </b>{' '}
                    (
                    {(
                        [
                            ['utr', 'no proper UTR'],
                            ['date', 'date not in the format'],
                            ['amount', 'amount unreadable'],
                        ] as const
                    )
                        .map(([field, label]) => [
                            lines.failed_by[field] ?? 0,
                            label,
                        ])
                        .filter(([total]) => total)
                        .map(([total, label]) => `${total} ${label}`)
                        .join(', ')}
                    ). Each one says why in the Check column. If many rows fail,
                    a column or the date format is usually wrong:{' '}
                    <button
                        type="button"
                        onClick={onBack}
                        className="font-medium underline underline-offset-2"
                    >
                        change the columns
                    </button>
                    . If only a few rows fail (totals, notes), you can ignore
                    them, or correct them in the file and upload it again.{' '}
                    <button
                        type="button"
                        onClick={() => setFilter('problems')}
                        className="font-medium underline underline-offset-2"
                    >
                        Show them
                    </button>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <Segmented<Filter>
                    options={[
                        { value: 'all', label: 'All' },
                        { value: 'credit', label: 'Credits' },
                        { value: 'debit', label: 'Debits' },
                        { value: 'match', label: `Will match (${matching})` },
                        {
                            value: 'problems',
                            label: `Problems (${unsettled + duplicates + lines.failed})`,
                        },
                    ]}
                    value={filter}
                    onChange={setFilter}
                />
                <div className="min-w-[200px] flex-1">
                    <TextInput
                        placeholder="Search UTR, narration, amount or problem"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
                {selected.length > 0 && (
                    <button
                        type="button"
                        onClick={() => onSelect([])}
                        className="h-8 rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                    >
                        Untick all
                    </button>
                )}
            </div>

            <div className="max-h-[440px] overflow-auto rounded-lg border border-ln">
                <table className="w-full min-w-[900px] border-collapse text-xs">
                    <thead className="sticky top-0 z-[1] bg-sf2">
                        <tr className="text-left text-tx3">
                            <th className="w-8 px-2 py-1.5">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-ac"
                                    aria-label="Tick every line shown"
                                    disabled={selectable.length === 0}
                                    checked={allShownTicked}
                                    onChange={toggleShown}
                                />
                            </th>
                            <th className="px-2 py-1.5 font-medium">Row</th>
                            <th className="px-2 py-1.5 font-medium">Date</th>
                            <th className="px-2 py-1.5 text-right font-medium">
                                Amount
                            </th>
                            <th className="px-2 py-1.5 font-medium">UTR</th>
                            <th className="px-2 py-1.5 font-medium">
                                Narration
                            </th>
                            <th className="w-[320px] px-2 py-1.5 font-medium">
                                Check
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {shown.map((item) =>
                            item.kind === 'error' ? (
                                <tr
                                    key={`e${item.row}`}
                                    className="border-t border-ln2 bg-erb/50 align-top"
                                >
                                    <td className="px-2 py-1.5">
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            aria-label={`Row ${item.row} can’t be imported`}
                                            disabled
                                        />
                                    </td>
                                    <td className="px-2 py-1.5 text-tx3">
                                        {item.row}
                                    </td>
                                    <td
                                        colSpan={4}
                                        className="max-w-[460px] truncate px-2 py-1.5 text-tx2"
                                        title={item.error.text}
                                    >
                                        {item.error.text || '(empty row)'}
                                    </td>
                                    <td className="px-2 py-1.5 text-er">
                                        ✕ Not imported: {item.error.message}
                                    </td>
                                </tr>
                            ) : (
                                <LineRow
                                    key={item.row}
                                    line={item.line}
                                    ticked={ticked.has(item.row)}
                                    onToggle={() => toggle(item.row)}
                                />
                            ),
                        )}
                        {shown.length === 0 && (
                            <tr>
                                <td
                                    colSpan={7}
                                    className="px-2 py-6 text-center text-tx3"
                                >
                                    No line matches.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <div className="text-[12.5px] text-tx2">
                {chosen.length === 0 ? (
                    'No line ticked yet.'
                ) : (
                    <>
                        Ticked: <b className="text-tx">{chosen.length}</b> ·
                        credits <b className="text-tx">{formatPaise(credit)}</b>
                        {debit > 0 && (
                            <>
                                {' '}
                                · debits{' '}
                                <b className="text-tx">{formatPaise(debit)}</b>
                            </>
                        )}
                        {chosenUnsettled > 0 && (
                            <span className="text-wn">
                                {' '}
                                · ⚠ {chosenUnsettled} of them will go to
                                Unsettled UTR (see Check)
                            </span>
                        )}
                    </>
                )}
            </div>
        </FormDialog>
    );
}

/** One line of the file in the Choose lines table. */
function LineRow({
    line,
    ticked,
    onToggle,
}: {
    line: Line;
    ticked: boolean;
    onToggle: () => void;
}) {
    const skipped = line.duplicate !== null;

    return (
        <tr
            onClick={() => !skipped && onToggle()}
            className={cn(
                'border-t border-ln2 align-top',
                skipped ? 'text-tx3' : 'cursor-pointer hover:bg-sf2',
                ticked && 'bg-acs/40',
            )}
        >
            <td className="px-2 py-1.5">
                <input
                    type="checkbox"
                    className="size-4 accent-ac"
                    aria-label={`Row ${line.row}`}
                    disabled={skipped}
                    checked={ticked}
                    onClick={(event) => event.stopPropagation()}
                    onChange={onToggle}
                />
            </td>
            <td className="px-2 py-1.5 text-tx3">{line.row}</td>
            <td className="px-2 py-1.5 whitespace-nowrap">
                {formatDate(line.date)}
            </td>
            <td
                className={cn(
                    'px-2 py-1.5 text-right whitespace-nowrap tabular-nums',
                    line.direction === 'credit' && !skipped && 'text-ok',
                )}
            >
                {line.direction === 'credit' ? '+' : '−'}
                {formatPaise(line.amount)}
            </td>
            <td className="px-2 py-1.5 font-mono whitespace-nowrap">
                {line.utr ?? '—'}
            </td>
            <td
                className="max-w-[240px] truncate px-2 py-1.5"
                title={line.description ?? ''}
            >
                {line.description ?? '—'}
            </td>
            <td
                className={cn(
                    'px-2 py-1.5',
                    line.check?.level === 'ok' && 'text-ok',
                    line.check?.level === 'warn' && 'text-wn',
                )}
            >
                {line.duplicate === 'stored'
                    ? 'Already in PayGate: skipped'
                    : line.duplicate === 'elsewhere'
                      ? 'UTR already on another bank line in PayGate (another account or branch): skipped. One UTR is one bank transfer: check you chose the right account.'
                      : line.duplicate === 'repeated'
                        ? 'Repeated in this file: skipped'
                        : line.check
                          ? `${line.check.level === 'ok' ? '✓' : '⚠'} ${line.check.message}`
                          : ''}
            </td>
        </tr>
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
