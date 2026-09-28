import { Head, Link, useForm } from '@inertiajs/react';
import { ExternalLink, Plus } from 'lucide-react';
import { useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { EmptyState } from '@/components/pg/empty-state';
import { Field, SelectInput, TextArea, TextInput } from '@/components/pg/field';
import { PageHeader } from '@/components/pg/page-header';
import { StatusBadge } from '@/components/pg/status-badge';
import { formatDateTime } from '@/lib/dates';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';

type PageRow = {
    id: string;
    slug: string;
    title: string;
    body: string;
    status: 'draft' | 'published';
    updated_at: string | null;
    url: string;
};

/**
 * Content pages (G-48): terms, privacy, payment help… written in Markdown.
 * Published pages are public and linked from the customer payment page.
 */
export default function Pages({ pages }: { pages: PageRow[] }) {
    const [selected, setSelected] = useState<string | null>(
        pages[0]?.id ?? null,
    );
    const current = pages.find((page) => page.id === selected) ?? null;

    return (
        <>
            <Head title="Content pages" />
            <PageHeader
                title="Content pages"
                description="Pages customers and partners can read: terms, privacy, payment help. Write them in Markdown; only published pages are public (linked from the payment page)."
                actions={
                    <>
                        <Link
                            href={admin.settings.index().url}
                            className="inline-flex h-8 items-center rounded-[7px] border border-ln bg-sf px-3 text-[13px] font-medium"
                        >
                            Global Settings
                        </Link>
                        <PgButton
                            variant="primary"
                            onClick={() => setSelected('new')}
                        >
                            <Plus className="size-3.5" /> New page
                        </PgButton>
                    </>
                }
            />
            <div className="grid gap-3 lg:grid-cols-[260px_1fr]">
                <Panel className="flex h-fit flex-col p-1.5">
                    {pages.length === 0 && (
                        <p className="px-3 py-4 text-xs text-tx3">
                            No pages yet.
                        </p>
                    )}
                    {pages.map((page) => (
                        <button
                            key={page.id}
                            type="button"
                            onClick={() => setSelected(page.id)}
                            className={cn(
                                'flex items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-[13px]',
                                page.id === selected
                                    ? 'bg-acs font-semibold text-act'
                                    : 'text-tx2 hover:bg-sf2',
                            )}
                        >
                            <span className="truncate">{page.title}</span>
                            <StatusBadge
                                status={
                                    page.status === 'published'
                                        ? 'active'
                                        : 'draft'
                                }
                                label={page.status}
                            />
                        </button>
                    ))}
                </Panel>
                {selected === 'new' ? (
                    <Editor
                        key="new"
                        page={null}
                        onSaved={() => setSelected(null)}
                    />
                ) : current ? (
                    <Editor
                        key={current.id}
                        page={current}
                        onSaved={() => undefined}
                    />
                ) : (
                    <Panel>
                        <EmptyState
                            title="Choose a page"
                            description="Or create a new one."
                        />
                    </Panel>
                )}
            </div>
        </>
    );
}

function Editor({
    page,
    onSaved,
}: {
    page: PageRow | null;
    onSaved: () => void;
}) {
    const form = useForm({
        title: page?.title ?? '',
        slug: '',
        body: page?.body ?? '',
        status: page?.status ?? 'draft',
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Panel className="flex flex-col gap-3 p-5">
            <form
                className="flex flex-col gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    const options = {
                        preserveScroll: true,
                        onSuccess: onSaved,
                    };

                    if (page) {
                        form.transform(({ slug: _slug, ...data }) => data);
                        form.put(admin.pages.update(page.id).url, options);
                    } else {
                        form.post(admin.pages.store().url, options);
                    }
                }}
            >
                <div className="grid gap-3 sm:grid-cols-[1fr_200px_160px]">
                    <Field label="Title" error={errors.title}>
                        <TextInput
                            required
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                        />
                    </Field>
                    {page ? (
                        <Field label="Address">
                            <a
                                href={page.url}
                                target="_blank"
                                rel="noreferrer"
                                className="flex h-10 items-center gap-1 text-[13px] text-ac"
                            >
                                /legal/{page.slug}{' '}
                                <ExternalLink className="size-3" />
                            </a>
                        </Field>
                    ) : (
                        <Field
                            label="Address (optional)"
                            hint="From the title if empty."
                            error={errors.slug}
                        >
                            <TextInput
                                placeholder="terms-of-use"
                                value={form.data.slug}
                                onChange={(event) =>
                                    form.setData('slug', event.target.value)
                                }
                            />
                        </Field>
                    )}
                    <Field label="Status" error={errors.status}>
                        <SelectInput
                            value={form.data.status}
                            onChange={(event) =>
                                form.setData(
                                    'status',
                                    event.target.value as PageRow['status'],
                                )
                            }
                        >
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </SelectInput>
                    </Field>
                </div>
                <Field
                    label="Text (Markdown)"
                    hint="# Heading, **bold**, - list, [link](https://…). HTML is not allowed."
                    error={errors.body}
                >
                    <TextArea
                        required
                        className="min-h-[360px] font-mono text-[13px]"
                        value={form.data.body}
                        onChange={(event) =>
                            form.setData('body', event.target.value)
                        }
                    />
                </Field>
                <div className="flex items-center justify-between">
                    <span className="text-xs text-tx3">
                        {page?.updated_at
                            ? `Last saved ${formatDateTime(page.updated_at)}`
                            : 'Not saved yet'}
                    </span>
                    <PgButton
                        type="submit"
                        variant="primary"
                        disabled={form.processing}
                    >
                        {page ? 'Save page' : 'Create page'}
                    </PgButton>
                </div>
            </form>
        </Panel>
    );
}
