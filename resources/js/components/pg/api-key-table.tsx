import { formatDateTime, formatRelative } from '@/lib/dates';
import { PgButton } from './button';
import { SimpleTable } from './simple-table';
import { StatusBadge } from './status-badge';

export type ApiKey = {
    id: string;
    key_id: string;
    last4: string;
    status: string;
    expires_at: string | null;
    created_at: string;
    last_used_at: string | null;
};

/** A partner's API keys: id, masked secret, status (rotating keys show until when). */
export function ApiKeyTable({
    keys,
    onRevoke,
}: {
    keys: ApiKey[];
    onRevoke?: (key: ApiKey) => void;
}) {
    if (keys.length === 0) {
        return <p className="text-xs text-tx3">No API key yet.</p>;
    }

    return (
        <SimpleTable
            headers={['Key ID', 'Secret', 'Status', 'Created', 'Last used', '']}
            rows={keys.map((key) => [
                <code key="k" className="font-mono text-xs">
                    {key.key_id}
                </code>,
                <code key="s" className="font-mono text-xs text-tx3">
                    ••••{key.last4}
                </code>,
                <span key="st">
                    <StatusBadge status={key.status} />
                    {key.status === 'rotating' && key.expires_at && (
                        <span className="block text-[11px] text-tx3">
                            until {formatDateTime(key.expires_at)}
                        </span>
                    )}
                </span>,
                formatDateTime(key.created_at),
                formatRelative(key.last_used_at),
                onRevoke && ['active', 'rotating'].includes(key.status) ? (
                    <PgButton
                        key="r"
                        variant="danger"
                        className="h-7 text-xs"
                        onClick={() => onRevoke(key)}
                    >
                        Revoke
                    </PgButton>
                ) : null,
            ])}
        />
    );
}
