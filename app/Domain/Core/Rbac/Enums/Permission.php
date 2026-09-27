<?php

namespace App\Domain\Core\Rbac\Enums;

use App\Domain\Core\Identity\Enums\UserType;

/**
 * The permission catalogue. Roles (stored in the database, editable by Admin)
 * grant a subset of these. Values double as Gate ability names:
 * `$user->can('transaction.decide')`.
 *
 * Partner and branch users are always scoped to their own organisation, so
 * the same permission (e.g. transaction.view) means "all" for an admin and
 * "own" for a partner or branch user.
 */
enum Permission: string
{
    case PartnerView = 'partner.view';
    case PartnerManage = 'partner.manage';
    case BranchView = 'branch.view';
    case BranchManage = 'branch.manage';
    case MappingManage = 'mapping.manage';
    case CommissionManage = 'commission.manage';

    case AccountView = 'account.view';
    case AccountManage = 'account.manage';
    case AccountVerify = 'account.verify';

    case TransactionView = 'transaction.view';
    case TransactionDecide = 'transaction.decide';
    case PayoutProcess = 'payout.process';

    case StatementImport = 'statement.import';
    case ReconciliationView = 'reconciliation.view';
    case ReconciliationResolve = 'reconciliation.resolve';

    case BalanceView = 'balance.view';
    case SettlementView = 'settlement.view';
    case SettlementManage = 'settlement.manage';
    case AdjustmentRequest = 'adjustment.request';
    case AdjustmentApprove = 'adjustment.approve';

    case ReportView = 'report.view';
    case ReportExport = 'report.export';

    case ApiKeyManage = 'api_key.manage';
    case WebhookManage = 'webhook.manage';
    case IpRuleManage = 'ip_rule.manage';
    case ApiLogView = 'api_log.view';

    case UserManage = 'user.manage';
    case RoleManage = 'role.manage';
    case AuditView = 'audit.view';
    case SettingsManage = 'settings.manage';
    case HorizonView = 'horizon.view';

    /**
     * Which portals a role holding this permission may belong to.
     *
     * @return list<UserType>
     */
    public function userTypes(): array
    {
        return match ($this) {
            self::TransactionView,
            self::BalanceView,
            self::SettlementView,
            self::ReportView,
            self::ReportExport,
            self::UserManage => [UserType::Admin, UserType::Partner, UserType::Branch],

            self::AccountView,
            self::AccountManage,
            self::TransactionDecide,
            self::PayoutProcess,
            self::StatementImport,
            self::ReconciliationView,
            self::ReconciliationResolve => [UserType::Admin, UserType::Branch],

            self::ApiKeyManage,
            self::WebhookManage,
            self::IpRuleManage,
            self::ApiLogView => [UserType::Admin, UserType::Partner],

            default => [UserType::Admin],
        };
    }

    public function allowedFor(UserType $type): bool
    {
        return in_array($type, $this->userTypes(), true);
    }

    /**
     * @return list<self>
     */
    public static function forType(UserType $type): array
    {
        return array_values(array_filter(self::cases(), fn (self $permission) => $permission->allowedFor($type)));
    }
}
