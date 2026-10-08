import { useForm } from '@inertiajs/react';
import { Field, TextInput } from '@/components/pg/field';
import { FormDialog } from '@/components/pg/form-dialog';

export type LimitValues = {
    deposit_min_amount: string;
    deposit_max_amount: string;
    deposit_daily_limit: string;
    withdrawal_min_amount: string;
    withdrawal_max_amount: string;
    withdrawal_daily_limit: string;
};

const NO_LIMIT = '-1';

const DEPOSIT_FIELDS: { key: keyof LimitValues; label: string }[] = [
    { key: 'deposit_min_amount', label: 'Minimum deposit' },
    { key: 'deposit_max_amount', label: 'Maximum deposit' },
    { key: 'deposit_daily_limit', label: 'Daily deposit limit' },
];

const WITHDRAWAL_FIELDS: { key: keyof LimitValues; label: string }[] = [
    { key: 'withdrawal_min_amount', label: 'Minimum withdrawal' },
    { key: 'withdrawal_max_amount', label: 'Maximum withdrawal' },
    { key: 'withdrawal_daily_limit', label: 'Daily withdrawal limit' },
];

export function LimitsDialog({
    title,
    description,
    limits,
    url,
    showDailyDeposit = true,
    onClose,
    onSaved,
}: {
    title: string;
    description: string;
    limits: LimitValues;
    url: string;
    showDailyDeposit?: boolean;
    onClose: () => void;
    onSaved?: () => void;
}) {
    const form = useForm<LimitValues>(limits);
    const errors = form.errors as Partial<Record<keyof LimitValues, string>>;

    const fields = (items: { key: keyof LimitValues; label: string }[]) =>
        items
            .filter(
                (field) =>
                    showDailyDeposit || field.key !== 'deposit_daily_limit',
            )
            .map((field) => (
                <Field
                    key={field.key}
                    label={`${field.label} (₹)`}
                    required
                    hint={
                        <span className="text-er">Note: -1 is unlimited</span>
                    }
                    error={errors[field.key]}
                >
                    <TextInput
                        required
                        inputMode="decimal"
                        pattern="-1|\d{1,11}(\.\d{1,2})?"
                        placeholder={NO_LIMIT}
                        value={form.data[field.key]}
                        invalid={!!errors[field.key]}
                        onChange={(event) =>
                            form.setData(
                                field.key,
                                event.target.value.replace(/[,₹\s]/g, ''),
                            )
                        }
                    />
                </Field>
            ));

    return (
        <FormDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={title}
            description={description}
            submitLabel="Save limits"
            processing={form.processing}
            width={520}
            onSubmit={() =>
                form.put(url, {
                    preserveScroll: true,
                    onSuccess: () => {
                        onClose();
                        onSaved?.();
                    },
                })
            }
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="text-xs font-semibold tracking-[.04em] text-tx3 uppercase sm:col-span-2">
                    Deposit
                </div>
                {fields(DEPOSIT_FIELDS)}
                <div className="border-t border-ln2 pt-3 text-xs font-semibold tracking-[.04em] text-tx3 uppercase sm:col-span-2">
                    Withdrawal
                </div>
                {fields(WITHDRAWAL_FIELDS)}
            </div>
        </FormDialog>
    );
}
