import type { InertiaLinkProps } from '@inertiajs/react';
import type { StatementAccount } from '@/components/pg/statement-import-dialog';
import adminCases from '@/routes/admin/cases';
import adminDeposits from '@/routes/admin/deposits';
import adminPayouts from '@/routes/admin/payouts';
import adminReconciliation from '@/routes/admin/reconciliation';
import adminStatements from '@/routes/admin/statements';
import adminTransactions from '@/routes/admin/transactions';
import branchCases from '@/routes/branch/cases';
import branchDeposits from '@/routes/branch/deposits';
import branchPayins from '@/routes/branch/payins';
import branchPayouts from '@/routes/branch/payouts';
import branchReconciliation from '@/routes/branch/reconciliation';
import branchStatements from '@/routes/branch/statements';
import partnerPayins from '@/routes/partner/payins';
import partnerPayouts from '@/routes/partner/payouts';
import { toUrl } from '@/lib/utils';
import type { UserType } from '@/types';

/**
 * Payments › Deposit and Payments › Withdrawal (client request 2026-11-09):
 * the pages a person needs for a deposit (or a withdrawal) shown as the tabs
 * of one menu item, so nothing means hopping between menu items. Each tab
 * is still its own page (URL, permission, Section rollout item, rules);
 * PaymentHub (PHP) adds the shared `hub` prop: the counts on the tabs and
 * the bank entry bar's accounts.
 */
export type HubKind = 'deposit' | 'withdrawal';

export type HubView = 'queue' | 'statement' | 'unsettled' | 'utr' | 'all';

export type HubEntryAccount = StatementAccount & {
    upi: string | null;
    branch_id: string;
    branch: string;
};

export type HubProps = {
    kind: HubKind;
    counts: Partial<Record<HubView, number>>;
    /** The bank entry bar (statements.create), on every Deposit tab. */
    entry: {
        accounts: HubEntryAccount[];
        branches: { id: string; code: string; name: string }[];
    } | null;
};

type Href = NonNullable<InertiaLinkProps['href']>;

export type HubViewLink = {
    key: HubView;
    label: string;
    href: Href;
    permission: string;
};

export const HUB_TITLES: Record<HubKind, string> = {
    deposit: 'Deposit',
    withdrawal: 'Withdrawal',
};

/** Every tab of the page, in order, before permissions and rollout. */
export function hubViews(portal: UserType, kind: HubKind): HubViewLink[] {
    if (portal === 'partner') {
        return kind === 'deposit'
            ? [
                  {
                      key: 'all',
                      label: 'All deposits',
                      href: partnerPayins.index(),
                      permission: 'payins.view',
                  },
              ]
            : [
                  {
                      key: 'all',
                      label: 'All withdrawals',
                      href: partnerPayouts.index(),
                      permission: 'payouts.view',
                  },
              ];
    }

    const isAdmin = portal === 'admin';
    const reconciliation = isAdmin ? adminReconciliation : branchReconciliation;

    if (kind === 'deposit') {
        return [
            {
                key: 'queue',
                label: 'Manual Deposit',
                href: (isAdmin ? adminDeposits : branchDeposits).index(),
                permission: 'payins.view',
            },
            {
                key: 'statement',
                label: 'Manual A/C Statement',
                href: (isAdmin ? adminStatements : branchStatements).index(),
                permission: 'statements.view',
            },
            {
                key: 'unsettled',
                label: 'Unsettled UTR',
                href: (isAdmin ? adminCases : branchCases).index(),
                permission: 'reconciliation.view',
            },
            {
                key: 'utr',
                label: 'UTR Reconciliation',
                href: reconciliation.index({ query: { direction: 'payin' } }),
                permission: 'reconciliation.view',
            },
            {
                key: 'all',
                label: 'All deposits',
                href: isAdmin
                    ? adminTransactions.index()
                    : branchPayins.index(),
                permission: 'payins.view',
            },
        ];
    }

    return [
        {
            key: 'queue',
            label: 'Manual Payout',
            href: (isAdmin ? adminPayouts : branchPayouts).index(),
            permission: 'payouts.view',
        },
        {
            key: 'utr',
            label: 'UTR Reconciliation',
            href: reconciliation.index({ query: { direction: 'payout' } }),
            permission: 'reconciliation.view',
        },
        {
            key: 'all',
            label: 'All withdrawals',
            href: isAdmin
                ? adminTransactions.payouts()
                : branchPayouts.history(),
            permission: 'payouts.view',
        },
    ];
}

/** "//paygate.local/admin/statements?x=1" → "/admin/statements". */
export const hrefPath = (href: Href): string =>
    toUrl(href)
        .replace(/^(https?:)?\/\/[^/]+/, '')
        .replace(/[?#].*$/, '');

/**
 * The tabs this person can open: their role has the permission, and Section
 * rollout hasn't closed the page. Every tab is the menu link (first path) of
 * its own SectionRollout item, which is what rolloutHidden lists, so an
 * exact match is right (/admin/transactions/payouts is its own item).
 */
export function visibleHubViews(
    portal: UserType,
    kind: HubKind,
    permissions: string[],
    rolloutHidden: string[],
): HubViewLink[] {
    return hubViews(portal, kind).filter((view) => {
        const path = hrefPath(view.href);

        return (
            permissions.includes(view.permission) &&
            !rolloutHidden.includes(path)
        );
    });
}
