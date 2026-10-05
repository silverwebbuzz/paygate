<?php

namespace App\Domain\Core\Rbac\Enums;

use App\Domain\Core\Identity\Enums\UserType;

/**
 * A row of the permission grid: one menu of a portal, grouped as in the
 * design's sidebar. Case order is the grid's row order.
 */
enum Menu: string
{
    case Partners = 'partners';
    case Branches = 'branches';
    case Mappings = 'mappings';
    case Accounts = 'accounts';
    case Payins = 'payins';
    case Payouts = 'payouts';
    case Reversals = 'reversals';
    case Statements = 'statements';
    case Reconciliation = 'reconciliation';
    case Balances = 'balances';
    case Commissions = 'commissions';
    case Settlements = 'settlements';
    case Adjustments = 'adjustments';
    case Reports = 'reports';
    case ApiKeys = 'api_keys';
    case Webhooks = 'webhooks';
    case IpRules = 'ip_rules';
    case ApiLogs = 'api_logs';
    case Users = 'users';
    case Roles = 'roles';
    case AuditLogs = 'audit_logs';
    case Settings = 'settings';
    case Horizon = 'horizon';

    public function label(): string
    {
        return match ($this) {
            self::Partners => 'Partners',
            self::Branches => 'Branches',
            self::Mappings => 'Partner ↔ branch mapping',
            self::Accounts => 'Bank & UPI accounts',
            self::Payins => 'Pay-ins (deposits)',
            self::Payouts => 'Payouts (withdrawals)',
            self::Reversals => 'Refunds & chargebacks',
            self::Statements => 'Account statements',
            self::Reconciliation => 'Reconciliation',
            self::Balances => 'Balances',
            self::Commissions => 'Commissions',
            self::Settlements => 'Settlements',
            self::Adjustments => 'Adjustments',
            self::Reports => 'Reports',
            self::ApiKeys => 'API keys',
            self::Webhooks => 'Webhooks',
            self::IpRules => 'IP whitelist',
            self::ApiLogs => 'API logs',
            self::Users => 'Users',
            self::Roles => 'Roles & permissions',
            self::AuditLogs => 'Audit logs',
            self::Settings => 'Global settings',
            self::Horizon => 'Queue monitor (Horizon)',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::Partners, self::Branches, self::Mappings, self::Accounts => 'Network',
            self::Payins, self::Payouts, self::Reversals => 'Payments',
            self::Statements, self::Reconciliation => 'Reconciliation',
            self::Balances, self::Commissions, self::Settlements, self::Adjustments, self::Reports => 'Finance',
            self::ApiKeys, self::Webhooks, self::IpRules, self::ApiLogs => 'Developers',
            self::Users, self::Roles, self::AuditLogs, self::Settings, self::Horizon => 'System',
        };
    }

    /**
     * Portals that have this menu at all.
     *
     * @return list<UserType>
     */
    public function userTypes(): array
    {
        $all = [UserType::Admin, UserType::Partner, UserType::Branch];

        return match ($this) {
            self::Accounts, self::Payins, self::Payouts, self::Balances, self::Settlements, self::Reports, self::Users => $all,
            self::Statements, self::Reconciliation, self::AuditLogs => [UserType::Admin, UserType::Branch],
            self::ApiKeys, self::Webhooks, self::IpRules, self::ApiLogs => [UserType::Admin, UserType::Partner],
            default => [UserType::Admin],
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(?UserType $type = null): array
    {
        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission) => $permission->menu() === $this && ($type === null || $permission->allowedFor($type)),
        ));
    }
}
