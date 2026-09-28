import type { InertiaLinkProps } from '@inertiajs/react';
import admin from '@/routes/admin';
import adminAccounts from '@/routes/admin/accounts';
import adminCases from '@/routes/admin/cases';
import adminDeposits from '@/routes/admin/deposits';
import adminTransactions from '@/routes/admin/transactions';
import adminBranches from '@/routes/admin/branches';
import adminMappings from '@/routes/admin/mappings';
import adminPartners from '@/routes/admin/partners';
import adminPayouts from '@/routes/admin/payouts';
import adminReconciliation from '@/routes/admin/reconciliation';
import adminRoles from '@/routes/admin/roles';
import adminSettlements from '@/routes/admin/settlements';
import adminStatements from '@/routes/admin/statements';
import adminUsers from '@/routes/admin/users';
import branch from '@/routes/branch';
import branchAccounts from '@/routes/branch/accounts';
import branchCases from '@/routes/branch/cases';
import branchDeposits from '@/routes/branch/deposits';
import branchPayins from '@/routes/branch/payins';
import branchPayouts from '@/routes/branch/payouts';
import branchReconciliation from '@/routes/branch/reconciliation';
import branchImports from '@/routes/branch/statement-imports';
import branchStatements from '@/routes/branch/statements';
import branchUsers from '@/routes/branch/users';
import partner, {
    balance as partnerBalance,
    apiDocs as partnerApiDocs,
    apiLogs as partnerApiLogs,
    profile as partnerProfile,
} from '@/routes/partner';
import partnerDevelopers from '@/routes/partner/developers';
import partnerPayins from '@/routes/partner/payins';
import partnerPayouts from '@/routes/partner/payouts';
import partnerSettlements from '@/routes/partner/settlements';
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
    /** Planned phase, or 'later' while it waits for a client decision. */
    soon?: number | 'later';
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
                {
                    label: 'Transactions',
                    href: adminTransactions.index(),
                    permission: 'payins.view',
                },
                {
                    label: 'Manual Deposit',
                    href: adminDeposits.index(),
                    permission: 'payins.view',
                },
                {
                    label: 'Manual Payout',
                    href: adminPayouts.index(),
                    permission: 'payouts.view',
                },
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
            ],
        },
        {
            label: 'Reconciliation',
            items: [
                {
                    label: 'Manual A/C Statement',
                    href: adminStatements.index(),
                    permission: 'statements.view',
                },
                // Source not decided yet (bank API, email, SMS…; G-27).
                { label: 'Auto A/C Statement', soon: 'later' },
                {
                    label: 'UTR Reconciliation',
                    href: adminReconciliation.index(),
                    permission: 'reconciliation.view',
                },
                {
                    label: 'Unsettled UTR',
                    href: adminCases.index(),
                    permission: 'reconciliation.view',
                },
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
                {
                    label: 'Settlement',
                    href: adminSettlements.index(),
                    permission: 'settlements.view',
                },
                {
                    label: 'Commissions',
                    href: admin.commissions.index(),
                    permission: 'commissions.view',
                },
                {
                    label: 'Reports',
                    href: admin.reports.index(),
                    permission: 'reports.view',
                },
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
                {
                    label: 'Manual Deposit',
                    href: branchDeposits.index(),
                    permission: 'payins.view',
                },
                {
                    label: 'Manual Payout',
                    href: branchPayouts.index(),
                    permission: 'payouts.view',
                },
                {
                    label: 'Deposit Unsettled',
                    href: branchCases.index(),
                    permission: 'reconciliation.view',
                },
            ],
        },
        {
            label: 'Statements',
            items: [
                {
                    label: 'A/C Statement Entry',
                    href: branchStatements.index(),
                    permission: 'statements.view',
                },
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
            items: [
                {
                    label: 'Pay-in History',
                    href: branchPayins.index(),
                    permission: 'payins.view',
                },
                {
                    label: 'Pay-out History',
                    href: branchPayouts.history(),
                    permission: 'payouts.view',
                },
                {
                    label: 'UTR Reconciliation',
                    href: branchReconciliation.index(),
                    permission: 'reconciliation.view',
                },
            ],
        },
        {
            label: 'Finance',
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
                    label: 'Reports',
                    href: branch.reports.index(),
                    permission: 'reports.view',
                },
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
            items: [{ label: 'Dashboard', href: partner.dashboard() }],
        },
        {
            label: 'Payments',
            items: [
                // Manual payment links are out of v1 (OOS-13).
                { label: 'Create Payment', soon: 'later' },
                {
                    label: 'Pay-in',
                    href: partnerPayins.index(),
                    permission: 'payins.view',
                },
                {
                    label: 'Pay-out',
                    href: partnerPayouts.index(),
                    permission: 'payouts.view',
                },
            ],
        },
        {
            label: 'Finance',
            items: [
                {
                    label: 'Settlements',
                    href: partnerSettlements.index(),
                    permission: 'settlements.view',
                },
                {
                    label: 'Balance',
                    href: partnerBalance(),
                    permission: 'balances.view',
                },
                {
                    label: 'Reports',
                    href: partner.reports.index(),
                    permission: 'reports.view',
                },
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
