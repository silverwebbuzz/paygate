import type { InertiaLinkProps } from '@inertiajs/react';
import admin from '@/routes/admin';
import adminAccounts from '@/routes/admin/accounts';
import adminBranches from '@/routes/admin/branches';
import adminMappings from '@/routes/admin/mappings';
import adminPartners from '@/routes/admin/partners';
import adminRoles from '@/routes/admin/roles';
import adminUsers from '@/routes/admin/users';
import branch from '@/routes/branch';
import branchAccounts from '@/routes/branch/accounts';
import branchUsers from '@/routes/branch/users';
import partner, { profile as partnerProfile } from '@/routes/partner';
import partnerDevelopers from '@/routes/partner/developers';
import partnerUsers from '@/routes/partner/users';
import { edit as profile } from '@/routes/profile';
import type { UserType } from '@/types';

/**
 * Sidebar menus per portal, following the design's navigation
 * (Document/PayGate UI redesign). Items without `href` are planned for the
 * phase in `soon` and render disabled, so the menu is complete from day one.
 * Items with `permission` are hidden from users whose role lacks it (the
 * server checks again on every request).
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
                {
                    label: 'Partners',
                    href: adminPartners.index(),
                    permission: 'partners.view',
                },
                {
                    label: 'Branches',
                    href: adminBranches.index(),
                    permission: 'branches.view',
                },
                {
                    label: 'Branch mapping',
                    href: adminMappings.index(),
                    permission: 'mappings.view',
                },
                {
                    label: 'Bank & UPI Accounts',
                    href: adminAccounts.index(),
                    permission: 'accounts.view',
                },
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
                {
                    label: 'Users',
                    href: adminUsers.index(),
                    permission: 'users.view',
                },
                {
                    label: 'Roles & Permissions',
                    href: adminRoles.index(),
                    permission: 'roles.view',
                },
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
            items: [
                {
                    label: 'Bank & UPI Accounts',
                    href: branchAccounts.index(),
                    permission: 'accounts.view',
                },
            ],
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
                {
                    label: 'Users',
                    href: branchUsers.index(),
                    permission: 'users.view',
                },
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
                {
                    label: 'API & Webhooks',
                    href: partnerDevelopers.show(),
                    permission: 'api_keys.view',
                },
                { label: 'API Logs', soon: 6 },
            ],
        },
        {
            label: 'Admin',
            items: [
                {
                    label: 'Users',
                    href: partnerUsers.index(),
                    permission: 'users.view',
                },
            ],
        },
        {
            label: 'Account',
            items: [
                { label: 'Business profile', href: partnerProfile() },
                ...account.items,
            ],
        },
    ],
};
