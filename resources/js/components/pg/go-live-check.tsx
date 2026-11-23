import { cn } from '@/lib/utils';

export type GoLive = {
    environment: string;
    base_url: string;
    ready: boolean;
    items: {
        field: string;
        label: string;
        url: string | null;
        state: 'ok' | 'warning' | 'missing' | 'unused';
        reason: string | null;
    }[];
};

const MARK: Record<GoLive['items'][number]['state'], [string, string]> = {
    ok: ['✓', 'text-ok'],
    warning: ['⚠', 'text-wn'],
    missing: ['✕', 'text-er'],
    unused: ['–', 'text-tx3'],
};

/**
 * Go-live check (client request 2026-11-23): this server and the partner's
 * URLs, each marked OK / suspicious / missing (GoLiveCheck). On live a URL
 * that looks like a test site is flagged.
 */
export function GoLiveCheck({ check }: { check: GoLive }) {
    const live = check.environment === 'live';

    return (
        <div className="flex flex-col gap-2.5 text-[12.5px]">
            <p className="text-tx2">
                This is the <b>{check.environment}</b> server
                {live ? ' (real money)' : ' (testing, no real money)'}. API base
                URL: <span className="font-mono">{check.base_url}</span>. Every
                answer and webhook says its server in{' '}
                <span className="font-mono">environment</span>.
                {live
                    ? ' URLs that look like a test site are flagged below.'
                    : ' Test URLs are fine here; when going live, set the live ones on the live server.'}
            </p>
            <ul className="flex flex-col gap-1.5">
                {check.items.map((item) => {
                    const [mark, color] = MARK[item.state];

                    return (
                        <li key={item.field} className="flex gap-2">
                            <span
                                className={cn(
                                    'w-4 flex-none font-semibold',
                                    color,
                                )}
                            >
                                {mark}
                            </span>
                            <div className="min-w-0">
                                <div>
                                    <b>{item.label}</b>{' '}
                                    <span className="font-mono break-all text-tx2">
                                        {item.url ?? '—'}
                                    </span>
                                </div>
                                {item.reason && (
                                    <div className={cn('text-xs', color)}>
                                        {item.reason}
                                    </div>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>
            <p
                className={cn(
                    'text-xs font-medium',
                    check.ready ? 'text-ok' : 'text-wn',
                )}
            >
                {check.ready
                    ? 'All set for this server.'
                    : 'Fix the marked URLs before taking payments on this server.'}
            </p>
        </div>
    );
}
