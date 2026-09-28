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
            /** Super admins get Admin › Testing (QA Checklist, Section rollout). */
            superAdmin: boolean;
            /** Admin › QA Checklist is available (super admin, local and staging only). */
            qaChecklist: boolean;
            /** Section rollout: menu links of sections not open for this person. */
            rolloutHidden: string[];
            /** Section rollout limits this person (planned items are hidden too). */
            rolloutLimited: boolean;
            [key: string]: unknown;
        };
    }
}
