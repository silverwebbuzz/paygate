<?php

namespace App\Auth;

use App\Enums\Permission as P;
use App\Enums\UserType;

/**
 * Built-in roles created by the migrations. Admin can create more roles and
 * edit these; `is_system` roles can't be deleted. Slugs are stable keys used
 * by code, seeders and tests.
 */
final class SystemRoles
{
    public const ADMIN_SUPER = 'admin.super';

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
                'description' => 'Full control of the platform.',
                'permissions' => P::forType(UserType::Admin),
            ],
            self::ADMIN_OPS => [
                'type' => UserType::Admin,
                'name' => 'Operations',
                'description' => 'Verifies accounts, maps branches, oversees transactions and reconciliation.',
                'permissions' => [
                    P::PartnerView, P::BranchView, P::MappingManage,
                    P::AccountView, P::AccountVerify,
                    P::TransactionView, P::TransactionDecide, P::PayoutProcess,
                    P::StatementImport, P::ReconciliationView, P::ReconciliationResolve,
                    P::BalanceView, P::ReportView, P::AuditView,
                ],
            ],
            self::ADMIN_FINANCE => [
                'type' => UserType::Admin,
                'name' => 'Finance',
                'description' => 'Calculates and records settlements and adjustments.',
                'permissions' => [
                    P::PartnerView, P::BranchView, P::TransactionView,
                    P::BalanceView, P::SettlementView, P::SettlementManage, P::AdjustmentRequest,
                    P::ReportView, P::ReportExport, P::AuditView,
                ],
            ],
            self::ADMIN_VIEWER => [
                'type' => UserType::Admin,
                'name' => 'Viewer',
                'description' => 'Read-only access.',
                'permissions' => [
                    P::PartnerView, P::BranchView, P::TransactionView,
                    P::BalanceView, P::SettlementView, P::ReportView,
                ],
            ],
            self::PARTNER_OWNER => [
                'type' => UserType::Partner,
                'name' => 'Owner',
                'description' => 'Full access to the partner portal.',
                'permissions' => P::forType(UserType::Partner),
            ],
            self::PARTNER_DEVELOPER => [
                'type' => UserType::Partner,
                'name' => 'Developer',
                'description' => 'Manages the API integration.',
                'permissions' => [P::TransactionView, P::ApiKeyManage, P::WebhookManage, P::IpRuleManage, P::ApiLogView],
            ],
            self::PARTNER_VIEWER => [
                'type' => UserType::Partner,
                'name' => 'Viewer',
                'description' => 'Views transactions, balance and reports.',
                'permissions' => [P::TransactionView, P::BalanceView, P::SettlementView, P::ReportView],
            ],
            self::BRANCH_OWNER => [
                'type' => UserType::Branch,
                'name' => 'Branch admin',
                'description' => 'Full access to the branch portal.',
                'permissions' => P::forType(UserType::Branch),
            ],
            self::BRANCH_OPERATOR => [
                'type' => UserType::Branch,
                'name' => 'Operator',
                'description' => 'Approves deposits, processes payouts, imports statements.',
                'permissions' => [
                    P::AccountView, P::TransactionView, P::TransactionDecide, P::PayoutProcess,
                    P::StatementImport, P::ReconciliationView,
                ],
            ],
        ];
    }
}
