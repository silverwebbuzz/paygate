import { Head } from '@inertiajs/react';
import { ExternalLink, Info, Search, TriangleAlert } from 'lucide-react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import { Panel } from '@/components/pg/data-table';
import { Mark } from '@/components/pg/guide-mocks';
import { PageHeader } from '@/components/pg/page-header';
import { GUIDE, GUIDE_GROUPS } from '@/lib/guide-content';
import type { GuideBlock, GuideSection } from '@/lib/guide-content';
import { cn } from '@/lib/utils';

/**
 * Guide (Admin only): the whole system in simple English, section by
 * section, with drawings of the screens and links that open the real page
 * in a new tab. Content: lib/guide-content.tsx.
 */
export default function Guide() {
    const [query, setQuery] = useState('');
    const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);

    const sections =
        words.length === 0
            ? GUIDE
            : GUIDE.filter((section) => {
                  const text = sectionText(section);

                  return words.every((word) => text.includes(word));
              });

    return (
        <>
            <Head title="Guide" />
            <PageHeader
                title="Guide"
                description="Everything about our system in simple words: IDs, menus, flows, rules and what to do when something is stuck. Use it to answer any branch or partner question. Links open the real page in a new tab."
            />

            <div className="grid items-start gap-4 lg:grid-cols-[230px_minmax(0,1fr)]">
                <nav className="flex flex-col gap-3 lg:sticky lg:top-4">
                    <label className="relative block">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-tx3" />
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search: UTR, IP, Match, chargeback…"
                            className="h-9 w-full rounded-[8px] border border-ln bg-sf pr-2.5 pl-8 text-[13px] outline-none focus:border-ac"
                        />
                    </label>
                    <Panel className="hidden max-h-[calc(100vh-140px)] overflow-y-auto p-2 lg:block">
                        {GUIDE_GROUPS.map((group) => {
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
                                                className="flex items-center gap-2 rounded-md px-2 py-1.5 text-[12.5px] text-tx2 hover:bg-acs hover:text-ac"
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
                            example “UTR”, “payout” or “IP”.
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

function SectionCard({ section }: { section: GuideSection }) {
    return (
        <Panel className="scroll-mt-4 p-4 sm:p-5">
            <section id={section.id} className="flex flex-col gap-4">
                <header className="flex items-start gap-3">
                    <span className="flex size-9 flex-none items-center justify-center rounded-[10px] bg-acs text-ac">
                        <section.icon className="size-[18px]" />
                    </span>
                    <div className="min-w-0">
                        <div className="text-[10.5px] font-semibold tracking-[.06em] text-tx3 uppercase">
                            {section.group}
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

                {section.links && section.links.length > 0 && (
                    <div className="flex flex-col gap-1.5 border-t border-ln pt-3">
                        <div className="text-[11px] font-semibold tracking-[.04em] text-tx3 uppercase">
                            Open in the system (new tab)
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {section.links.map((link) => (
                                <a
                                    key={link.url + link.label}
                                    href={link.url}
                                    target="_blank"
                                    rel="noopener"
                                    className="inline-flex items-center gap-1.5 rounded-[7px] border border-ln bg-sf px-2.5 py-1 text-[12px] hover:border-ac hover:text-ac"
                                >
                                    <span className="font-medium">
                                        {link.label}
                                    </span>
                                    <span className="font-mono text-[11px] text-tx3">
                                        {link.url}
                                    </span>
                                    <ExternalLink className="size-3 text-tx3" />
                                </a>
                            ))}
                        </div>
                    </div>
                )}
            </section>
        </Panel>
    );
}

function Block({ block }: { block: GuideBlock }) {
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
        case 'mock':
            return (
                <figure className="flex flex-col gap-2 rounded-xl border border-dashed border-ln bg-bg/60 p-3">
                    <figcaption className="text-[12px] font-semibold text-tx2">
                        Screen: {block.title}
                        <span className="ml-1.5 font-normal text-tx3">
                            (drawing, not live data)
                        </span>
                    </figcaption>
                    {block.node}
                    {block.marks.length > 0 && (
                        <ol className="mt-1 flex flex-col gap-1.5">
                            {block.marks.map((text, i) => (
                                <li
                                    key={i}
                                    className="flex gap-2 text-[12.5px] text-tx2"
                                >
                                    <Mark n={i + 1} />
                                    <span>{rich(text)}</span>
                                </li>
                            ))}
                        </ol>
                    )}
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
function sectionText(section: GuideSection): string {
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
            case 'mock':
                parts.push(...block.marks);
                break;
        }
    }

    for (const link of section.links ?? []) parts.push(link.label, link.url);

    return parts.join(' ').replace(/[*`]/g, '').toLowerCase();
}
