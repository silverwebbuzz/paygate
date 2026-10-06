import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Poppins', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('JetBrains Mono', {
                    weights: [400, 500],
                }),
            ],
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    optimizeDeps: {
        include: [
            'apexcharts/core',
            'apexcharts/line',
            'apexcharts/bar',
            'apexcharts/features/legend',
            'apexcharts/features/toolbar',
            'apexcharts/features/exports',
        ],
    },
    build: {
        chunkSizeWarningLimit: 750,
    },
    // Vite runs in Docker: listen on all interfaces, but tell the browser to
    // load assets and HMR from the host name it uses for the app.
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: `http://${process.env.VITE_DEV_HOST ?? 'localhost'}:5173`,
        hmr: { host: process.env.VITE_DEV_HOST ?? 'localhost' },
        cors: { origin: /^https?:\/\/([a-z0-9-]+\.)*paygate\.local(:\d+)?$/ },
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'Document/PayGate UI redesign/**',
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            // Design exports from the claude.ai design tool: keep byte-for-byte.
            'Document/PayGate UI redesign/**',
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
