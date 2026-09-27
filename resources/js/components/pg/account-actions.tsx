import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { AccountRow } from './account-form-dialog';
import { PgButton } from './button';
import { ConfirmDialog } from './confirm-dialog';

const LABELS: Record<string, string> = {
    active: 'Activate',
    paused: 'Pause',
    disabled: 'Disable',
};

/**
 * Edit + status buttons for one account row. Disabling is permanent and
 * asks for a reason; activating and pausing apply at once.
 */
export function AccountActions({
    account,
    statusUrl,
    onEdit,
}: {
    account: AccountRow;
    statusUrl: string;
    onEdit: () => void;
}) {
    const [confirmDisable, setConfirmDisable] = useState(false);

    const change = (status: string, reason?: string) =>
        router.put(
            statusUrl,
            { status, reason: reason ?? null },
            { preserveScroll: true, onFinish: () => setConfirmDisable(false) },
        );

    return (
        <>
            {account.can.update && (
                <PgButton className="h-7 text-xs" onClick={onEdit}>
                    Edit
                </PgButton>
            )}
            {account.can.switch_to
                .filter((status) => status !== 'disabled')
                .map((status) => (
                    <PgButton
                        key={status}
                        className="h-7 text-xs"
                        variant={status === 'active' ? 'primary' : 'secondary'}
                        onClick={() => change(status)}
                    >
                        {LABELS[status] ?? status}
                    </PgButton>
                ))}
            {account.can.switch_to.includes('disabled') && (
                <PgButton
                    className="h-7 text-xs"
                    variant="danger"
                    onClick={() => setConfirmDisable(true)}
                >
                    Disable
                </PgButton>
            )}
            <ConfirmDialog
                open={confirmDisable}
                onOpenChange={setConfirmDisable}
                title={`Disable ${account.label}?`}
                description="Customers are no longer sent to this account. This can’t be undone; its history is kept."
                confirmLabel="Disable account"
                tone="danger"
                input={{
                    label: 'Reason (kept in the audit log)',
                    required: true,
                }}
                onConfirm={(reason) => change('disabled', reason)}
            />
        </>
    );
}
