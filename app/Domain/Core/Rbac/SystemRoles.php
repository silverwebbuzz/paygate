<?php

namespace App\Domain\Core\Rbac;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Rbac\Enums\Permission as P;

/**
 * Built-in roles created by the migrations. Admin can create more roles and
 * edit these; `is_system` roles can't be deleted. Slugs are stable keys used
 * by code, seeders and tests.
 *
 * The super admin role is locked: it always holds every admin permission
 * (including ones added to the catalogue later), so Admin can never lock
 * itself out of the roles screen. Only super admins hand it out or manage
 * its users; the Admin role starts with the same permissions but not the
 * super-admin tools.
 */
final class SystemRoles
{
    public const ADMIN_SUPER = 'admin.super';

    public const ADMIN_FULL = 'admin.admin';

    public const ADMIN_OPS = 'admin.ops';

    public const ADMIN_FINANCE = 'admin.finance';

    public const ADMIN_VIEWER = 'admin.viewer';

    public const PARTNER_OWNER = 'partner.owner';

    public const PARTNER_DEVELOPER = 'partner.developer';

    public const PARTNER_VIEWER = 'partner.viewer';

    public const BRANCH_OWNER = 'branch.owner';

    public const BRANCH_OPERATOR = 'branch.operator';

    /**
     * @return array<string, array{type: UserType, name: string, description: string, permissions: list<P>}>
     */
    public static function definitions(): array
    {
        return [
            self::ADMIN_SUPER => [
                'type' => UserType::Admin,
                'name' => 'Super admin',
                'description' => 'Full control of the platform. Always holds every admin permission and can\'t be edited.',
                'permissions' => P::forType(UserType::Admin),
            ],
            self::ADMIN_FULL => [
                'type' => UserType::Admin,
                'name' => 'Admin',
                // Shown to the client: no mention of the (hidden) super admin role.
                'description' => 'Full access to the admin portal.',
                'permissions' => P::forType(UserType::Admin),
            ],
            self::ADMIN_OPS => [
                'type' => UserType::Admin,
                'name' => 'Operations',
                'description' => 'Verifies accounts, maps branches, oversees transactions and reconciliation.',
                'permissions' => [
                    P::PartnersView, P::BranchesView, P::MappingsView, P::MappingsUpdate,
                    P::AccountsView, P::AccountsVerify,
                    P::PayinsView, P::PayinsApprove, P::PayoutsView, P::PayoutsProcess,
                    P::ReversalsView, P::ReversalsCreate,
                    P::StatementsView, P::StatementsCreate, P::ReconciliationView, P::ReconciliationResolve,
                    P::BalancesView, P::ReportsView, P::AuditLogsView,
                ],
            ],
            self::ADMIN_FINANCE => [
                'type' => UserType::Admin,
                'name' => 'Finance',
                'description' => 'Calculates and records settlements and adjustments.',
                'permissions' => [
                    P::PartnersView, P::BranchesView, P::PayinsView, P::PayoutsView,
                    P::ReversalsView, P::ReversalsCreate,
                    P::BalancesView, P::CommissionsView,
                    P::SettlementsView, P::SettlementsCreate, P::SettlementsUpdate,
                    P::AdjustmentsView, P::AdjustmentsCreate,
                    P::ReportsView, P::ReportsExport, P::AuditLogsView,
                ],
            ],
            self::ADMIN_VIEWER => [
                'type' => UserType::Admin,
                'name' => 'Viewer',
                'description' => 'Read-only access.',
                'permissions' => [
                    P::PartnersView, P::BranchesView, P::PayinsView, P::PayoutsView,
                    P::ReversalsView, P::BalancesView, P::SettlementsView, P::ReportsView,
                ],
            ],
            self::PARTNER_OWNER => [
                'type' => UserType::Partner,
                'name' => 'Owner',
                'description' => 'Full access to the partner portal, including its users.',
                'permissions' => P::forType(UserType::Partner),
            ],
            self::PARTNER_DEVELOPER => [
                'type' => UserType::Partner,
                'name' => 'Developer',
                'description' => 'Manages the API integration.',
                'permissions' => [
                    P::PayinsView, P::PayoutsView,
                    P::ApiKeysView, P::ApiKeysCreate, P::ApiKeysDelete,
                    P::WebhooksView, P::WebhooksUpdate,
                    P::IpRulesView, P::IpRulesCreate, P::IpRulesDelete, P::ApiLogsView,
                ],
            ],
            self::PARTNER_VIEWER => [
                'type' => UserType::Partner,
                'name' => 'Viewer',
                'description' => 'Views transactions, balance and reports.',
                'permissions' => [P::PayinsView, P::PayoutsView, P::BalancesView, P::SettlementsView, P::ReportsView],
            ],
            self::BRANCH_OWNER => [
                'type' => UserType::Branch,
                'name' => 'Branch admin',
                'description' => 'Full access to the branch portal, including its users.',
                'permissions' => P::forType(UserType::Branch),
            ],
            self::BRANCH_OPERATOR => [
                'type' => UserType::Branch,
                'name' => 'Operator',
                'description' => 'Approves deposits, processes payouts, imports statements.',
                'permissions' => [
                    P::AccountsView, P::PayinsView, P::PayinsApprove, P::PayoutsView, P::PayoutsProcess,
                    P::StatementsView, P::StatementsCreate, P::ReconciliationView,
                ],
            ],
        ];
    }
}
