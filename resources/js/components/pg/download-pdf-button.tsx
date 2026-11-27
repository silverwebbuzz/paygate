import { Download } from 'lucide-react';
import { useState } from 'react';
import { PgButton } from '@/components/pg/button';

/**
 * "Download PDF" for the guides (client request 2026-11-27). Opens the
 * browser's print dialog, where "Save as PDF" is the destination; the print
 * styles hide the menu, the top bar and the guide's own side menu, and the
 * page prints in light mode with every picture loaded. `fileName` becomes
 * the suggested PDF name. `prepare` runs first (e.g. clear the search so
 * every section is in the PDF) and may return a promise.
 */
export function DownloadPdfButton({
    fileName,
    prepare,
}: {
    fileName: string;
    prepare?: () => void | Promise<void>;
}) {
    const [busy, setBusy] = useState(false);

    const download = async () => {
        setBusy(true);

        try {
            await prepare?.();
            // Let React draw what prepare changed.
            await new Promise((resolve) => setTimeout(resolve, 50));

            const images = Array.from(document.images);
            images.forEach((image) => (image.loading = 'eager'));
            await Promise.race([
                Promise.all(
                    images
                        .filter((image) => !image.complete)
                        .map(
                            (image) =>
                                new Promise((resolve) => {
                                    image.addEventListener('load', resolve, {
                                        once: true,
                                    });
                                    image.addEventListener('error', resolve, {
                                        once: true,
                                    });
                                }),
                        ),
                ),
                new Promise((resolve) => setTimeout(resolve, 8000)),
            ]);

            const root = document.documentElement;
            const dark = root.classList.contains('dark');
            const title = document.title;
            const scheme = root.style.colorScheme;

            root.classList.remove('dark');
            root.style.colorScheme = 'light';
            document.title = fileName;

            const restore = () => {
                if (dark) root.classList.add('dark');
                root.style.colorScheme = scheme;
                document.title = title;
            };

            window.addEventListener('afterprint', restore, { once: true });
            window.print();
        } finally {
            setBusy(false);
        }
    };

    return (
        <PgButton onClick={download} disabled={busy} className="print:hidden">
            <Download className="size-4" />
            {busy ? 'Preparing…' : 'Download PDF'}
        </PgButton>
    );
}
