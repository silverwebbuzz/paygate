import { Head, Link, router } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { DataTable, Panel } from '@/components/pg/data-table';
import type { Column } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { SelectInput } from '@/components/pg/field';
import { FormDialog } from '@/components/pg/form-dialog';
import { PageHeader } from '@/components/pg/page-header';
import {
    accountLabel,
    StatementImportDialog,
} from '@/components/pg/statement-import-dialog';
import type { StatementAccount } from '@/components/pg/statement-import-dialog';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import adminImports from '@/routes/admin/statement-imports';
import adminStatements from '@/routes/admin/statements';
import branchImports from '@/routes/branch/statement-imports';
import branchStatements from '@/routes/branch/statements';
import { show as fileUrl } from '@/routes/files';
import type { UserType } from '@/types';

type ImportRow = {
    id: string;
    created_at: string;
    account: string;
    bank: string | null;
    number: string | null;
    branch: string;
    file: string | null;
    file_id: string | null;
    period_from: string | null;
    period_to: string | null;
    status: string;
    rows_total: number;
    rows_imported: number;
    rows_duplicate: number;
    rows_failed: number;
    credit_total: number;
    debit_total: number;
    errors: { row: number; message: string }[];
    imported_by: string | null;
};

type Props = {
    portal: UserType;
    imports: {
        data: ImportRow[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { branch: string | null; account: string | null };
    accounts: (StatementAccount & { branch_id: string })[];
    branches: { id: string; code: string; name: string }[];
    can: { create: boolean };
};

/** Statement History: every statement file imported, with what it added. */
export default function StatementImports(props: Props) {
    const { portal, imports, filters, accounts, branches, can } = props;
    const routes = portal === 'admin' ? adminImports : branchImports;
    const statements = portal === 'admin' ? adminStatements : branchStatements;
    const [importing, setImporting] = useState(false);
    const [problems, setProblems] = useState<ImportRow | null>(null);

    const visit = (next: Partial<Props['filters']>) =>
        router.get(
            routes.index({
                query: Object.fromEntries(
                    Object.entries({ ...filters, ...next }).filter(
                        ([, value]) => value,
                    ),
                ) as Record<string, string>,
            }).url,
            {},
            { preserveState: true, replace: true },
        );

    const columns: Column<ImportRow>[] = [
        {
            key: 'when',
            header: 'Imported',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap">
                    <div>{formatDateTime(row.created_at)}</div>
                    <div className="text-tx3">{row.imported_by}</div>
                </div>
            ),
        },
        {
            key: 'account',
            header: 'Account',
            cell: (row) => (
                <div className="text-xs">
                    <div className="font-medium">
                        {row.branch} · {row.account}
                    </div>
                    <div className="text-tx3">
                        {row.bank} {row.number}
                    </div>
                </div>
            ),
        },
        {
            key: 'file',
            header: 'File',
            cell: (row) =>
                row.file_id ? (
                    <a
                        href={fileUrl(row.file_id).url}
                        onClick={(event) => event.stopPropagation()}
                        className="text-xs font-medium text-ac"
                    >
                        {row.file}
                    </a>
                ) : (
                    <span className="text-xs">{row.file ?? '—'}</span>
                ),
        },
        {
            key: 'period',
            header: 'Period',
            cell: (row) => (
                <span className="text-xs whitespace-nowrap">
                    {formatDate(row.period_from)} – {formatDate(row.period_to)}
                </span>
            ),
        },
        {
            key: 'lines',
            header: 'Lines',
            cell: (row) => (
                <div className="text-xs whitespace-nowrap">
                    <b>{row.rows_imported}</b> added
                    <span className="text-tx3">
                        {' '}
                        · {row.rows_duplicate} already there
                    </span>
                    {row.rows_failed > 0 && (
                        <button
                            type="button"
                            onClick={() => setProblems(row)}
                            className="ml-1 font-medium text-er underline"
                        >
                            {row.rows_failed} unreadable
                        </button>
                    )}
                </div>
            ),
        },
        {
            key: 'credit',
            header: 'Credit',
            align: 'right',
            cell: (row) => (
                <span className="text-ok">{formatPaise(row.credit_total)}</span>
            ),
        },
        {
            key: 'debit',
            header: 'Debit',
            align: 'right',
            cell: (row) => (
                <span className="text-er">{formatPaise(row.debit_total)}</span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => (
                <StatusBadge
                    status={row.status === 'completed' ? 'success' : row.status}
                    label={row.status}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Statement History" />
            <PageHeader
                title="Statement History"
                description="Every statement file imported, with the lines it added. Lines already in the statement are never added twice."
                actions={
                    <>
                        <Link
                            href={statements.index().url}
                            className="inline-flex h-8 items-center rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                        >
                            Statement lines
                        </Link>
                        {can.create && (
                            <PgButton
                                variant="primary"
                                onClick={() => setImporting(true)}
                            >
                                <Upload className="size-3.5" /> Import statement
                            </PgButton>
                        )}
                    </>
                }
            />

            <Panel className="overflow-hidden">
                <div className="flex flex-wrap items-center gap-2 border-b border-ln2 px-3 py-2.5">
                    {portal === 'admin' && (
                        <SelectInput
                            className="h-[30px] w-[190px] text-[12.5px]"
                            value={filters.branch ?? ''}
                            onChange={(event) =>
                                visit({
                                    branch: event.target.value || null,
                                    account: null,
                                })
                            }
                        >
                            <option value="">All branches</option>
                            {branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>
                                    {branch.code} · {branch.name}
                                </option>
                            ))}
                        </SelectInput>
                    )}
                    <SelectInput
                        className="h-[30px] w-[260px] text-[12.5px]"
                        value={filters.account ?? ''}
                        onChange={(event) =>
                            visit({ account: event.target.value || null })
                        }
                    >
                        <option value="">All accounts</option>
                        {accounts
                            .filter(
                                (account) =>
                                    !filters.branch ||
                                    account.branch_id === filters.branch,
                            )
                            .map((account) => (
                                <option key={account.id} value={account.id}>
                                    {accountLabel(account)}
                                </option>
                            ))}
                    </SelectInput>
                    <div className="flex-1" />
                    <span className="text-xs text-tx3">
                        {imports.total} imports · newest first
                    </span>
                </div>
                <DataTable
                    columns={columns}
                    rows={imports.data}
                    rowKey={(row) => row.id}
                    empty={
                        <EmptyState
                            title="No statements imported yet"
                            description="Import a bank statement (CSV or Excel) to add its lines at once."
                        />
                    }
                />
                {(imports.prev_page_url || imports.next_page_url) && (
                    <div className="flex justify-end gap-2 px-3 py-2.5 text-xs">
                        {imports.prev_page_url && (
                            <Link
                                href={imports.prev_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                ‹ Newer
                            </Link>
                        )}
                        {imports.next_page_url && (
                            <Link
                                href={imports.next_page_url}
                                className="rounded-[7px] border border-ln px-2.5 py-1"
                            >
                                Older ›
                            </Link>
                        )}
                    </div>
                )}
            </Panel>

            <StatementImportDialog
                portal={portal}
                accounts={accounts}
                open={importing}
                onOpenChange={setImporting}
            />

            {problems && (
                <FormDialog
                    open
                    onOpenChange={(next) => !next && setProblems(null)}
                    title={`${problems.rows_failed} lines couldn’t be read`}
                    description={`${problems.file} · the rest of the file was imported. Fix these lines in the file and import it again, or add them by hand.`}
                    submitLabel="Close"
                    onSubmit={() => setProblems(null)}
                    width={560}
                >
                    <ul className="flex max-h-[320px] flex-col gap-1 overflow-y-auto text-[12.5px]">
                        {problems.errors.map((error) => (
                            <li key={error.row}>
                                <span className="font-mono text-tx3">
                                    Row {error.row}
                                </span>{' '}
                                {error.message}
                            </li>
                        ))}
                    </ul>
                </FormDialog>
            )}
        </>
    );
}
