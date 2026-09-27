/**
 * Dates are stored in UTC and shown in India time (the business timezone,
 * config('app.business_timezone')), whatever the viewer's computer is set to.
 */
const dateTime = new Intl.DateTimeFormat('en-IN', {
    timeZone: 'Asia/Kolkata',
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
});

export function formatDateTime(value: string | null | undefined): string {
    return value ? dateTime.format(new Date(value)) : '—';
}

/** "3 min ago", "2 days ago"; the full date after 30 days. */
export function formatRelative(value: string | null | undefined): string {
    if (!value) return 'Never';

    const seconds = (Date.now() - new Date(value).getTime()) / 1000;
    const days = Math.floor(seconds / 86400);

    if (seconds < 60) return 'Just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} hr ago`;
    if (days < 30) return `${days} ${days === 1 ? 'day' : 'days'} ago`;

    return formatDateTime(value);
}
