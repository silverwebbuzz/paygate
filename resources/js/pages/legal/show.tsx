import { Head } from '@inertiajs/react';
import { formatDateTime } from '@/lib/dates';

type Props = { title: string; html: string; updated_at: string | null };

/**
 * A published content page (terms, privacy, help), public. The HTML comes
 * from Markdown on the server with raw HTML stripped.
 */
export default function LegalPage({ title, html, updated_at }: Props) {
    return (
        <div className="min-h-screen bg-bg px-4 py-10 text-tx">
            <Head title={title} />
            <article className="mx-auto max-w-[720px] rounded-xl border border-ln bg-sf p-6 sm:p-8">
                <div className="mb-4 text-xs font-semibold tracking-[.06em] text-tx3 uppercase">
                    PayGate
                </div>
                <div
                    className="flex flex-col gap-3 text-[14px] leading-relaxed [&_a]:text-ac [&_a]:underline [&_h1]:text-2xl [&_h1]:font-semibold [&_h2]:mt-3 [&_h2]:text-lg [&_h2]:font-semibold [&_li]:ml-5 [&_ol]:list-decimal [&_ul]:list-disc"
                    dangerouslySetInnerHTML={{ __html: html }}
                />
                {updated_at && (
                    <p className="mt-6 text-xs text-tx3">
                        Last updated {formatDateTime(updated_at)}
                    </p>
                )}
            </article>
        </div>
    );
}
