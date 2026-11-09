import { useForm } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import {
    accountLabel,
    StatementImportDialog,
} from '@/components/pg/statement-import-dialog';
import type { HubEntryAccount } from '@/lib/payment-hub';
import adminStatements from '@/routes/admin/statements';
import branchStatements from '@/routes/branch/statements';
import type { UserType } from '@/types';

/** Debit entry is hidden for now (client request); flip to bring it back. */
export const SHOW_DEBIT = false;

/**
 * Design "New statement line" (credit or debit, one value per line) and the
 * statement import, on every Deposit tab. A credit line whose UTR and amount
 * match a waiting deposit approves it (AutoApproveDeposit); the page reloads
 * and shows the result.
 */
export function StatementEntryBar({
    portal,
    accounts,
    branches,
}: {
    portal: UserType;
    accounts: HubEntryAccount[];
    branches: { id: string; code: string; name: string }[];
}) {
    const routes = portal === 'admin' ? adminStatements : branchStatements;
    const today = useMemo(
        () =>
            new Intl.DateTimeFormat('en-CA', {
                timeZone: 'Asia/Kolkata',
            }).format(new Date()),
        [],
    );
    const [branch, setBranch] = useState('');
    const [importing, setImporting] = useState(false);
    const form = useForm({
        payment_account_id: accounts.length === 1 ? accounts[0].id : '',
        value_date: today,
        utr: '',
        description: '',
        credit: '',
        debit: '',
    });
    const shown = accounts.filter(
        (account) => !branch || account.branch_id === branch,
    );

    return (
        <Panel className="px-3.5 py-3">
            <div className="mb-2.5 flex flex-wrap items-center gap-2">
                <span className="text-[13.5px] font-semibold">
                    New statement line
                </span>
                <span className="text-xs text-tx3">
                    {SHOW_DEBIT
                        ? 'Credit or debit — one value per line'
                        : 'Credit received in the bank'}
                    {' · '}a deposit with the same UTR and amount is approved
                    automatically
                </span>
                <div className="flex-1" />
                <PgButton
                    type="button"
                    className="h-7 text-xs"
                    onClick={() => setImporting(true)}
                >
                    <Upload className="size-3.5" /> Import statement
                </PgButton>
            </div>
            <form
                className="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] items-end gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(routes.store().url, {
                        preserveScroll: true,
                        onSuccess: () =>
                            form.reset('utr', 'description', 'credit', 'debit'),
                    });
                }}
            >
                {portal === 'admin' && (
                    <Field label="Branch">
                        <SelectInput
                            className="h-[34px] text-[13px]"
                            value={branch}
                            onChange={(event) => {
                                setBranch(event.target.value);
                                form.setData('payment_account_id', '');
                            }}
                        >
                            <option value="">All</option>
                            {branches.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </SelectInput>
                    </Field>
                )}
                <Field
                    label="Bank account"
                    error={form.errors.payment_account_id}
                >
                    <SelectInput
                        required
                        className="h-[34px] text-[13px]"
                        value={form.data.payment_account_id}
                        onChange={(event) =>
                            form.setData(
                                'payment_account_id',
                                event.target.value,
                            )
                        }
                    >
                        <option value="">Choose…</option>
                        {shown.map((account) => (
                            <option key={account.id} value={account.id}>
                                {accountLabel(account)}
                            </option>
                        ))}
                    </SelectInput>
                </Field>
                <Field label="Date" error={form.errors.value_date}>
                    <TextInput
                        type="date"
                        required
                        max={today}
                        className="h-[34px] text-[13px]"
                        value={form.data.value_date}
                        onChange={(event) =>
                            form.setData('value_date', event.target.value)
                        }
                    />
                </Field>
                <Field label="UTR / reference" error={form.errors.utr}>
                    <TextInput
                        className="h-[34px] font-mono text-[13px]"
                        placeholder="e.g. 626812820491"
                        value={form.data.utr}
                        onChange={(event) =>
                            form.setData('utr', event.target.value)
                        }
                    />
                </Field>
                <Field label="Description" error={form.errors.description}>
                    <TextInput
                        className="h-[34px] text-[13px]"
                        placeholder="As on the statement"
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                    />
                </Field>
                <Field label="Credit ₹" error={form.errors.credit}>
                    <TextInput
                        inputMode="decimal"
                        className="h-[34px] text-[13px]"
                        placeholder="0.00"
                        value={form.data.credit}
                        disabled={form.data.debit !== ''}
                        onChange={(event) =>
                            form.setData('credit', event.target.value)
                        }
                    />
                </Field>
                {SHOW_DEBIT && (
                    <Field label="Debit ₹" error={form.errors.debit}>
                        <TextInput
                            inputMode="decimal"
                            className="h-[34px] text-[13px]"
                            placeholder="0.00"
                            value={form.data.debit}
                            disabled={form.data.credit !== ''}
                            onChange={(event) =>
                                form.setData('debit', event.target.value)
                            }
                        />
                    </Field>
                )}
                <PgButton
                    type="submit"
                    variant="primary"
                    className="h-[34px] justify-center"
                    disabled={form.processing}
                >
                    Add line
                </PgButton>
            </form>

            <StatementImportDialog
                portal={portal}
                accounts={accounts}
                open={importing}
                onOpenChange={setImporting}
            />
        </Panel>
    );
}
