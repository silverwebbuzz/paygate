import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Field, PasswordTextInput, TextInput } from './field';
import { FormDialog } from './form-dialog';

/**
 * Sensitive action that needs the person's own password (and optionally a
 * reason for the audit log): generating, rotating or revoking API keys.
 */
export function SecureActionDialog({
    open,
    onClose,
    title,
    description,
    submitLabel,
    method,
    url,
    askReason = false,
    onSuccess,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    submitLabel: string;
    method: 'post' | 'delete';
    url: string;
    askReason?: boolean;
    onSuccess?: () => void;
}) {
    const [password, setPassword] = useState('');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const close = () => {
        setPassword('');
        setReason('');
        setErrors({});
        onClose();
    };

    const submit = () =>
        router[method](url, askReason ? { password, reason } : { password }, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: setErrors,
            onSuccess: () => {
                close();
                onSuccess?.();
            },
        });

    return (
        <FormDialog
            open={open}
            onOpenChange={(next) => !next && close()}
            title={title}
            description={description}
            submitLabel={submitLabel}
            processing={processing}
            onSubmit={submit}
        >
            {askReason && (
                <Field
                    label="Reason (kept in the audit log)"
                    error={errors.reason}
                >
                    <TextInput
                        required
                        value={reason}
                        invalid={!!errors.reason}
                        onChange={(event) => setReason(event.target.value)}
                    />
                </Field>
            )}
            <Field label="Your password" error={errors.password ?? errors.key}>
                <PasswordTextInput
                    required
                    autoComplete="current-password"
                    value={password}
                    invalid={!!errors.password}
                    onChange={(event) => setPassword(event.target.value)}
                />
            </Field>
        </FormDialog>
    );
}
