import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { AccountRow } from './account-form-dialog';
import { PgButton } from './button';
import { ConfirmDialog } from './confirm-dialog';

/**
 * Edit + status buttons for one account row. Pausing and disabling ask for
 * a reason (kept in the account log); activating applies at once.
 */
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
    const [confirm, setConfirm] = useState<'paused' | 'disabled' | null>(null);

    const change = (status: string, reason?: string) =>
        router.put(
            statusUrl,
            { status, reason: reason ?? null },
            { preserveScroll: true, onFinish: () => setConfirm(null) },
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
            {!hideStatus &&
                account.can.switch_to.includes('verification_pending') && (
                    <PgButton
                        className="h-7 text-xs"
                        variant="primary"
                        onClick={() => change('verification_pending')}
                    >
                        Send for verification
                    </PgButton>
                )}
            {!hideStatus && account.can.switch_to.includes('paused') && (
                <PgButton
                    className="h-7 text-xs"
                    onClick={() => setConfirm('paused')}
                >
                    Pause
                </PgButton>
            )}
            {!hideStatus && account.can.switch_to.includes('disabled') && (
                <PgButton
                    className="h-7 text-xs"
                    variant="danger"
                    onClick={() => setConfirm('disabled')}
                >
                    Disable
                </PgButton>
            )}
            <ConfirmDialog
                open={confirm === 'paused'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={`Pause ${account.label}?`}
                description="New customers are not sent to this account until it is activated again. Payments already on their way are unaffected."
                confirmLabel="Pause account"
                tone="warning"
                input={{
                    label: 'Reason (kept in the account log)',
                    required: true,
                }}
                onConfirm={(reason) => change('paused', reason)}
            />
            <ConfirmDialog
                open={confirm === 'disabled'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={`Disable ${account.label}?`}
                description="Customers are no longer sent to this account. You can turn a verified account back on. One that is not verified goes back to PayGate for verification."
                confirmLabel="Disable account"
                tone="danger"
                input={{
                    label: 'Reason (kept in the account log)',
                    required: true,
                }}
                onConfirm={(reason) => change('disabled', reason)}
            />
        </>
    );
}
