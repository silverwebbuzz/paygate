import { Head, usePage } from '@inertiajs/react';
import { Info, Search, TriangleAlert } from 'lucide-react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import { Panel } from '@/components/pg/data-table';
import { DownloadPdfButton } from '@/components/pg/download-pdf-button';
import { PageHeader } from '@/components/pg/page-header';
import { BRANCH_GUIDE, BRANCH_GUIDE_GROUPS } from '@/lib/branch-guide-content';
import type {
    BranchGuideBlock,
    BranchGuideSection,
} from '@/lib/branch-guide-content';
import { cn } from '@/lib/utils';
import type { Auth } from '@/types';

/**
 * Guide (Branch portal, client request 2026-11-26): how to use the branch
 * portal step by step, with screenshots. Every branch user may open it.
 * Content: lib/branch-guide-content.ts; pictures: public/guide/branch/.
 */
export default function BranchGuide() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const isAdmin = auth.permissions.includes('users.view');
    const [query, setQuery] = useState('');
    const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);

    const sections =
        words.length === 0
            ? BRANCH_GUIDE
            : BRANCH_GUIDE.filter((section) => {
                  const text = sectionText(section);

                  return words.every((word) => text.includes(word));
              });

    return (
        <>
            <Head title="Guide" />
            <PageHeader
                title="Guide"
                description="How to use the branch portal, step by step with pictures. Start at the top if you are new; search for a word if you have a question."
                actions={
                    <DownloadPdfButton
                        fileName="Branch portal guide"
                        prepare={() => setQuery('')}
                    />
                }
            />

            <div className="grid items-start gap-4 lg:grid-cols-[240px_minmax(0,1fr)] print:block">
                <nav className="flex flex-col gap-3 lg:sticky lg:top-4 print:hidden">
                    <label className="relative block">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-tx3" />
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search: approve, UTR, password…"
                            className="h-9 w-full rounded-[8px] border border-ln bg-sf pr-2.5 pl-8 text-[13px] outline-none focus:border-ac"
                        />
                    </label>
                    <Panel className="hidden max-h-[calc(100vh-140px)] overflow-y-auto p-2 lg:block">
                        {BRANCH_GUIDE_GROUPS.map((group) => {
                            const items = sections.filter(
                                (section) => section.group === group,
                            );

                            return (
                                items.length > 0 && (
                                    <div key={group} className="mb-2 last:mb-0">
                                        <div className="px-2 py-1 text-[10.5px] font-semibold tracking-[.06em] text-tx3 uppercase">
                                            {group}
                                        </div>
                                        {items.map((section) => (
                                            <a
                                                key={section.id}
                                                href={`#${section.id}`}
                                                className={cn(
                                                    'flex items-center gap-2 rounded-md px-2 py-1.5 text-[12.5px] text-tx2 hover:bg-acs hover:text-ac',
                                                    section.who === 'admin' &&
                                                        !isAdmin &&
                                                        'opacity-60',
                                                )}
                                            >
                                                <section.icon className="size-3.5 flex-none" />
                                                <span className="truncate">
                                                    {section.title}
                                                </span>
                                            </a>
                                        ))}
                                    </div>
                                )
                            );
                        })}
                    </Panel>
                </nav>

                <div className="flex min-w-0 flex-col gap-4">
                    {sections.length === 0 && (
                        <Panel className="p-8 text-center text-[13px] text-tx3">
                            Nothing found for “{query}”. Try a shorter word, for
                            example “approve”, “UTR” or “payout”.
                        </Panel>
                    )}
                    {sections.map((section) => (
                        <SectionCard key={section.id} section={section} />
                    ))}
                </div>
            </div>
        </>
    );
}

function SectionCard({ section }: { section: BranchGuideSection }) {
    return (
        <Panel className="scroll-mt-4 p-4 sm:p-5 print:shadow-none">
            <section id={section.id} className="flex flex-col gap-4">
                <header className="flex items-start gap-3">
                    <span className="flex size-9 flex-none items-center justify-center rounded-[10px] bg-acs text-ac">
                        <section.icon className="size-[18px]" />
                    </span>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2 text-[10.5px] font-semibold tracking-[.06em] text-tx3 uppercase">
                            {section.group}
                            {section.who === 'admin' && (
                                <span className="rounded-full bg-wnb px-2 py-px tracking-normal text-wn normal-case">
                                    Branch admin only
                                </span>
                            )}
                        </div>
                        <h2 className="text-[16px] font-semibold text-tx">
                            {section.title}
                        </h2>
                        <p className="mt-0.5 text-[13px] text-tx2">
                            {rich(section.summary)}
                        </p>
                    </div>
                </header>

                {section.blocks.map((block, i) => (
                    <Block key={i} block={block} />
                ))}
            </section>
        </Panel>
    );
}

function Block({ block }: { block: BranchGuideBlock }) {
    const title = 'title' in block && block.title && (
        <h3 className="mb-1.5 text-[12px] font-semibold tracking-[.04em] text-tx3 uppercase">
            {block.title}
        </h3>
    );

    switch (block.kind) {
        case 'points':
            return (
                <div>
                    {title}
                    <ul className="flex flex-col gap-1.5">
                        {block.items.map((item, i) => (
                            <li
                                key={i}
                                className="flex gap-2 text-[13px] leading-relaxed text-tx2"
                            >
                                <span className="mt-[7px] size-1.5 flex-none rounded-full bg-ac" />
                                <span>{rich(item)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            );
        case 'steps':
            return (
                <div>
                    {title}
                    <ol className="flex flex-col gap-2">
                        {block.items.map((item, i) => (
                            <li
                                key={i}
                                className="flex gap-2.5 text-[13px] leading-relaxed text-tx2"
                            >
                                <span className="flex size-[22px] flex-none items-center justify-center rounded-full border border-ac text-[11px] font-semibold text-ac">
                                    {i + 1}
                                </span>
                                <span className="pt-0.5">{rich(item)}</span>
                            </li>
                        ))}
                    </ol>
                </div>
            );
        case 'table':
            return (
                <div>
                    {title}
                    <div className="overflow-x-auto rounded-lg border border-ln">
                        <table className="w-full text-left text-[12.5px]">
                            <thead className="bg-bg text-[11px] text-tx3">
                                <tr>
                                    {block.head.map((cell, i) => (
                                        <th
                                            key={i}
                                            className="px-3 py-2 font-semibold whitespace-nowrap"
                                        >
                                            {cell}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {block.rows.map((row, i) => (
                                    <tr
                                        key={i}
                                        className="border-t border-ln align-top"
                                    >
                                        {row.map((cell, j) => (
                                            <td
                                                key={j}
                                                className={cn(
                                                    'px-3 py-2 text-tx2',
                                                    j === 0 &&
                                                        'font-medium text-tx',
                                                )}
                                            >
                                                {rich(cell)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            );
        case 'note':
            return (
                <div
                    className={cn(
                        'flex gap-2 rounded-lg px-3 py-2.5 text-[13px]',
                        block.tone === 'warn'
                            ? 'bg-wnb text-wn'
                            : 'bg-inb text-in',
                    )}
                >
                    {block.tone === 'warn' ? (
                        <TriangleAlert className="mt-0.5 size-4 flex-none" />
                    ) : (
                        <Info className="mt-0.5 size-4 flex-none" />
                    )}
                    <span>{rich(block.text)}</span>
                </div>
            );
        case 'shot':
            return (
                <figure
                    className={cn(
                        'flex flex-col gap-1.5',
                        block.narrow && 'max-w-[460px]',
                    )}
                >
                    <a
                        href={block.src}
                        target="_blank"
                        rel="noopener"
                        title="Open the picture full size"
                        className="block overflow-hidden rounded-lg border border-ln bg-bg shadow-sm hover:border-ac"
                    >
                        <img
                            src={block.src}
                            alt={block.caption}
                            loading="lazy"
                            className="block h-auto w-full"
                        />
                    </a>
                    <figcaption className="text-[12px] text-tx3">
                        {rich(block.caption)}{' '}
                        <span className="text-tx3/70">(click to enlarge)</span>
                    </figcaption>
                </figure>
            );
    }
}

/** **bold** and `code` in the guide's text. */
function rich(text: string): ReactNode {
    return text.split(/(\*\*[^*]+\*\*|`[^`]+`)/g).map((part, i) => {
        if (part.startsWith('**') && part.endsWith('**')) {
            return (
                <strong key={i} className="font-semibold text-tx">
                    {part.slice(2, -2)}
                </strong>
            );
        }

        if (part.startsWith('`') && part.endsWith('`')) {
            return (
                <code
                    key={i}
                    className="rounded bg-sf2 px-1 py-px font-mono text-[11.5px] text-tx"
                >
                    {part.slice(1, -1)}
                </code>
            );
        }

        return <Fragment key={i}>{part}</Fragment>;
    });
}

/** Everything a search can find in a section. */
function sectionText(section: BranchGuideSection): string {
    const parts: string[] = [section.group, section.title, section.summary];

    for (const block of section.blocks) {
        if ('title' in block && block.title) parts.push(block.title);

        switch (block.kind) {
            case 'points':
            case 'steps':
                parts.push(...block.items);
                break;
            case 'table':
                parts.push(...block.head, ...block.rows.flat());
                break;
            case 'note':
                parts.push(block.text);
                break;
            case 'shot':
                parts.push(block.caption);
                break;
        }
    }

    return parts.join(' ').toLowerCase();
}
