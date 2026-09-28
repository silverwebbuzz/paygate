<?php

namespace App\Domain\Platform;

use App\Domain\Core\Identity\Models\User;

/**
 * Section rollout (Admin › Testing › Section rollout, super admins only):
 * while it is switched on, every user except super admins sees only the
 * menu items a super admin has opened, in all three portals, so a client
 * can test one section at a time (decided 2026-09-28: global for all
 * non-super-admin users, per menu item, confirmations tracked outside the
 * app).
 *
 * A closed item disappears from the menu and its pages are refused
 * (EnforceSectionRollout). Each item owns the URL paths under it; a path
 * belongs to the item with the longest matching path, so "Statement
 * History" (/branch/statements/imports) can be open while "A/C Statement
 * Entry" (/branch/statements) is closed. Dashboards and Profile & settings
 * are always open. The first path of each item is its menu link.
 *
 * Keep SECTIONS in step with resources/js/lib/portal-nav.ts when a menu
 * item is added.
 */
class SectionRollout
{
    /** Setting value: {active: bool, open: {admin: [keys], branch: [keys], partner: [keys]}} */
    public const SETTING = 'rollout.sections';

    /**
     * @var array<string, array<string, array<string, array{label: string, paths: list<string>}>>>
     */
    public const SECTIONS = [
        'admin' => [
            'Payments' => [
                'transactions' => ['label' => 'Transactions', 'paths' => ['/admin/transactions', '/admin/webhooks']],
                'deposits' => ['label' => 'Manual Deposit', 'paths' => ['/admin/deposits']],
                'payouts' => ['label' => 'Manual Payout', 'paths' => ['/admin/payouts']],
                'refunds' => ['label' => 'Refunds', 'paths' => ['/admin/refunds', '/admin/reversals']],
                'chargebacks' => ['label' => 'Chargebacks', 'paths' => ['/admin/chargebacks', '/admin/reversals']],
            ],
            'Reconciliation' => [
                'statements' => ['label' => 'Manual A/C Statement', 'paths' => ['/admin/statements']],
                'utr' => ['label' => 'UTR Reconciliation', 'paths' => ['/admin/utr-reconciliation']],
                'unsettled' => ['label' => 'Unsettled UTR', 'paths' => ['/admin/unsettled']],
            ],
            'Network' => [
                'partners' => ['label' => 'Partners', 'paths' => ['/admin/partners']],
                'branches' => ['label' => 'Branches', 'paths' => ['/admin/branches']],
                'mappings' => ['label' => 'Branch mapping', 'paths' => ['/admin/mappings']],
                'accounts' => ['label' => 'Bank & UPI Accounts', 'paths' => ['/admin/accounts']],
            ],
            'Finance' => [
                'settlements' => ['label' => 'Settlement', 'paths' => ['/admin/settlements']],
                'commissions' => ['label' => 'Commissions', 'paths' => ['/admin/commissions']],
                'reports' => ['label' => 'Reports', 'paths' => ['/admin/reports']],
            ],
            'System' => [
                'users' => ['label' => 'Users', 'paths' => ['/admin/users']],
                'roles' => ['label' => 'Roles & Permissions', 'paths' => ['/admin/roles']],
                'settings' => ['label' => 'Global Settings', 'paths' => ['/admin/settings']],
                'ip' => ['label' => 'IP Management', 'paths' => ['/admin/ip-management']],
                'audit' => ['label' => 'Audit Logs', 'paths' => ['/admin/audit-logs']],
            ],
        ],
        'branch' => [
            'Accounts' => [
                'accounts' => ['label' => 'Bank & UPI Accounts', 'paths' => ['/branch/accounts']],
            ],
            'Operations' => [
                'deposits' => ['label' => 'Manual Deposit', 'paths' => ['/branch/deposits']],
                'payouts' => ['label' => 'Manual Payout', 'paths' => ['/branch/payouts']],
                'unsettled' => ['label' => 'Deposit Unsettled', 'paths' => ['/branch/unsettled']],
            ],
            'Statements' => [
                'statements' => ['label' => 'A/C Statement Entry', 'paths' => ['/branch/statements']],
                'imports' => ['label' => 'Statement History', 'paths' => ['/branch/statements/imports']],
            ],
            'History' => [
                'payins' => ['label' => 'Pay-in History', 'paths' => ['/branch/payins']],
                'payout_history' => ['label' => 'Pay-out History', 'paths' => ['/branch/payout-history']],
                'utr' => ['label' => 'UTR Reconciliation', 'paths' => ['/branch/utr-reconciliation']],
            ],
            'Finance' => [
                'balance' => ['label' => 'Branch Balance', 'paths' => ['/branch/balance']],
                'settlements' => ['label' => 'Settlement', 'paths' => ['/branch/settlements']],
                'reports' => ['label' => 'Reports', 'paths' => ['/branch/reports']],
            ],
            'Admin' => [
                'users' => ['label' => 'Users', 'paths' => ['/branch/users']],
                'audit' => ['label' => 'Audit Logs', 'paths' => ['/branch/audit-logs']],
            ],
        ],
        'partner' => [
            'Payments' => [
                'payins' => ['label' => 'Pay-in', 'paths' => ['/partner/payins', '/partner/webhooks']],
                'payouts' => ['label' => 'Pay-out', 'paths' => ['/partner/payouts']],
            ],
            'Finance' => [
                'settlements' => ['label' => 'Settlements', 'paths' => ['/partner/settlements']],
                'balance' => ['label' => 'Balance', 'paths' => ['/partner/balance']],
                'reports' => ['label' => 'Reports', 'paths' => ['/partner/reports']],
            ],
            'Developers' => [
                'developers' => ['label' => 'API & Webhooks', 'paths' => ['/partner/developers']],
                'api_docs' => ['label' => 'API documentation', 'paths' => ['/partner/api-docs']],
                'api_logs' => ['label' => 'API Logs', 'paths' => ['/partner/api-logs']],
            ],
            'Admin' => [
                'users' => ['label' => 'Users', 'paths' => ['/partner/users']],
            ],
            'Account' => [
                'profile' => ['label' => 'Business profile', 'paths' => ['/partner/profile']],
            ],
        ],
    ];

    public function __construct(private Settings $settings) {}

    /**
     * @return array{active: bool, open: array<string, list<string>>}
     */
    public function state(): array
    {
        $value = (array) ($this->settings->get(self::SETTING) ?? []);
        $open = [];

        foreach (array_keys(self::SECTIONS) as $portal) {
            $open[$portal] = array_values(array_intersect(array_keys($this->items($portal)), (array) ($value['open'][$portal] ?? [])));
        }

        return ['active' => (bool) ($value['active'] ?? false), 'open' => $open];
    }

    /**
     * @param  array<string, list<string>>  $open
     */
    public function save(bool $active, array $open, User $actor): void
    {
        $clean = [];

        foreach (array_keys(self::SECTIONS) as $portal) {
            $clean[$portal] = array_values(array_intersect(array_keys($this->items($portal)), $open[$portal] ?? []));
        }

        $this->settings->set(self::SETTING, ['active' => $active, 'open' => $clean], $actor);
    }

    /**
     * Whether rollout limits this person at all.
     */
    public function limits(?User $user): bool
    {
        return $user !== null && ! $user->isSuperAdmin() && $this->state()['active'];
    }

    /**
     * The menu links (first path) of the items closed for this person.
     *
     * @return list<string>
     */
    public function hiddenLinks(?User $user): array
    {
        if ($user === null || ! $this->limits($user)) {
            return [];
        }

        $portal = $user->type->value;
        $open = $this->state()['open'][$portal] ?? [];
        $hidden = [];

        foreach ($this->items($portal) as $key => $item) {
            if (! in_array($key, $open, true)) {
                $hidden[] = $item['paths'][0];
            }
        }

        return $hidden;
    }

    /**
     * Whether this person may open this path (e.g. "/branch/statements/imports").
     */
    public function allows(?User $user, string $path): bool
    {
        if ($user === null || ! $this->limits($user)) {
            return true;
        }

        $portal = $user->type->value;
        $path = '/'.ltrim($path, '/');
        $longest = 0;
        $owners = [];

        foreach ($this->items($portal) as $key => $item) {
            foreach ($item['paths'] as $prefix) {
                if ($path !== $prefix && ! str_starts_with($path, $prefix.'/')) {
                    continue;
                }

                if (strlen($prefix) > $longest) {
                    [$longest, $owners] = [strlen($prefix), [$key]];
                } elseif (strlen($prefix) === $longest) {
                    $owners[] = $key;
                }
            }
        }

        // Not part of any section (dashboard, settings, files…): always open.
        return $owners === [] || array_intersect($owners, $this->state()['open'][$portal] ?? []) !== [];
    }

    /**
     * @return array<string, array{label: string, paths: list<string>}>
     */
    private function items(string $portal): array
    {
        return array_merge(...array_values(self::SECTIONS[$portal] ?? [[]]));
    }
}
