import type { InertiaLinkProps } from '@inertiajs/react';
import admin from '@/routes/admin';
import branch from '@/routes/branch';
import partner from '@/routes/partner';
import { edit as profile } from '@/routes/profile';
import type { UserType } from '@/types';

/**
 * Sidebar menus per portal, following the design's navigation
 * (Document/PayGate UI redesign). Items without `href` are planned for the
 * phase in `soon` and render disabled, so the menu is complete from day one.
 */
export type NavLink = {
    label: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    soon?: number;
    permission?: string;
};

export type NavGroup = { label: string; items: NavLink[] };

export const PORTAL_LABELS: Record<UserType, string> = {
    admin: 'Admin',
    branch: 'Branch',
    partner: 'Partner',
};

const account: NavGroup = {
    label: 'Account',
    items: [{ label: 'Profile & settings', href: profile() }],
};

export const PORTAL_NAV: Record<UserType, NavGroup[]> = {
    admin: [
        {
            label: 'Overview',
            items: [{ label: 'Dashboard', href: admin.dashboard() }],
        },
        {
            label: 'Payments',
            items: [
                { label: 'Transactions', soon: 7 },
                { label: 'Manual Deposit', soon: 7 },
                { label: 'Manual Payout', soon: 8 },
                { label: 'Refunds', soon: 12 },
                { label: 'Chargebacks', soon: 12 },
            ],
        },
        {
            label: 'Reconciliation',
            items: [
                { label: 'Manual A/C Statement', soon: 9 },
                { label: 'Auto A/C Statement', soon: 9 },
                { label: 'UTR Reconciliation', soon: 9 },
                { label: 'Unsettled UTR', soon: 9 },
            ],
        },
        {
            label: 'Network',
            items: [
                { label: 'Partners', soon: 4 },
                { label: 'Branches', soon: 5 },
                { label: 'Bank & UPI Accounts', soon: 5 },
            ],
        },
        {
            label: 'Finance',
            items: [
                { label: 'Settlement', soon: 10 },
                { label: 'Commissions', soon: 10 },
                { label: 'Reports', soon: 11 },
            ],
        },
        {
            label: 'System',
            items: [
                { label: 'Users', soon: 3 },
                { label: 'Roles & Permissions', soon: 3 },
                { label: 'Global Settings', soon: 12 },
                { label: 'IP Management', soon: 12 },
                { label: 'Audit Logs', soon: 12 },
            ],
        },
        account,
    ],
    branch: [
        {
            label: 'Overview',
            items: [{ label: 'Dashboard', href: branch.dashboard() }],
        },
        {
            label: 'Accounts',
            items: [{ label: 'Bank & UPI Accounts', soon: 5 }],
        },
        {
            label: 'Operations',
            items: [
                { label: 'Manual Deposit', soon: 7 },
                { label: 'Manual Payout', soon: 8 },
                { label: 'Deposit Unsettled', soon: 9 },
            ],
        },
        {
            label: 'Statements',
            items: [
                { label: 'A/C Statement Entry', soon: 9 },
                { label: 'Auto A/C Statement', soon: 9 },
                { label: 'Statement History', soon: 9 },
            ],
        },
        {
            label: 'History',
            items: [
                { label: 'Pay-in History', soon: 7 },
                { label: 'Pay-out History', soon: 8 },
                { label: 'UTR Reconciliation', soon: 9 },
            ],
        },
        {
            label: 'Finance',
            items: [
                { label: 'Branch Balance', soon: 10 },
                { label: 'Settlement', soon: 10 },
                { label: 'Reports', soon: 11 },
            ],
        },
        {
            label: 'Admin',
            items: [
                { label: 'Users', soon: 3 },
                { label: 'Audit Logs', soon: 12 },
            ],
        },
        account,
    ],
    partner: [
        {
            label: 'Overview',
            items: [{ label: 'Dashboard', href: partner.dashboard() }],
        },
        {
            label: 'Payments',
            items: [
                { label: 'Create Payment', soon: 6 },
                { label: 'Pay-in', soon: 7 },
                { label: 'Pay-out', soon: 8 },
            ],
        },
        {
            label: 'Finance',
            items: [
                { label: 'Settlements', soon: 10 },
                { label: 'Balance', soon: 8 },
                { label: 'Reports', soon: 11 },
            ],
        },
        {
            label: 'Developers',
            items: [
                { label: 'API & Webhooks', soon: 4 },
                { label: 'IP Whitelist', soon: 4 },
                { label: 'API Logs', soon: 6 },
            ],
        },
        account,
    ],
};
