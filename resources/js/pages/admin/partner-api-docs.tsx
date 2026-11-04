import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Panel } from '@/components/pg/data-table';
import { PageHeader } from '@/components/pg/page-header';
import admin from '@/routes/admin';

/**
 * Developer › Partner API Docs: the standard integration guide — the same
 * document as a partner's file, without their key, IPs, URLs or limits — to
 * share with a partner's developers before they are set up.
 */
export default function PartnerApiDocs() {
    return (
        <>
            <Head title="Partner API Docs" />
            <PageHeader
                title="Partner API Docs"
                description="The standard guide for integrating a partner with PayGate: signing, pay-ins, payouts, webhooks, status and balance. Each partner’s own file (Partners › Download partner file) adds their key and URLs."
                actions={
                    <a
                        href={admin.partnerApiDocs.download().url}
                        className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-transparent bg-brand px-3 text-[13px] font-medium whitespace-nowrap text-white hover:brightness-110"
                    >
                        <Download className="size-3.5" /> Download PDF
                    </a>
                }
            />
            <Panel className="overflow-hidden">
                <iframe
                    src={admin.partnerApiDocs.preview().url}
                    title="Partner integration guide"
                    className="block h-[75vh] w-full bg-white"
                />
            </Panel>
        </>
    );
}
