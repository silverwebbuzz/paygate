import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { AccountRow } from './account-form-dialog';
import { PgButton } from './button';
import { ConfirmDialog } from './confirm-dialog';

export function AccountActions({
    account,
    statusUrl,
    onEdit,
    hideStatus = false,
}: {
    account: AccountRow;
    statusUrl: string;
    onEdit: () => void;
    hideStatus?: boolean;
}) {
    const [confirm, setConfirm] = useState(false);

    const change = (status: string, reason?: string) =>
        router.put(
            statusUrl,
            { status, reason: reason ?? null },
            { preserveScroll: true, onFinish: () => setConfirm(false) },
        );

    return (
        <>
            {account.can.update && (
                <PgButton className="h-7 text-xs" onClick={onEdit}>
                    Edit
                </PgButton>
            )}
            {!hideStatus && account.can.switch_to.includes('active') && (
                <PgButton
                    className="h-7 text-xs"
                    variant="primary"
                    onClick={() => change('active')}
                >
                    Activate
                </PgButton>
            )}
            {!hideStatus && account.can.switch_to.includes('inactive') && (
                <PgButton
                    className="h-7 text-xs"
                    onClick={() => setConfirm(true)}
                >
                    Turn off
                </PgButton>
            )}
            <ConfirmDialog
                open={confirm}
                onOpenChange={(open) => !open && setConfirm(false)}
                title={`Turn off ${account.label}?`}
                description="New customers are not sent to this account until it is activated again. Payments already on their way are unaffected."
                confirmLabel="Turn off"
                tone="warning"
                input={{
                    label: 'Reason (kept in the account log)',
                    required: true,
                }}
                onConfirm={(reason) => change('inactive', reason)}
            />
        </>
    );
}
