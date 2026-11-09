import type { InertiaLinkProps } from '@inertiajs/react';
import {
    Code,
    FileText,
    GitCompareArrows,
    History,
    Landmark,
    LayoutDashboard,
    Network,
    Settings,
    User,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import admin from '@/routes/admin';
import adminAccounts from '@/routes/admin/accounts';
import adminBranches from '@/routes/admin/branches';
import adminMappings from '@/routes/admin/mappings';
import adminPartners from '@/routes/admin/partners';
import adminRoles from '@/routes/admin/roles';
import adminSettlements from '@/routes/admin/settlements';
import adminUsers from '@/routes/admin/users';
import branch from '@/routes/branch';
import branchAccounts from '@/routes/branch/accounts';
import branchImports from '@/routes/branch/statement-imports';
import branchUsers from '@/routes/branch/users';
import partner, {
    balance as partnerBalance,
    apiDocs as partnerApiDocs,
    apiLogs as partnerApiLogs,
    profile as partnerProfile,
} from '@/routes/partner';
import partnerAccounts from '@/routes/partner/accounts';
import partnerDevelopers from '@/routes/partner/developers';
import partnerPayoutReturns from '@/routes/partner/payout-returns';
import partnerSettlements from '@/routes/partner/settlements';
import partnerUsers from '@/routes/partner/users';
import { edit as profile } from '@/routes/profile';
import type { HubKind } from '@/lib/payment-hub';
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
    /** Planned phase, or 'later' while it waits for a client decision. */
    soon?: number | 'later';
    permission?: string;
    /**
     * Payments › Deposit / Withdrawal: a page of tabs (lib/payment-hub).
     * Shown while any tab is open to the person, linking to the first.
     */
    hub?: HubKind;
};

/**
 * A group with one item shows as a single link (e.g. Dashboard); larger
 * groups fold open under their heading. `id` tells apart groups that share
 * a label (the statements group and Profile & settings are both "Account").
 */
export type NavGroup = {
    id?: string;
    label: string;
    icon: LucideIcon;
    items: NavLink[];
};

export const navGroupId = (group: NavGroup): string => group.id ?? group.label;

export const PORTAL_LABELS: Record<UserType, string> = {
    admin: 'Admin',
    branch: 'Branch',
    partner: 'Partner',
};

const account: NavGroup = {
    label: 'Account',
    icon: User,
    items: [{ label: 'Profile & settings', href: profile() }],
};

export const PORTAL_NAV: Record<UserType, NavGroup[]> = {
    admin: [
        {
            label: 'Overview',
            icon: LayoutDashboard,
            items: [{ label: 'Dashboard', href: admin.dashboard() }],
        },
        {
            label: 'Network',
            icon: Network,
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
                    label: 'Partner → branches',
                    href: adminMappings.byPartner(),
                    permission: 'mappings.view',
                },
                {
                    label: 'Branch → partners',
                    href: adminMappings.byBranch(),
                    permission: 'mappings.view',
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
            label: 'Payments',
            icon: Wallet,
            items: [
                // All deposits (Transactions, opens first), Manual Deposit,
                // Manual A/C Statement, Unsettled UTR and UTR Reconciliation,
                // as tabs of one page.
                { label: 'Deposit', hub: 'deposit' },
                // All withdrawals (opens first), Manual Payout, UTR Reconciliation.
                { label: 'Withdrawal', hub: 'withdrawal' },
                {
                    label: 'Refunds',
                    href: admin.refunds.index(),
                    permission: 'reversals.view',
                },
                {
                    label: 'Chargebacks',
                    href: admin.chargebacks.index(),
                    permission: 'reversals.view',
                },
                {
                    label: 'Payout returns',
                    href: admin.payoutReturns.index(),
                    permission: 'reversals.view',
                },
            ],
        },
        {
            id: 'reconciliation',
            label: 'Account',
            icon: GitCompareArrows,
            items: [
                // Manual A/C Statement, UTR Reconciliation and Unsettled UTR
                // are tabs of Payments › Deposit.
                // Source not decided yet (bank API, email, SMS…; G-27).
                { label: 'Auto A/C Statement', soon: 'later' },
            ],
        },
        {
            label: 'Finance',
            icon: Landmark,
            items: [
                {
                    label: 'Settlement',
                    href: adminSettlements.index(),
                    permission: 'settlements.view',
                },
                {
                    label: 'Admin Settlement Dashboard',
                    href: admin.settlementDashboard('admin'),
                    permission: 'settlements.view',
                },
                {
                    label: 'Branch Settlement Dashboard',
                    href: admin.settlementDashboard('branch'),
                    permission: 'settlements.view',
                },
                {
                    label: 'Partner Settlement Dashboard',
                    href: admin.settlementDashboard('partner'),
                    permission: 'settlements.view',
                },
                {
                    label: 'Commissions',
                    href: admin.commissions.index(),
                    permission: 'commissions.view',
                },
            ],
        },
        {
            label: 'Reports',
            icon: FileText,
            items: [
                {
                    label: 'Branch Deposit Report',
                    href: admin.reports.show({ report: 'branch_deposits' }),
                    permission: 'reports.view',
                },
                {
                    label: 'Partner Deposit Report',
                    href: admin.reports.show({ report: 'partner_deposits' }),
                    permission: 'reports.view',
                },
                {
                    label: 'Branch Withdrawal Report',
                    href: admin.reports.show({ report: 'branch_withdrawals' }),
                    permission: 'reports.view',
                },
                {
                    label: 'Partner Withdrawal Report',
                    href: admin.reports.show({ report: 'partner_withdrawals' }),
                    permission: 'reports.view',
                },
            ],
        },
        {
            label: 'System',
            icon: Settings,
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
                {
                    label: 'Global Settings',
                    href: admin.settings.index(),
                    permission: 'settings.view',
                },
                {
                    label: 'IP Management',
                    href: admin.ipManagement.index(),
                    permission: 'ip_rules.view',
                },
                {
                    label: 'Audit Logs',
                    href: admin.auditLogs.index(),
                    permission: 'audit_logs.view',
                },
            ],
        },
        {
            // Hidden from admins until a super admin opens it in Section
            // rollout (super admins also get the UI kit here).
            label: 'Developer',
            icon: Code,
            items: [
                {
                    label: 'Partner API Docs',
                    href: admin.partnerApiDocs.show(),
                    permission: 'partners.view',
                },
            ],
        },
        account,
    ],
    branch: [
        {
            label: 'Overview',
            icon: LayoutDashboard,
            items: [{ label: 'Dashboard', href: branch.dashboard() }],
        },
        {
            label: 'Network',
            icon: Network,
            items: [
                {
                    label: 'Bank & UPI Accounts',
                    href: branchAccounts.index(),
                    permission: 'accounts.view',
                },
            ],
        },
        {
            label: 'Payments',
            icon: Wallet,
            items: [
                // All deposits (Pay-in History, opens first), Manual Deposit,
                // Manual A/C Statement, Unsettled UTR and UTR Reconciliation,
                // as tabs of one page.
                { label: 'Deposit', hub: 'deposit' },
                // All withdrawals (Pay-out History, opens first), Manual
                // Payout, UTR Reconciliation.
                { label: 'Withdrawal', hub: 'withdrawal' },
            ],
        },
        {
            id: 'reconciliation',
            label: 'Account',
            icon: GitCompareArrows,
            items: [
                { label: 'Auto A/C Statement', soon: 'later' },
                {
                    label: 'Statement History',
                    href: branchImports.index(),
                    permission: 'statements.view',
                },
            ],
        },
        {
            label: 'History',
            icon: History,
            items: [
                {
                    label: 'Chargebacks',
                    href: branch.chargebacks.index(),
                    permission: 'reversals.view',
                },
                {
                    label: 'Payout returns',
                    href: branch.payoutReturns.index(),
                    permission: 'payouts.view',
                },
            ],
        },
        {
            label: 'Finance',
            icon: Landmark,
            items: [
                {
                    label: 'Branch Balance',
                    href: branch.balance(),
                    permission: 'balances.view',
                },
                {
                    label: 'Settlement',
                    href: branch.settlements.index(),
                    permission: 'settlements.view',
                },
                {
                    label: 'Settlement Dashboard',
                    href: branch.settlementDashboard(),
                    permission: 'settlements.view',
                },
            ],
        },
        {
            label: 'Reports',
            icon: FileText,
            items: [
                {
                    label: 'Branch Deposit Report',
                    href: branch.reports.show({ report: 'branch_deposits' }),
                    permission: 'reports.view',
                },
                {
                    label: 'Branch Withdrawal Report',
                    href: branch.reports.show({ report: 'branch_withdrawals' }),
                    permission: 'reports.view',
                },
            ],
        },
        {
            label: 'System',
            icon: Settings,
            items: [
                {
                    label: 'Users',
                    href: branchUsers.index(),
                    permission: 'users.view',
                },
                {
                    label: 'Audit Logs',
                    href: branch.auditLogs.index(),
                    permission: 'audit_logs.view',
                },
            ],
        },
        account,
    ],
    partner: [
        {
            label: 'Overview',
            icon: LayoutDashboard,
            items: [{ label: 'Dashboard', href: partner.dashboard() }],
        },
        {
            label: 'Payments',
            icon: Wallet,
            items: [
                // Manual payment links are out of v1 (OOS-13).
                { label: 'Create Payment', soon: 'later' },
                { label: 'Deposit', hub: 'deposit' },
                {
                    label: 'Chargebacks',
                    href: partner.chargebacks.index(),
                    permission: 'payins.view',
                },
                { label: 'Withdrawal', hub: 'withdrawal' },
                {
                    label: 'Payout returns',
                    href: partnerPayoutReturns.index(),
                    permission: 'payouts.view',
                },
            ],
        },
        {
            label: 'Network',
            icon: Network,
            items: [
                {
                    label: 'Bank & UPI Accounts',
                    href: partnerAccounts.index(),
                    permission: 'accounts.view',
                },
            ],
        },
        {
            label: 'Finance',
            icon: Landmark,
            items: [
                {
                    label: 'Settlement',
                    href: partnerSettlements.index(),
                    permission: 'settlements.view',
                },
                {
                    label: 'Settlement Dashboard',
                    href: partner.settlementDashboard(),
                    permission: 'settlements.view',
                },
                {
                    label: 'Balance',
                    href: partnerBalance(),
                    permission: 'balances.view',
                },
            ],
        },
        {
            label: 'Reports',
            icon: FileText,
            items: [
                {
                    label: 'Partner Deposit Report',
                    href: partner.reports.show({ report: 'partner_deposits' }),
                    permission: 'reports.view',
                },
                {
                    label: 'Partner Withdrawal Report',
                    href: partner.reports.show({
                        report: 'partner_withdrawals',
                    }),
                    permission: 'reports.view',
                },
            ],
        },
        {
            label: 'Developers',
            icon: Code,
            items: [
                {
                    label: 'API & Webhooks',
                    href: partnerDevelopers.show(),
                    permission: 'api_keys.view',
                },
                {
                    label: 'API documentation',
                    href: partnerApiDocs(),
                    permission: 'api_keys.view',
                },
                {
                    label: 'API Logs',
                    href: partnerApiLogs(),
                    permission: 'api_logs.view',
                },
            ],
        },
        {
            label: 'System',
            icon: Settings,
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
            icon: User,
            items: [
                { label: 'Business profile', href: partnerProfile() },
                ...account.items,
            ],
        },
    ],
};
