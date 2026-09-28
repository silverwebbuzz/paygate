import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /** The bell (G-47), null when signed out. */
            alerts: {
                unread: number;
                latest: {
                    id: string;
                    title: string;
                    body: string;
                    url: string | null;
                    read: boolean;
                    at: string | null;
                }[];
            } | null;
            environment: string;
            /** Admin › QA Checklist is available (local and staging only). */
            qaChecklist: boolean;
            [key: string]: unknown;
        };
    }
}
