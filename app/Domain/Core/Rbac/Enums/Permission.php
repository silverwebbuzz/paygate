<?php

namespace App\Domain\Core\Rbac\Enums;

use App\Domain\Core\Identity\Enums\UserType;

/**
 * The permission catalogue, one `menu.action` per permission. Roles (stored in
 * the database, editable by Admin) grant a subset of these. Values double as
 * Gate ability names: `$user->can('payins.approve')`.
 *
 * The roles screen shows the catalogue as the design's grid: one row per menu
 * (Menu enum), columns View / Insert / Update / Delete, plus special actions
 * such as Approve or Verify that don't fit those four.
 *
 * Partner and branch users are always scoped to their own organisation, so the
 * same permission (e.g. payins.view) means "all" for an admin and "own" for a
 * partner or branch user.
 */
enum Permission: string
{
    case PartnersView = 'partners.view';
    case PartnersCreate = 'partners.create';
    case PartnersUpdate = 'partners.update';

    case BranchesView = 'branches.view';
    case BranchesCreate = 'branches.create';
    case BranchesUpdate = 'branches.update';

    case MappingsView = 'mappings.view';
    case MappingsUpdate = 'mappings.update';

    case AccountsView = 'accounts.view';
    case AccountsCreate = 'accounts.create';
    case AccountsUpdate = 'accounts.update';
    case AccountsVerify = 'accounts.verify';

    case PayinsView = 'payins.view';
    case PayinsCreate = 'payins.create';
    case PayinsApprove = 'payins.approve';

    case PayoutsView = 'payouts.view';
    case PayoutsCreate = 'payouts.create';
    case PayoutsProcess = 'payouts.process';

    case ReversalsView = 'reversals.view';
    case ReversalsCreate = 'reversals.create';

    case StatementsView = 'statements.view';
    case StatementsCreate = 'statements.create';

    case ReconciliationView = 'reconciliation.view';
    case ReconciliationResolve = 'reconciliation.resolve';

    case BalancesView = 'balances.view';

    case CommissionsView = 'commissions.view';
    case CommissionsUpdate = 'commissions.update';

    case SettlementsView = 'settlements.view';
    case SettlementsCreate = 'settlements.create';
    case SettlementsUpdate = 'settlements.update';

    case AdjustmentsView = 'adjustments.view';
    case AdjustmentsCreate = 'adjustments.create';
    case AdjustmentsApprove = 'adjustments.approve';

    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    case ApiKeysView = 'api_keys.view';
    case ApiKeysCreate = 'api_keys.create';
    case ApiKeysDelete = 'api_keys.delete';

    case WebhooksView = 'webhooks.view';
    case WebhooksUpdate = 'webhooks.update';

    case IpRulesView = 'ip_rules.view';
    case IpRulesCreate = 'ip_rules.create';
    case IpRulesDelete = 'ip_rules.delete';

    case ApiLogsView = 'api_logs.view';

    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';

    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';

    case AuditLogsView = 'audit_logs.view';

    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';

    case HorizonView = 'horizon.view';

    public function menu(): Menu
    {
        return Menu::from(explode('.', $this->value)[0]);
    }

    public function action(): string
    {
        return explode('.', $this->value)[1];
    }

    /**
     * Which portals a role holding this permission may belong to: the menu's
     * portals, narrowed for actions only Admin may perform.
     *
     * @return list<UserType>
     */
    public function userTypes(): array
    {
        $types = $this->menu()->userTypes();

        return match ($this) {
            // Pay-ins and payouts are created by the partner (or Admin on its
            // behalf) and approved / paid by the branch (or Admin).
            self::PayinsCreate, self::PayoutsCreate => [UserType::Admin, UserType::Partner],
            self::PayinsApprove, self::PayoutsProcess => [UserType::Admin, UserType::Branch],
            // Branches add their accounts; only Admin verifies them.
            self::AccountsCreate, self::AccountsUpdate => [UserType::Admin, UserType::Branch],
            self::AccountsVerify => [UserType::Admin],
            // Settlements are calculated and ticked by Admin only.
            self::SettlementsCreate, self::SettlementsUpdate => [UserType::Admin],
            default => $types,
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
