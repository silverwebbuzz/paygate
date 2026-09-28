import { Head, Link } from '@inertiajs/react';
import { Panel } from '@/components/pg/data-table';
import { PageHeader } from '@/components/pg/page-header';
import { SimpleTable } from '@/components/pg/simple-table';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDateTime } from '@/lib/dates';
import admin from '@/routes/admin';

type Partner = {
    id: string;
    code: string;
    name: string;
    status: string;
    rules: string[];
    calls: {
        ip: string | null;
        calls: number;
        refused: number;
        last_at: string;
    }[];
};

/**
 * IP Management: every partner's API allow-list and the addresses that
 * called in the last 7 days (refused calls = not on the list). Lists are
 * edited on the partner.
 */
export default function IpManagement({
    enforced,
    partners,
}: {
    enforced: boolean;
    partners: Partner[];
}) {
    return (
        <>
            <Head title="IP Management" />
            <PageHeader
                title="IP Management"
                description="Which addresses may call the Partner API for each partner, and which actually did in the last 7 days."
            />
            <Panel className="px-4 py-3 text-[13px]">
                Allow-lists are{' '}
                <b className={enforced ? 'text-ok' : 'text-er'}>
                    {enforced ? 'enforced' : 'not enforced on this server'}
                </b>
                {enforced
                    ? '. A partner without allowed IPs can’t call the API.'
                    : ' (PAYGATE_API_ENFORCE_IP=false). Never switch this off outside your own machine.'}
            </Panel>
            <div className="grid gap-3 lg:grid-cols-2">
                {partners.map((partner) => {
                    const unknown = partner.calls.filter(
                        (call) => call.refused > 0,
                    );

                    return (
                        <Panel
                            key={partner.id}
                            className="flex flex-col gap-3 p-4"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <div>
                                    <div className="font-semibold">
                                        {partner.name}{' '}
                                        <span className="font-mono text-xs text-tx3">
                                            {partner.code}
                                        </span>
                                    </div>
                                    <div className="text-xs text-tx3">
                                        {partner.rules.length === 0
                                            ? 'No allowed IPs: the API is closed'
                                            : `${partner.rules.length} allowed`}
                                        {unknown.length > 0 && (
                                            <span className="text-er">
                                                {' '}
                                                · {unknown.length} refused
                                                address
                                                {unknown.length > 1 ? 'es' : ''}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <StatusBadge status={partner.status} />
                                    <Link
                                        href={
                                            admin.partners.edit(partner.id).url
                                        }
                                        className="text-xs font-medium text-ac"
                                    >
                                        Edit ›
                                    </Link>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {partner.rules.map((rule) => (
                                    <span
                                        key={rule}
                                        className="rounded-md bg-sf2 px-2 py-0.5 font-mono text-xs"
                                    >
                                        {rule}
                                    </span>
                                ))}
                            </div>
                            <SimpleTable
                                empty="No API calls in the last 7 days."
                                headers={[
                                    'Caller IP',
                                    'Calls',
                                    'Refused',
                                    'Last call',
                                ]}
                                rows={partner.calls.map((call) => [
                                    <span key="i" className="font-mono text-xs">
                                        {call.ip ?? '—'}
                                    </span>,
                                    call.calls.toLocaleString('en-IN'),
                                    <span
                                        key="r"
                                        className={
                                            call.refused > 0
                                                ? 'font-medium text-er'
                                                : ''
                                        }
                                    >
                                        {call.refused}
                                    </span>,
                                    formatDateTime(call.last_at),
                                ])}
                            />
                        </Panel>
                    );
                })}
            </div>
        </>
    );
}
