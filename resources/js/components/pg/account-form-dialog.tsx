import { useForm } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { Field, SelectInput, TextInput } from './field';
import { FormDialog } from './form-dialog';
import { SwitchField } from './switch-field';

/** Row shape from App\Http\Shared\Accounts\AccountPresenter. */
export type AccountRow = {
    id: string;
    label: string;
    holder: string;
    branch: { id: string; code: string; name: string };
    is_bank_enabled: boolean;
    bank_name: string | null;
    ifsc: string | null;
    account_number: string | null;
    is_upi_enabled: boolean;
    upi_id: string | null;
    upi_display_name: string | null;
    upi_code: string | null;
    is_qr_enabled: boolean;
    is_intent_enabled: boolean;
    min_amount: number | null;
    max_amount: number | null;
    daily_amount_limit: number | null;
    daily_count_limit: number | null;
    max_open_sessions: number;
    used_today: { amount: number; count: number };
    status: string;
    rejected_reason: string | null;
    verified_at: string | null;
    created_at: string | null;
    can: { update: boolean; verify: boolean; switch_to: string[] };
};

const rupees = (paise: number | null) =>
    paise === null ? '' : (paise / 100).toFixed(2).replace(/\.00$/, '');

/**
 * Add or edit a bank / UPI account. On edit the numbers are never shown;
 * leaving them empty keeps the current ones. Changing payment details sends
 * the account back to PayGate for verification.
 */
export function AccountFormDialog({
    account,
    branches,
    url,
    method,
    onClose,
}: {
    account?: AccountRow;
    branches?: { id: string; code: string; name: string }[];
    url: string;
    method: 'post' | 'put';
    onClose: () => void;
}) {
    const editing = account !== undefined;
    const form = useForm({
        branch_id: '',
        label: account?.label ?? '',
        account_holder_name: account?.holder ?? '',
        is_bank_enabled: account?.is_bank_enabled ?? true,
        bank_name: account?.bank_name ?? '',
        ifsc: account?.ifsc ?? '',
        account_number: '',
        is_upi_enabled: account?.is_upi_enabled ?? false,
        upi_id: '',
        upi_display_name: account?.upi_display_name ?? '',
        is_qr_enabled: account?.is_qr_enabled ?? false,
        is_intent_enabled: account?.is_intent_enabled ?? false,
        min_amount: rupees(account?.min_amount ?? null),
        max_amount: rupees(account?.max_amount ?? null),
        daily_amount_limit: rupees(account?.daily_amount_limit ?? null),
        daily_count_limit: account?.daily_count_limit?.toString() ?? '',
        max_open_sessions: (account?.max_open_sessions ?? 5).toString(),
    });
    const { data, setData } = form;
    const errors = form.errors as Record<string, string | undefined>;

    const submit = () => {
        form.transform((values) =>
            branches ? values : { ...values, branch_id: undefined },
        );
        form[method](url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onClose,
        });
    };

    const text = (
        key: keyof typeof data,
        label: string,
        props: ComponentProps<typeof TextInput> & { hint?: string } = {},
    ) => {
        const { hint, ...rest } = props;

        return (
            <Field label={label} hint={hint} error={errors[key]}>
                <TextInput
                    value={data[key] as string}
                    invalid={!!errors[key]}
                    onChange={(event) => setData(key, event.target.value)}
                    {...rest}
                />
            </Field>
        );
    };

    const money = (key: keyof typeof data, label: string) =>
        text(key, label, {
            inputMode: 'decimal',
            placeholder: 'No limit',
            hint: 'In rupees. Empty = no limit.',
        });

    return (
        <FormDialog
            open
            width={640}
            onOpenChange={(open) => !open && onClose()}
            title={editing ? `Edit ${account.label}` : 'Add bank / UPI account'}
            description={
                editing
                    ? 'Changing the holder, bank, IFSC, account number or UPI ID sends the account back for verification.'
                    : 'PayGate verifies every new account before customers are sent to it.'
            }
            submitLabel={editing ? 'Save' : 'Add account'}
            processing={form.processing}
            onSubmit={submit}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                {branches && (
                    <div className="sm:col-span-2">
                        <Field label="Branch" error={errors.branch_id}>
                            <SelectInput
                                required
                                value={data.branch_id}
                                invalid={!!errors.branch_id}
                                onChange={(event) =>
                                    setData('branch_id', event.target.value)
                                }
                            >
                                <option value="">Choose…</option>
                                {branches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>
                                        {branch.code} · {branch.name}
                                    </option>
                                ))}
                            </SelectInput>
                        </Field>
                    </div>
                )}
                {text('label', 'Label', {
                    required: true,
                    autoFocus: true,
                    hint: 'Your own name for it, e.g. “SBI current 2”.',
                })}
                {text('account_holder_name', 'Account holder name', {
                    required: true,
                })}

                <div className="sm:col-span-2">
                    <SwitchField
                        label="Bank transfer"
                        hint="Customers can pay by NEFT / IMPS / RTGS to this account."
                        checked={data.is_bank_enabled}
                        onChange={(checked) =>
                            setData('is_bank_enabled', checked)
                        }
                    />
                </div>
                {data.is_bank_enabled && (
                    <>
                        {text('bank_name', 'Bank name', { required: true })}
                        {text('ifsc', 'IFSC', {
                            required: true,
                            maxLength: 11,
                            className: 'font-mono uppercase',
                            placeholder: 'HDFC0001203',
                        })}
                        <div className="sm:col-span-2">
                            {text('account_number', 'Account number', {
                                inputMode: 'numeric',
                                autoComplete: 'off',
                                className: 'font-mono',
                                required: !editing || !account.account_number,
                                placeholder:
                                    editing && account.account_number
                                        ? `${account.account_number} (leave empty to keep)`
                                        : '',
                                hint: 'Stored encrypted; only the last 4 digits are shown afterwards.',
                            })}
                        </div>
                    </>
                )}

                <div className="sm:col-span-2">
                    <SwitchField
                        label="UPI"
                        hint="Customers can pay to a UPI ID (QR code and app intent)."
                        checked={data.is_upi_enabled}
                        onChange={(checked) =>
                            setData('is_upi_enabled', checked)
                        }
                    />
                </div>
                {data.is_upi_enabled && (
                    <>
                        {text('upi_id', 'UPI ID', {
                            autoComplete: 'off',
                            className: 'font-mono',
                            required: !editing || !account.upi_id,
                            placeholder:
                                editing && account.upi_id
                                    ? `${account.upi_id} (leave empty to keep)`
                                    : 'name@bank',
                        })}
                        {text('upi_display_name', 'Name shown to customers')}
                        <SwitchField
                            label="QR code"
                            checked={data.is_qr_enabled}
                            onChange={(checked) =>
                                setData('is_qr_enabled', checked)
                            }
                        />
                        <SwitchField
                            label="UPI app intent"
                            checked={data.is_intent_enabled}
                            onChange={(checked) =>
                                setData('is_intent_enabled', checked)
                            }
                        />
                    </>
                )}
                {errors.is_bank_enabled && (
                    <p className="text-xs text-er sm:col-span-2">
                        {errors.is_bank_enabled}
                    </p>
                )}

                <div className="border-t border-ln2 pt-3 text-xs font-semibold tracking-[.04em] text-tx3 uppercase sm:col-span-2">
                    Limits
                </div>
                {money('min_amount', 'Minimum per payment')}
                {money('max_amount', 'Maximum per payment')}
                {money('daily_amount_limit', 'Daily amount limit')}
                {text('daily_count_limit', 'Daily number of payments', {
                    type: 'number',
                    min: 1,
                    placeholder: 'No limit',
                })}
                {text(
                    'max_open_sessions',
                    'Customers paying at the same time',
                    {
                        type: 'number',
                        min: 1,
                        max: 100,
                        required: true,
                        hint: 'Keeps parallel payments to this account easy to tell apart.',
                    },
                )}
            </div>
        </FormDialog>
    );
}
