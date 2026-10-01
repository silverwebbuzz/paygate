/**
 * One place that turns database states into the design's labels and colours
 * (Implementation-Plan.md §2.2 A-2). Tones map to the design tokens.
 */
export type Tone = 'ok' | 'wn' | 'er' | 'in' | 'hd' | 'rv' | 'nt';

type StatusStyle = { label: string; tone: Tone; icon: string };

const STATUSES: Record<string, StatusStyle> = {
    // pay-in
    created: { label: 'Created', tone: 'nt', icon: '○' },
    awaiting_payment: { label: 'Awaiting payment', tone: 'nt', icon: '○' },
    payment_submitted: { label: 'Pending', tone: 'wn', icon: '◷' },
    payment_detected: { label: 'Detected', tone: 'in', icon: '◉' },
    under_review: { label: 'Payment hold', tone: 'hd', icon: '‖' },
    success: { label: 'Success', tone: 'ok', icon: '✓' },
    rejected: { label: 'Declined', tone: 'er', icon: '✕' },
    expired: { label: 'Expired', tone: 'nt', icon: '⧗' },
    cancelled: { label: 'Cancelled', tone: 'nt', icon: '–' },
    chargeback: { label: 'Chargeback', tone: 'er', icon: '↺' },
    refunded: { label: 'Refunded', tone: 'rv', icon: '↺' },
    // payout
    validated: { label: 'Validated', tone: 'nt', icon: '○' },
    assigned: { label: 'Assigned', tone: 'in', icon: '→' },
    processing: { label: 'Processing', tone: 'wn', icon: '◷' },
    failed: { label: 'Failed', tone: 'er', icon: '✕' },
    returned: { label: 'Returned', tone: 'rv', icon: '↺' },
    // organisations, accounts, users
    draft: { label: 'Draft', tone: 'nt', icon: '○' },
    pending_verification: {
        label: 'Pending verification',
        tone: 'wn',
        icon: '◷',
    },
    verification_pending: {
        label: 'Pending verification',
        tone: 'wn',
        icon: '◷',
    },
    new: { label: 'New', tone: 'nt', icon: '○' },
    verified: { label: 'Verified', tone: 'ok', icon: '✓' },
    active: { label: 'Active', tone: 'ok', icon: '●' },
    inactive: { label: 'Inactive', tone: 'nt', icon: '○' },
    paused: { label: 'Paused', tone: 'hd', icon: '‖' },
    exhausted: { label: 'Limit exhausted', tone: 'hd', icon: '!' },
    disabled: { label: 'Disabled', tone: 'nt', icon: '–' },
    suspended: { label: 'Suspended', tone: 'er', icon: '✕' },
    // API keys
    rotating: { label: 'Rotating out', tone: 'wn', icon: '↻' },
    revoked: { label: 'Revoked', tone: 'er', icon: '✕' },
    offboarded: { label: 'Offboarded', tone: 'nt', icon: '–' },
    // reconciliation & settlement
    matched: { label: 'Matched', tone: 'ok', icon: '✓' },
    unmatched: { label: 'Not matched', tone: 'nt', icon: '–' },
    mismatch: { label: 'Mismatch', tone: 'er', icon: '≠' },
    unsettled: { label: 'Unsettled', tone: 'hd', icon: '!' },
    reconciled: { label: 'Reconciled', tone: 'ok', icon: '✓' },
    duplicate: { label: 'Duplicate', tone: 'er', icon: '≠' },
    ignored: { label: 'Closed', tone: 'nt', icon: '–' },
    hold: { label: 'Payment hold', tone: 'hd', icon: '‖' },
    none: { label: 'Not matched', tone: 'nt', icon: '–' },
    open: { label: 'Open', tone: 'hd', icon: '!' },
    resolved: { label: 'Resolved', tone: 'ok', icon: '✓' },
    calculated: { label: 'Calculated', tone: 'nt', icon: '○' },
    pending_review: { label: 'Under review', tone: 'rv', icon: '◐' },
    approved: { label: 'Approved', tone: 'ok', icon: '✓' },
    partially_settled: { label: 'Partially settled', tone: 'wn', icon: '◑' },
    settled: { label: 'Settled', tone: 'ok', icon: '✓' },
    disputed: { label: 'Disputed', tone: 'er', icon: '!' },
    // webhooks
    pending: { label: 'Pending', tone: 'wn', icon: '◷' },
    retrying: { label: 'Retrying', tone: 'hd', icon: '↻' },
    delivered: { label: 'Delivered', tone: 'ok', icon: '✓' },
};

export function statusStyle(status: string): StatusStyle {
    return (
        STATUSES[status] ?? {
            label: status.replaceAll('_', ' '),
            tone: 'nt',
            icon: '•',
        }
    );
}

export const TONE_CLASSES: Record<Tone, string> = {
    ok: 'bg-okb text-ok',
    wn: 'bg-wnb text-wn',
    er: 'bg-erb text-er',
    in: 'bg-inb text-in',
    hd: 'bg-hdb text-hd',
    rv: 'bg-rvb text-rv',
    nt: 'bg-ntb text-nt',
};
