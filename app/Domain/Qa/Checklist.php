<?php

namespace App\Domain\Qa;

use App\Support\Hosts;

/**
 * The QA checklist shown in Admin › QA Checklist (super admins, every environment):
 * every feature built so far, where it is, how to test it by hand, who to
 * log in as, and which automated tests cover it (`tests`: "ClassTest" for a
 * whole test class or "ClassTest::test_name" for one test; the Re-run
 * button runs exactly these).
 *
 * Keep it up to date: when a phase adds a screen or a rule, add a row here
 * in the same commit. Keys are stable ids: results are stored against them.
 */
final class Checklist
{
    /**
     * Who to log in as. The demo e-mails exist only on a local machine
     * (LocalDemoUserSeeder, password "password"); on staging, use a user
     * with the same role.
     *
     * @var array<string, array{label: string, email: string|null}>
     */
    public const LOGINS = [
        'admin' => ['label' => 'Super admin', 'email' => 'admin@paygate.local'],
        'ops' => ['label' => 'Admin · Operations', 'email' => 'ops@paygate.local'],
        'finance' => ['label' => 'Admin · Finance', 'email' => 'finance@paygate.local'],
        'partner' => ['label' => 'Partner owner', 'email' => 'partner@paygate.local'],
        'developer' => ['label' => 'Partner developer', 'email' => 'developer@paygate.local'],
        'branch' => ['label' => 'Branch owner', 'email' => 'branch@paygate.local'],
        'operator' => ['label' => 'Branch operator', 'email' => 'operator@paygate.local'],
        'guest' => ['label' => 'Not signed in', 'email' => null],
        'customer' => ['label' => 'Customer (payment link, no login)', 'email' => null],
        'api' => ['label' => 'Partner API key (signed call)', 'email' => null],
        'system' => ['label' => 'Nobody: automated only', 'email' => null],
    ];

    /**
     * @return list<array{key: string, title: string, items: list<array{key: string, name: string, url: string|null, description: string, steps: list<string>, login: list<string>, tests: list<string>}>}>
     */
    public static function sections(): array
    {
        return [
            self::section('access', 'Sign-in & security', [
                self::item('access.login', 'Login', '/login',
                    'One login page for everyone; each user lands in their own portal (/admin, /partner, /branch).',
                    ['Log in as each demo user and check you land in the right portal.', 'Enter a wrong password: an error shows and the attempt appears in Audit Logs › Security.', 'Try 6 wrong passwords quickly: you are asked to wait (rate limit).', 'Sign out from the sidebar.'],
                    ['admin', 'partner', 'branch'],
                    ['AuthenticationTest', 'DashboardTest']),
                self::item('access.two_factor', 'Two-factor authentication', '/settings/security',
                    'Admin and branch users must set up an authenticator app before using their portal; partners may.',
                    ['Log in as a new admin or branch user: you are sent to set up 2FA first.', 'Scan the QR code, enter the code, keep the recovery codes.', 'Sign out and in again: the 6-digit code is asked.', 'A partner user can use the portal without 2FA.'],
                    ['admin', 'branch'],
                    ['TwoFactorEnforcementTest', 'TwoFactorChallengeTest']),
                self::item('access.password_reset', 'Forgot password', '/forgot-password',
                    'A user who forgot the password gets a reset link by e-mail.',
                    ['Click "Forgot password" on the login page and enter a demo e-mail.', 'Open Mailpit (http://localhost:8025) and follow the link.', 'Set a new password and log in with it (set it back to "password" afterwards).'],
                    ['guest'],
                    ['PasswordResetTest', 'PasswordConfirmationTest']),
                self::item('access.portals', 'Portal separation', '/admin',
                    'Each user only reaches their own portal; each domain (portal, API, payment page) only answers its own pages.',
                    ['Log in as branch@ and open /admin and /partner: both are refused (403).', 'Check Audit Logs › Security as admin: the refusal is recorded.', 'Open http://api.paygate.local/login: not found.', 'Open /horizon as ops@ (no Horizon permission): refused; as admin@: opens.'],
                    ['branch', 'admin'],
                    ['PortalAccessTest', 'HostIsolationTest']),
                self::item('access.suspended', 'Suspended users', '/admin/users',
                    'A suspended user can’t log in, and is logged out at the next click if suspended while working.',
                    ['As admin, suspend a test user (with a reason).', 'In another browser that user is signed out at their next click and can’t log in again.', 'Reactivate the user: login works again.'],
                    ['admin'],
                    ['SuspendedUserTest']),
                self::item('access.profile', 'Profile, password & appearance', '/settings/profile',
                    'Everyone can change their name, password and appearance (light / dark); the e-mail is changed only by an admin.',
                    ['Change your name and save: it shows in the sidebar.', 'Change your password under Security (asks the current one).', 'Switch Appearance to dark and back.', 'The e-mail field can’t be edited.'],
                    ['admin', 'partner', 'branch'],
                    ['ProfileUpdateTest', 'SecurityTest']),
                self::item('access.audit_trail', 'Logs can’t be changed', null,
                    'Audit and security logs are append-only in the database: nobody can edit or delete them, and users with history can’t be deleted.',
                    ['Nothing to click: covered by the automated tests.'],
                    ['system'],
                    ['SecurityLogTest']),
                self::item('access.create_admin', 'First super admin (server command)', null,
                    'On a new server the first super admin is created with `php artisan paygate:create-admin`; it is audited.',
                    ['On staging only, when setting up: run the command and log in with the new account.'],
                    ['system'],
                    ['CreateAdminCommandTest']),
            ]),

            self::section('users', 'Roles & users', [
                self::item('users.roles', 'Roles & Permissions', '/admin/roles',
                    'Roles per portal with a menu × View / Insert / Update / Delete grid. The super admin role is locked and always holds everything.',
                    ['Open Roles, pick the Admin tab, click "New role", tick a few permissions and save.', 'Edit it: the change appears in Audit Logs with before → after.', 'Try to open the Super admin role: it is locked.', 'Delete your new role (only unused custom roles can be deleted).'],
                    ['admin'],
                    ['RoleManagementTest', 'RolePermissionTest']),
                self::item('users.admin_users', 'Users (Admin)', '/admin/users',
                    'All users of all portals (Active / Inactive). "Add admin user" adds admin users only; set a new password, change role or e-mail, deactivate, reset 2FA. The Admin role does everything except the super-admin tools and can’t manage super admins.',
                    ['Add an admin user with a password; log in as them in a private window.', 'The role list only offers admin roles.', 'Set password on a user: the old one stops working.', 'Change a user’s role: recorded in Audit Logs.', 'Deactivate with a reason, then activate.', 'Reset 2FA of a user: they must set it up again.', 'You can’t deactivate yourself or the last super admin.', 'As an Admin-role user: Testing and Developer are not in the menu, and Super admin is not in the role list.'],
                    ['admin'],
                    ['UserManagementTest']),
                self::item('users.organisation_users', 'Users of a partner / branch (Admin)', '/admin/partners',
                    'Each partner’s and branch’s users are added and managed from its own Users page.',
                    ['On Partners, click "Users" on a partner row (or in its drawer).', 'Only that partner’s users are listed; "Add user" offers partner roles only.', 'Add a developer with a password and log in as them.', 'Do the same from Branches.'],
                    ['admin'],
                    ['UserManagementTest::test_a_partner_users_page_lists_and_adds_only_that_partners_users', 'UserManagementTest::test_admin_adds_a_branch_user_on_the_branch_users_page_who_can_log_in_at_once']),
                self::item('users.owner_users', 'Users (partner / branch owners)', '/branch/users',
                    'Partner and branch owners manage the users of their own organisation only.',
                    ['As branch@, add an operator with a password: the organisation is fixed to your branch.', 'Only your branch’s users are listed.', 'Do the same as partner@ on /partner/users.', 'As operator@, Users is not in the menu.'],
                    ['branch', 'partner', 'operator'],
                    ['UserManagementTest::test_owners_add_users_to_their_own_organisation_only', 'UserManagementTest::test_owners_cannot_manage_users_of_another_organisation', 'UserManagementTest::test_admin_sees_all_users_and_owners_only_their_own_organisation']),
            ]),

            self::section('partners', 'Partners', [
                self::item('partners.list', 'Partner list & drawer', '/admin/partners',
                    'All partners with status; click a row for the drawer (keys, rates, branches, activity).',
                    ['Open Partners and click a partner: the drawer shows API keys, rates, mapped branches and activity.', 'Search by code or name.', 'Under the API key, Download partner file appears once the key, return URL, pay-in callback URL and pay-in webhook URL are set, plus the payout webhook URL when pay-out is on. The PDF does not contain the secret.', 'Click Enabled or Disabled on Pay-in or Pay-out: a dialog asks for a reason, then the list updates. The activity and Audit Logs show who did it.', 'As branch@ or partner@, /admin/partners is refused.'],
                    ['admin'],
                    ['PartnerManagementTest::test_partner_list_and_wizard_open_for_admins', 'PartnerManagementTest::test_the_drawer_detail_loads_on_demand', 'PartnerManagementTest::test_partners_and_branches_cannot_open_partner_management', 'PartnerManagementTest::test_payin_and_payout_toggles_from_the_list_are_audited']),
                self::item('partners.wizard', 'New partner wizard (7 steps)', '/admin/partners/create',
                    'Profile, API & security (allowed IPs), payment configuration, commission, withdrawal, branch mapping, review. The API key is shown once.',
                    ['Click "New partner" and fill each step; leave a required field empty to see the error on that step.', 'Enter 0.0.0.0/0 as allowed IP: refused.', 'Finish with "Create & activate": the key and secret show once; copy them.', 'Reload: the secret is never shown again.'],
                    ['admin'],
                    ['PartnerManagementTest::test_the_wizard_creates_a_draft_partner_with_everything_and_a_key_shown_once', 'PartnerManagementTest::test_invalid_input_is_rejected_per_field', 'PartnerManagementTest::test_whole_internet_ranges_are_refused', 'PartnerManagementTest::test_create_and_activate_goes_live_in_one_step_or_not_at_all', 'PartnerRulesTest']),
                self::item('partners.edit', 'Edit partner & commission rates', '/admin/partners/{id}/edit',
                    'Changes apply from now; old rates are kept as history. A rate below a mapped branch’s rate is allowed but flagged (platform would lose money).',
                    ['Edit a partner and change the deposit rate: the drawer shows the old rate with an end date.', 'Set it below a mapped branch’s rate: a warning shows before saving.', 'For a live partner, the code can’t be changed.', 'As finance@ (no partner update), the edit is refused.'],
                    ['admin', 'finance'],
                    ['PartnerManagementTest::test_editing_changes_rates_from_now_and_keeps_history', 'PartnerManagementTest::test_a_rate_below_a_mapped_branch_is_allowed_but_flagged', 'PartnerManagementTest::test_code_is_fixed_once_the_partner_is_live', 'PartnerManagementTest::test_admins_without_partner_update_permission_cannot_edit']),
                self::item('partners.status', 'Partner status', '/admin/partners',
                    'Draft → active → suspended / offboarded, always with a reason; unmapping a branch deactivates the pair instead of deleting it.',
                    ['Suspend an active partner with a reason: its API calls are refused.', 'Reactivate it.', 'Remove a branch in the partner’s mapping step: the pair shows as inactive.'],
                    ['admin'],
                    ['PartnerManagementTest::test_status_changes_follow_the_lifecycle_and_need_a_reason', 'PartnerManagementTest::test_unmapping_a_branch_deactivates_rather_than_deletes']),
                self::item('partners.keys_admin', 'API keys (Admin)', '/admin/partners',
                    'Generate (needs your password), rotate (old key keeps working for the overlap window) and revoke (password + reason) a partner’s keys.',
                    ['In the partner drawer, generate a key: your password is asked; the secret shows once, with Download partner file. That PDF contains the secret.', 'Download it again from the partner list: the secret is not in the file.', 'Generate another: the old one shows "Rotating out".', 'Revoke one with a reason: status Revoked, recorded in Audit Logs.'],
                    ['admin'],
                    ['ApiCredentialsTest::test_rotation_keeps_the_old_key_working_for_the_overlap_window', 'ApiCredentialsTest::test_generating_a_key_needs_the_persons_password', 'ApiCredentialsTest::test_rotating_a_key_is_audited', 'ApiCredentialsTest::test_revoking_needs_password_and_reason_and_is_audited', 'ApiCredentialsTest::test_a_key_of_another_partner_cannot_be_revoked_through_this_partner', 'PartnerIntegrationFileTest']),
            ]),

            self::section('branches', 'Branches, mapping & accounts', [
                self::item('branches.list', 'Branch list & form', '/admin/branches',
                    'Branches with limits (daily reset or top-up allowance), commission rates, mapped partners and the branch admin, who is invited on save.',
                    ['Click "New branch", fill limits and rates, map a partner, add the branch admin’s e-mail and password.', 'Save: the branch admin can log in at once.', 'Activate a branch without rates for an enabled direction: refused.', 'A branch rate above the partner’s rate is saved with a warning.'],
                    ['admin'],
                    ['BranchManagementTest::test_branch_list_and_form_open_for_admins_only', 'BranchManagementTest::test_creating_a_branch_saves_limits_rates_partners_and_its_admin', 'BranchManagementTest::test_activation_needs_rates_for_enabled_directions', 'BranchManagementTest::test_a_branch_rate_above_a_partner_rate_is_allowed_but_flagged']),
                self::item('branches.topups', 'Deposit allowance top-ups', '/admin/branches',
                    'Branches on "Deposit limit · top-up" receive deposits up to an allowance that Admin tops up (history kept, never below zero).',
                    ['Open a top-up branch’s drawer › Top-ups, add ₹10,000 with a note: the allowance grows and the history row shows who did it.', 'Try to remove more than is left: refused.', 'A daily-reset branch has no top-up button.'],
                    ['admin'],
                    ['BranchManagementTest::test_topups_change_the_allowance_with_history_and_never_go_negative', 'BranchManagementTest::test_daily_limit_branches_cannot_be_topped_up']),
                self::item('branches.status', 'Branch status', '/admin/branches',
                    'Activate, pause and suspend branches following the lifecycle.',
                    ['Pause an active branch: its accounts get no new pay-ins.', 'Activate it again.'],
                    ['admin'],
                    ['BranchManagementTest::test_status_changes_follow_the_lifecycle']),
                self::item('branches.mapping', 'Branch mapping (partner ↔ branch)', '/admin/mappings',
                    'A partner can be ticked onto many branches, and a branch onto many partners. Pair rates, direction switches and pair limits are edited on Branch mapping; the margin is shown.',
                    ['Open Partner → branches, pick a partner and tick several branches, then save.', 'Untick one: that pair stops, its history stays.', 'Open Branch → partners and tick several partners for one branch.', 'On Branch mapping, set a pair rate: it overrides the default; remove it again.', 'Set rates that lose money: saved with a warning, recorded in Audit Logs.', 'As ops@ without commission rights, pair rates can’t be changed.'],
                    ['admin', 'ops'],
                    ['MappingTest']),
                self::item('branches.accounts_branch', 'Bank & UPI accounts (branch)', '/branch/accounts',
                    'The branch adds its bank accounts / UPI IDs with limits; they wait for Admin verification. Numbers are stored encrypted and shown masked.',
                    ['As branch@, click "Add account", enter bank details and a UPI ID with limits.', 'It shows "Pending verification".', 'Add the same account number again (even from another branch): refused.', 'Limits above the branch limits are refused.', 'As operator@: you can see the list but not add.'],
                    ['branch', 'operator'],
                    ['PaymentAccountTest::test_a_branch_adds_an_account_stored_encrypted_and_waiting_for_verification', 'PaymentAccountTest::test_the_same_account_or_upi_id_cannot_be_registered_twice_even_by_another_branch', 'PaymentAccountTest::test_limits_must_sit_inside_the_branch_limits', 'PaymentAccountTest::test_operators_can_view_but_not_add_accounts', 'PaymentAccountTest::test_branches_only_see_and_change_their_own_accounts']),
                self::item('branches.accounts_admin', 'Account verification (Admin)', '/admin/accounts',
                    'Admin sees the full bank and UPI details, verifies or rejects new accounts, and can set any status from the list. A reason is required. A disabled account can be turned back on.',
                    ['As ops@, open Bank & UPI Accounts: each row shows the branch, limits, full account number, IFSC, UPI, intent and verification.', 'Click the status, pick Active, Paused, Disabled or another status, and give a reason.', 'Disable an account, then set it back to Active: it receives customers again.', 'Click "Review" on a pending account and verify it.', 'Reject another one with a reason; the branch edits and resubmits.'],
                    ['ops', 'branch'],
                    ['PaymentAccountTest::test_admin_verifies_then_the_branch_activates_and_pauses', 'PaymentAccountTest::test_changing_payment_details_needs_verification_again_but_limits_do_not', 'PaymentAccountTest::test_rejection_needs_a_reason_and_editing_resubmits', 'PaymentAccountTest::test_lists_show_masked_numbers_and_revealing_is_audited', 'PaymentAccountTest::test_an_admin_can_change_an_account_to_any_status_and_turn_a_disabled_one_back_on']),
                self::item('branches.accounts_log', 'Account log (Admin)', '/admin/accounts/logs',
                    'Every change to a branch account (added, edited, verified, rejected, activated, paused, disabled, full number viewed) with who did it, their role, the reason, IP and branch. Pausing and disabling need a reason.',
                    ['As branch@, pause an active account: a reason is asked for and the pause is refused without one.', 'As ops@, open Bank & UPI Accounts → "Account log": the pause shows Active → Paused, branch@ with username and role, the reason, IP and branch.', 'Filter by event, branch, dates and search by holder, person or IP; click an account to see only its history.', 'Click "Log" on an account row: the log opens filtered to that account.', 'Export CSV: the file has the same filtered rows with masked numbers.'],
                    ['branch', 'ops'],
                    ['AccountLogTest']),
            ]),

            self::section('partner_portal', 'Partner portal', [
                self::item('partner.developers', 'API & Webhooks', '/partner/developers',
                    'The partner manages its own keys, webhook URLs and allowed IPs; secrets are never shown again.',
                    ['As developer@, generate a key (password asked), copy the secret.', 'Set the pay-in and payout webhook URLs and save.', 'Add an allowed IP (a whole-internet range is refused).', 'As a partner viewer, the page is not available.'],
                    ['developer', 'partner'],
                    ['ApiCredentialsTest::test_partner_developers_manage_their_own_keys_and_never_see_secrets', 'ApiCredentialsTest::test_partners_cannot_touch_another_partners_keys', 'ApiCredentialsTest::test_partner_viewers_cannot_open_the_developer_page', 'ApiCredentialsTest::test_partners_update_their_endpoints_and_ips']),
                self::item('partner.docs', 'API documentation & API Logs', '/partner/api-docs',
                    'How to sign requests, every endpoint, statuses and webhooks; API Logs lists the partner’s own calls (no bodies stored).',
                    ['Open API documentation: your key id is filled into the examples.', 'Make a few API calls (see "Partner API" below), then open API Logs: each call is listed with status and time.'],
                    ['developer'],
                    ['PartnerApiPagesTest']),
                self::item('partner.profile', 'Business profile', '/partner/profile',
                    'The partner’s details; its commission rates are visible only to finance roles.',
                    ['As partner@ (owner), rates are shown.', 'As developer@, rates are hidden.'],
                    ['partner', 'developer'],
                    ['ApiCredentialsTest::test_business_profile_shows_own_rates_only_to_finance_roles']),
                self::item('partner.transactions', 'Pay-in & Pay-out lists', '/partner/payins',
                    'The partner’s own pay-ins and payouts with status, timeline and webhooks (resend); never the branch or bank details.',
                    ['Open Pay-in: only this partner’s pay-ins are listed.', 'Open one: timeline and webhooks, no proof and no bank line.', 'Resend a webhook from the drawer.', 'Open Pay-out: the payouts list.'],
                    ['partner'],
                    ['TransactionScreensTest::test_each_portal_sees_only_what_it_may', 'WebhookDeliveryTest::test_partners_and_admin_can_resend_but_not_other_partners']),
                self::item('partner.accounts', 'Bank & UPI Accounts (partner)', '/partner/accounts',
                    'The bank accounts and UPI IDs of the branches mapped to this partner, read-only and masked; nothing about the branch itself is shown.',
                    ['As partner@, open Bank & UPI Accounts: accounts of mapped branches are listed with bank, masked number, IFSC, UPI, limits, methods and status.', 'No branch name, code or account label appears anywhere on the page.', 'Unmap a branch in Branch mapping: its accounts disappear.', 'As developer@ (no account permission), the menu item is missing and the page is refused.', 'With Section rollout on, the page shows for partners only when "Bank & UPI Accounts" is opened under Partner.'],
                    ['partner', 'developer'],
                    ['PartnerAccountsTest']),
            ]),

            self::section('api', 'Partner API', [
                self::item('api.ping', 'Health check', 'api:/v1/ping',
                    'Public: tells the partner the API is up.',
                    ['Open the URL in a browser: {"status":"ok", …}.'],
                    ['guest'],
                    ['PartnerApiTest::test_ping_is_public']),
                self::item('api.security', 'Signing, IP allow-list, rate limit', 'api:/v1/payins',
                    'Every call is signed (key id, timestamp, nonce, HMAC signature), must come from an allowed IP, is rate-limited per partner and logged.',
                    ['Send a call without signature: 401 with a stable error code.', 'Repeat the same nonce: refused.', 'Call from an IP not on the list: 403, visible in IP Management as "refused".', 'Use a revoked key: refused.'],
                    ['api'],
                    ['PartnerApiTest::test_unsigned_or_badly_signed_requests_are_refused_with_a_stable_error_shape', 'PartnerApiTest::test_old_timestamps_and_reused_nonces_are_refused', 'PartnerApiTest::test_a_revoked_key_stops_working', 'PartnerApiTest::test_calls_from_unlisted_ips_are_refused_and_an_empty_list_blocks_everything', 'PartnerApiTest::test_inactive_partners_and_disabled_payins_are_refused', 'PartnerApiTest::test_partners_are_rate_limited', 'PartnerApiTest::test_every_call_is_logged_without_bodies']),
                self::item('api.payin_create', 'Create a pay-in', 'api:/v1/payins',
                    'Returns the payment link for the customer; the same order sent again returns the same pay-in (safe retries).',
                    ['Use "Create test pay-in" at the top of this page, or a signed POST as in the API documentation.', 'Send the same order id again: the same pay-in comes back.', 'Send it with a different amount: 409.', 'Missing fields list each field; a return URL on another domain is refused.'],
                    ['api'],
                    ['PartnerApiTest::test_creating_a_payin_returns_a_payment_link_and_repeats_are_safe', 'PartnerApiTest::test_invalid_requests_list_the_fields', 'PartnerApiTest::test_return_urls_must_be_on_the_partners_domain']),
                self::item('api.payin_status', 'Pay-in status, lookup & cancel', 'api:/v1/payins/{reference}',
                    'Find a pay-in by our reference or the partner’s order id, many statuses at once, cancel while open.',
                    ['GET by reference and by ?order_id=: same pay-in.', 'Another partner’s reference: 404.', 'POST /v1/payins/status with several ids.', 'Cancel an open pay-in; cancelling again is refused.'],
                    ['api'],
                    ['PartnerApiTest::test_payins_are_found_by_id_or_order_id_and_only_by_their_partner', 'PartnerApiTest::test_many_statuses_at_once', 'PartnerApiTest::test_open_payins_can_be_cancelled_once']),
                self::item('api.payout_create', 'Create a payout & balance', 'api:/v1/payouts',
                    'The partner asks us to pay its customer; a branch holding enough of the partner’s balance for amount + fee is chosen, otherwise "balance is low". GET /v1/balance shows what can be paid out.',
                    ['Use "Create test payout" at the top of this page (the partner needs approved deposits first).', 'Ask for more than the balance: "Balance is low".', 'Same order id again: same payout; changed details: 409.', 'Cancel it before the branch starts: allowed; after: refused.'],
                    ['api'],
                    ['PayoutTest::test_a_payout_holds_amount_plus_fee_and_goes_to_the_branch_queue', 'PayoutTest::test_balance_is_low_when_no_branch_can_cover_amount_plus_fee', 'PayoutTest::test_the_branch_holding_enough_of_the_partners_balance_is_chosen', 'PayoutTest::test_repeats_are_safe_and_changed_details_are_refused', 'PayoutTest::test_partners_cancel_only_before_the_branch_starts', 'PayoutTest::test_payouts_need_them_enabled_and_respect_limits']),
            ]),

            self::section('checkout', 'Customer payment page', [
                self::item('checkout.page', 'Payment page', 'pay:/p/{token}',
                    'The customer sees the amount, the time left and the methods the partner allows (UPI, QR, bank transfer). Unknown links show "not found".',
                    ['Create a test pay-in (top of this page) and open its link on a phone-sized window.', 'Change one letter of the link: not found.', 'Reload several times: still works (views don’t use up the submit limit).'],
                    ['customer'],
                    ['CheckoutTest::test_the_page_shows_the_amount_and_methods_and_unknown_tokens_404', 'CheckoutTest::test_page_views_and_method_changes_do_not_use_up_the_submit_limit']),
                self::item('checkout.allocation', 'Account allocation (round robin)', 'pay:/p/{token}',
                    'Choosing a method picks the next account in turn among active, verified accounts of mapped branches, within account, branch and partner limits.',
                    ['Create two pay-ins and choose UPI on both: different accounts are shown if two are active.', 'Switch method: a suitable account is chosen and the first is released.', 'Pause all accounts: the page says payment is unavailable.'],
                    ['customer', 'branch'],
                    ['CheckoutTest::test_choosing_a_method_allocates_an_account_and_reserves_its_capacity', 'CheckoutTest::test_accounts_are_used_in_turn', 'CheckoutTest::test_full_accounts_are_skipped_and_nothing_left_means_unavailable', 'CheckoutTest::test_only_mapped_active_branches_and_accounts_that_support_the_method_are_used', 'CheckoutTest::test_branch_and_partner_daily_limits_apply', 'CheckoutTest::test_switching_to_a_method_the_account_lacks_moves_to_another_account_and_releases_the_first']),
                self::item('checkout.submit', 'Submit UTR or screenshot', 'pay:/p/{token}',
                    'After paying, the customer enters the 12-digit UTR or uploads a screenshot; the pay-in goes to the branch as "Pending". A UTR used before is accepted but flagged for the branch.',
                    ['Enter a 12-digit UTR and submit: the page shows "pending".', 'On another pay-in, upload a screenshot instead.', 'Reuse the first UTR on a third pay-in: accepted, flagged in the branch queue.'],
                    ['customer'],
                    ['CheckoutTest::test_the_customer_submits_a_utr_or_a_screenshot', 'CheckoutTest::test_a_reused_utr_is_accepted_but_flagged_for_the_branch', 'CheckoutTest::test_a_customer_who_submitted_proof_frees_their_account_slot']),
                self::item('checkout.expiry', 'Expiry & cancel', 'pay:/p/{token}',
                    'Unpaid pay-ins expire at the partner’s time limit and free the account; submitted ones never expire.',
                    ['Leave a pay-in open past its time: the page shows "expired".', 'A submitted pay-in stays pending.', 'Cancel through the API: the page shows cancelled.'],
                    ['customer'],
                    ['CheckoutTest::test_expiry_releases_capacity_and_the_page_expires_on_the_spot', 'CheckoutTest::test_submitted_payins_do_not_expire', 'CheckoutTest::test_cancelling_releases_the_reservation']),
                self::item('checkout.support', 'Support contact & legal pages', 'pay:/legal/{slug}',
                    'The payment page footer shows the support e-mail / phone from Global Settings and links to published content pages.',
                    ['Set the support contact in Global Settings and publish a page in Content pages.', 'Open a payment link: the footer shows the contact and the page link; the page opens without login.'],
                    ['customer', 'admin'],
                    ['PlatformAdministrationTest::test_global_settings_hold_alerts_the_payment_page_contact_and_reasons', 'PlatformAdministrationTest::test_published_pages_are_public_and_never_run_html']),
            ]),

            self::section('deposits', 'Deposits (approval), transactions & webhooks', [
                self::item('deposits.queue', 'Manual Deposit queue', '/branch/deposits',
                    'Pay-ins submitted to this branch’s accounts, as cards or a list, with the customer’s UTR / screenshot. Admin sees every branch at /admin/deposits.',
                    ['Submit a UTR on a test pay-in, then as operator@ open Manual Deposit: it is there.', 'Switch between cards and list.', 'A pay-in paid into another branch is not shown.', 'As admin, /admin/deposits shows all branches.'],
                    ['operator', 'admin'],
                    ['TransactionScreensTest::test_the_queue_shows_submitted_pay_ins_to_their_branch_only']),
                self::item('deposits.approve', 'Approve a deposit', '/branch/deposits',
                    'Approving with the bank UTR books commissions (rounded to the paisa) and the ledger in one step, only once; pair rates override defaults; a top-up branch’s allowance shrinks.',
                    ['Click Approve, enter the bank UTR (12 digits) and confirm: status Success.', 'Open the transaction drawer › Ledger: partner, branch and margin lines add up to zero.', 'Approve another with the same bank UTR on the same account: refused.', 'A pair without rates can’t be approved.'],
                    ['operator'],
                    ['ApprovalTest::test_approving_books_commission_and_the_ledger_in_one_step', 'ApprovalTest::test_commissions_round_half_up_to_the_paisa', 'ApprovalTest::test_pair_rates_override_the_defaults_and_a_negative_margin_is_booked_as_such', 'ApprovalTest::test_approval_needs_a_valid_bank_utr_that_is_not_already_used_on_the_account', 'ApprovalTest::test_without_rates_nothing_is_approved', 'ApprovalTest::test_a_pay_in_is_booked_only_once', 'ApprovalTest::test_a_topup_branch_allowance_shrinks_by_what_it_received']),
                self::item('deposits.hold_decline', 'Hold & decline', '/branch/deposits',
                    'Hold puts a deposit on "Payment hold"; decline needs a reason and frees the account; only the receiving branch or Admin may decide.',
                    ['Hold a pending deposit: status Payment hold.', 'Decline it with a reason: status Declined, the partner gets a webhook.', 'Log in as another branch: you can’t act on it.'],
                    ['operator'],
                    ['ApprovalTest::test_hold_then_decline_releases_the_reservation_and_notifies_the_partner', 'ApprovalTest::test_only_the_receiving_branch_or_admin_may_decide']),
                self::item('deposits.transactions', 'Transactions & drawer', '/admin/transactions',
                    'All pay-ins (and payouts at /admin/transactions/payouts) with search by reference, order id or UTR; the drawer shows timeline, proof, ledger, webhooks, bank line and audit. Branches see /branch/payins.',
                    ['Search by a UTR: the pay-in is found.', 'Open the drawer and go through each tab.', 'The screenshot proof opens for admin and the receiving branch only.'],
                    ['admin', 'branch'],
                    ['TransactionScreensTest::test_each_portal_sees_only_what_it_may', 'TransactionScreensTest::test_search_finds_by_reference_order_id_and_utr', 'TransactionScreensTest::test_payment_proofs_are_shown_only_to_admin_and_the_receiving_branch']),
                self::item('deposits.webhooks', 'Webhooks to partners', '/admin/transactions',
                    'Signed webhooks for submitted / approved / declined pay-ins and payouts, retried with growing waits, then marked failed; Admin and the partner can resend. Private network URLs are refused on servers.',
                    ['Set a webhook URL on the partner (e.g. a webhook.site address).', 'Approve a deposit: the drawer › Webhooks shows the delivery; the receiver gets a signed request.', 'Use a URL that fails: attempts are retried, then "failed" and an alert appears in the bell.', 'Click Resend.'],
                    ['admin', 'partner'],
                    ['WebhookDeliveryTest', 'ApprovalTest::test_partners_get_webhooks_for_submitted_and_decided_pay_ins_only_with_a_url']),
            ]),

            self::section('payouts', 'Payouts', [
                self::item('payouts.queue', 'Manual Payout (branch)', '/branch/payouts',
                    'Payouts assigned to the branch: start, mark paid with the bank UTR (books the ledger) or "can’t pay" with a reason (releases the hold; the partner may retry).',
                    ['Create a test payout (top of this page); as operator@ it appears in Manual Payout.', 'Click Start, then Paid with a UTR: status Success, drawer › Ledger shows the booking.', 'On another payout, "Can’t pay" with a reason: status Failed.', 'A started payout stays in the "To pay" list.'],
                    ['operator'],
                    ['PayoutTest::test_paying_books_the_ledger_and_releases_the_hold', 'PayoutTest::test_a_failed_payout_releases_the_hold_and_the_partner_can_retry', 'PayoutTest::test_a_started_payout_stays_in_the_to_pay_list', 'PayoutTest::test_only_the_assigned_branch_or_admin_can_process_and_sees_the_full_details']),
                self::item('payouts.admin', 'Manual Payout (Admin) & reassign', '/admin/payouts',
                    'Admin sees every branch’s payouts and can move a waiting payout (with its hold) to another branch.',
                    ['Open Manual Payout as admin, pick a waiting payout and Reassign it to another mapped branch.', 'That branch now sees it; the first no longer does.'],
                    ['admin'],
                    ['PayoutTest::test_admin_can_move_a_waiting_payout_and_its_hold_to_another_branch']),
                self::item('payouts.history', 'Payout histories & partner balance', '/partner/balance',
                    'Payout history for Admin (/admin/transactions/payouts), branch (/branch/payout-history) and partner (/partner/payouts); the partner’s Balance page shows what can be paid out per branch.',
                    ['As partner@, open Balance: available amount per branch.', 'Open each payout history and check the payout is listed.'],
                    ['partner', 'branch', 'admin'],
                    ['PayoutTest::test_balance_is_low_when_no_branch_can_cover_amount_plus_fee']),
            ]),

            self::section('reconciliation', 'Statements & reconciliation', [
                self::item('recon.manual_lines', 'A/C Statement Entry (typed lines)', '/branch/statements',
                    'The branch types bank statement lines; a credit with the deposit’s UTR + amount + account is linked (the branch still approves); an approved deposit is reconciled when its line arrives.',
                    ['Submit a UTR on a test pay-in, then add a statement line with the same UTR, amount and account: the deposit shows the bank line.', 'Approve it: the line shows Reconciled.', 'Add the same line twice: refused.', 'Approve with another UTR than the linked line: the line goes to review.'],
                    ['branch', 'operator'],
                    ['StatementMatchingTest::test_a_credit_with_the_deposits_utr_and_amount_is_linked_and_the_branch_still_approves', 'StatementMatchingTest::test_approving_with_another_utr_than_the_linked_line_sends_the_line_to_review', 'StatementMatchingTest::test_an_approved_deposit_is_reconciled_when_its_bank_line_arrives', 'StatementMatchingTest::test_a_line_that_arrives_before_the_customers_claim_links_itself_later', 'StatementMatchingTest::test_the_same_line_twice_is_refused_and_the_same_utr_on_another_line_is_a_duplicate_case']),
                self::item('recon.import', 'Import a bank statement (CSV / Excel)', '/branch/statements',
                    'Upload any bank CSV or Excel file; columns are guessed, you confirm the mapping (remembered per bank layout), see a preview and import. Duplicates are skipped.',
                    ['Click "Import", choose a bank statement file and the account.', 'Check the guessed columns, fix any, look at the preview and import.', 'Import the same file again: all rows skipped as duplicates.', 'Upload a non-statement file: a clear error.'],
                    ['branch'],
                    ['StatementImportTest::test_a_bank_csv_is_read_with_guessed_columns_matched_and_duplicates_skipped', 'StatementImportTest::test_an_excel_file_with_one_amount_column_and_cr_dr', 'StatementImportTest::test_unreadable_files_and_bad_mappings_are_explained', 'StatementParserTest']),
                self::item('recon.history', 'Statement History', '/branch/statements/imports',
                    'Every import of the branch with counts; the original file can be downloaded.',
                    ['Open Statement History after an import: it is listed with rows / matched / skipped.', 'Download the file.'],
                    ['branch'],
                    ['StatementImportTest::test_statement_history_lists_imports_of_the_branch_and_the_file_downloads']),
                self::item('recon.cases', 'Deposit Unsettled / Unsettled UTR cases', '/branch/unsettled',
                    'Lines that don’t match exactly become cases (amount mismatch, wrong branch, duplicate, no UTR…). They are linked by hand (same amount only) or closed with a reason. Admin sees all at /admin/unsettled.',
                    ['Add a line with a pending deposit’s UTR but a different amount: an "Amount mismatch" case opens.', 'Add a line without UTR, then link it by hand to a deposit of the same amount.', 'Close a case as "Not a customer payment".', 'Branches only see their own cases.'],
                    ['branch', 'admin'],
                    ['StatementMatchingTest::test_a_different_amount_opens_an_amount_mismatch_case', 'StatementMatchingTest::test_a_utr_of_another_branchs_deposit_is_a_wrong_branch_case', 'StatementMatchingTest::test_a_line_without_utr_is_linked_by_hand_only_to_the_same_amount', 'StatementMatchingTest::test_a_case_can_be_closed_as_not_a_customer_payment_or_returned_money', 'StatementMatchingTest::test_branches_see_only_their_own_lines_and_cases']),
                self::item('recon.late', 'Late payment (Admin approves late)', '/admin/unsettled',
                    'If a declined or expired deposit’s money shows up in the statement, a "Late payment" case opens and only Admin may approve it late (partner gets payin.success with late: true).',
                    ['Decline a deposit, then add its credit line: a Late payment case opens and an alert appears.', 'As admin, approve late from the case.'],
                    ['admin', 'branch'],
                    ['StatementMatchingTest::test_declining_a_deposit_whose_credit_was_found_opens_a_late_payment_case_admin_can_approve']),
                self::item('recon.utr', 'UTR Reconciliation & drawer Bank tab', '/admin/utr-reconciliation',
                    'The transaction-side view: each deposit / payout with its bank line and match result; payouts are reconciled with debit lines. Partners never see bank lines.',
                    ['Open UTR Reconciliation and filter by "Not matched".', 'Add a debit line with a paid payout’s UTR: it becomes Reconciled.', 'In the transaction drawer › Bank, compare the two sides.'],
                    ['admin', 'branch'],
                    ['StatementMatchingTest::test_a_debit_is_reconciled_with_the_payout_paid_with_its_utr', 'StatementMatchingTest::test_the_transaction_drawer_compares_the_bank_line_but_partners_never_see_it']),
            ]),

            self::section('finance', 'Settlement & finance', [
                self::item('finance.settlements', 'Settlement', '/admin/settlements',
                    'Per-party settlements with per-pair lines (what the platform owes / is owed), calculated daily at the cut-off or on demand.',
                    ['Approve a few deposits, then click "Calculate now": each partner and branch gets a settlement with lines per pair.', 'Open one: the breakdown adds up.'],
                    ['finance', 'admin'],
                    ['SettlementTest::test_a_settlement_shows_each_partys_position_with_the_breakdown', 'SettlementTest::test_the_daily_run_follows_the_cut_off_set_in_global_settings']),
                self::item('finance.payments', 'Record settlement payments', '/admin/settlements',
                    'Record money actually paid per pair (partial allowed); what is left carries into the next settlement. A partner can’t be paid out what running payouts still hold.',
                    ['Record a partial payment on a line: it shows as partly paid.', 'Calculate again: the rest carries forward.', 'Try to pay a partner more than is free: refused.'],
                    ['finance'],
                    ['SettlementTest::test_a_partial_payment_carries_forward_into_the_next_settlement', 'SettlementTest::test_paying_a_partner_out_leaves_what_payouts_in_progress_hold']),
                self::item('finance.adjustments', 'Adjustments (maker–checker)', '/admin/settlements/adjustments',
                    'Top-up, correction or goodwill: one admin requests with a reason, a different admin approves or rejects. A correction can close an unsettled case.',
                    ['As finance@, request a correction with a reason.', 'Try to approve it yourself: refused.', 'As admin@, approve it: the position changes.', 'Request another and reject it with a note.'],
                    ['finance', 'admin'],
                    ['SettlementTest::test_adjustments_need_a_second_admin_and_topups_fund_partner_payouts', 'SettlementTest::test_a_correction_can_resolve_an_unsettled_case_and_rejections_keep_the_note']),
                self::item('finance.commissions', 'Commissions', '/admin/commissions',
                    'Commissions earned per partner / branch / pair from the amounts fixed at approval.',
                    ['Open Commissions for today: totals match the approved deposits’ ledger lines.'],
                    ['finance'],
                    ['SettlementTest::test_the_commissions_page_adds_up_the_snapshotted_commissions']),
                self::item('finance.party_views', 'Branch Balance & partner / branch settlements', '/branch/balance',
                    'Branches and partners see only their own settlements and position.',
                    ['As branch@, open Branch Balance and Settlement.', 'As partner@, open Settlements and Balance.', 'As operator@, Branch Balance is not available.'],
                    ['branch', 'partner', 'operator'],
                    ['SettlementTest::test_partners_and_branches_see_only_their_own_settlements_and_side']),
                self::item('finance.reversals', 'Refunds & Chargebacks', '/admin/chargebacks',
                    'Admin records a chargeback (choosing who bears it), a pay-in refund or a returned payout; the ledger is reversed, the partner gets a webhook; once per transaction.',
                    ['Open Chargebacks, find an approved deposit by reference, choose "Partner bears it", add a reason and record.', 'The drawer shows status Chargeback and the reversal in Ledger.', 'Record the same one again: refused.', 'On Refunds, record a returned payout.'],
                    ['ops', 'finance'],
                    ['ReversalsAndAlertsTest::test_a_chargeback_borne_by_the_partner_moves_the_amount_and_keeps_the_fees', 'ReversalsAndAlertsTest::test_a_chargeback_borne_by_the_branch_books_nothing', 'ReversalsAndAlertsTest::test_a_refund_debits_the_partner_and_a_returned_payout_is_fully_reversed', 'ReversalsAndAlertsTest::test_only_admins_with_the_permission_record_reversals']),
            ]),

            self::section('reporting', 'Dashboards & reports', [
                self::item('reporting.admin_dashboard', 'Admin dashboard', '/admin',
                    'Live KPIs, pay-in vs payout chart, outcome and "needs attention" for a chosen period; refreshes every 30 seconds.',
                    ['Switch between Today, 7 days, This month and a custom range.', 'Approve a deposit and wait 30 seconds: the figures move.'],
                    ['admin'],
                    ['ReportingTest::test_the_admin_dashboard_shows_live_figures_for_the_period', 'ReportingTest::test_periods_follow_business_days']),
                self::item('reporting.party_dashboards', 'Branch & partner dashboards', '/branch',
                    'The same dashboard showing only the branch’s or partner’s own side.',
                    ['As branch@ and partner@, open the dashboard: only your own figures.'],
                    ['branch', 'partner'],
                    ['ReportingTest::test_partners_and_branches_see_their_own_side_only']),
                self::item('reporting.reports', 'Reports & exports', '/admin/reports',
                    'Eight reports with columns per portal; CSV / Excel exports are prepared in the background, downloadable only by who asked, deleted after 7 days.',
                    ['Pick a report and period: the preview shows.', 'Click Export › Excel: the file is ready after a moment; download it.', 'As branch@, the same report hides partner columns.'],
                    ['admin', 'branch', 'partner'],
                    ['ReportingTest::test_reports_show_each_portal_its_own_columns_and_rows', 'ReportingTest::test_exports_are_prepared_downloaded_by_their_owner_only_and_deleted_after_seven_days']),
            ]),

            self::section('platform', 'Platform administration', [
                self::item('platform.settings', 'Global Settings', '/admin/settings',
                    'Settlement cut-off (timezone + time), deposit-waiting alert, payment-page support contact, reasons for declining / failing.',
                    ['Change the alert to 45 minutes and save.', 'Enter 3 minutes: refused (5 to 1,440).', 'Add a reason, rename one, switch one off; "Other" can’t be switched off.', 'As a viewer admin, the page is read-only.'],
                    ['admin'],
                    ['PlatformAdministrationTest::test_global_settings_hold_alerts_the_payment_page_contact_and_reasons']),
                self::item('platform.pages', 'Content pages', '/admin/settings/pages',
                    'Terms, privacy, help… written in Markdown; published pages are public at /legal/{slug}; HTML is never run.',
                    ['Create a page with a heading, publish it, open /legal/{slug} logged out.', 'A draft page is not found.', 'Put <script>alert(1)</script> in the body: nothing runs.'],
                    ['admin', 'guest'],
                    ['PlatformAdministrationTest::test_published_pages_are_public_and_never_run_html']),
                self::item('platform.ip', 'IP Management', '/admin/ip-management',
                    'Every partner’s allowed IPs and which addresses called in the last 7 days (refused calls highlighted).',
                    ['Open IP Management: each partner with its allow-list and callers.'],
                    ['admin'],
                    ['PlatformAdministrationTest::test_ip_management_lists_every_partners_allow_list']),
                self::item('platform.audit', 'Audit Logs', '/admin/audit-logs',
                    'Every recorded action with before → after, IP and request id; the Security tab (Admin only) shows logins and refusals. Branches see their own users’ actions at /branch/audit-logs.',
                    ['Change a setting, then find it in Audit Logs with before → after.', 'Open the Security tab.', 'As branch@, only your branch’s actions are listed.'],
                    ['admin', 'branch'],
                    ['PlatformAdministrationTest::test_audit_logs_admin_sees_all_and_a_branch_only_its_users']),
                self::item('platform.alerts', 'Alerts (bell & e-mail)', '/settings/notifications',
                    'The bell shows alerts for the people who can act (deposits waiting, payouts to pay, failed webhooks, adjustments…); each person chooses which also come by e-mail.',
                    ['Let a submitted deposit wait past the alert time: branch users get a bell alert and an e-mail (Mailpit).', 'Click the bell: open an alert, then "Mark all read".', 'In Profile & settings › Notifications, untick an e-mail: next time only the bell.'],
                    ['branch', 'admin'],
                    ['ReversalsAndAlertsTest::test_alerts_reach_the_people_who_can_act_by_bell_and_email', 'ReversalsAndAlertsTest::test_the_bell_shows_unread_alerts_and_preferences_are_saved']),
                self::item('platform.rollout', 'Section rollout', '/admin/section-rollout',
                    'Super admins open sections one at a time for the client: while rollout is on, everyone else sees only the open menu items; closed pages send them back to the dashboard.',
                    ['As admin@, switch rollout on and open only Partners.', 'Log in as ops@ in another browser: the menu shows Dashboard, Partners and Profile only; /admin/branches sends you back to the dashboard.', 'As branch@ and partner@: only the sections opened on their portal tabs.', 'Switch rollout off: everyone sees everything again.', 'As ops@, /admin/section-rollout and /admin/qa-checklist are refused.'],
                    ['admin', 'ops', 'branch', 'partner'],
                    ['SectionRolloutTest']),
                self::item('platform.qa', 'This QA Checklist', '/admin/qa-checklist',
                    'This page (super admins only): mark results, re-run automated tests, create test pay-ins / payouts. Local and staging only.',
                    ['Mark a row Pass with a note: your name and the time show.', 'Click Re-run on a row: Queued → Running → Passed within a minute (Horizon must be running).'],
                    ['admin'],
                    ['QaChecklistTest']),
            ]),

            self::section('safety', 'Data safety & code rules (automated only)', [
                self::item('safety.database', 'Database safety rules', null,
                    'Rules enforced by the database itself: balanced ledger journals, append-only ledger and events, one booking per transaction, unique UTRs per account, no negative reservations, valid statuses.',
                    ['Nothing to click: run the automated tests.'],
                    ['system'],
                    ['SchemaConstraintsTest']),
                self::item('safety.architecture', 'Code structure rules', null,
                    'Only the Ledger module books money; domain code doesn’t depend on screens; screens don’t query the database directly.',
                    ['Nothing to click: run the automated tests.'],
                    ['system'],
                    ['ArchitectureTest']),
            ]),
        ];
    }

    /**
     * @return array<string, array{key: string, name: string, url: string|null, description: string, steps: list<string>, login: list<string>, tests: list<string>}>
     */
    public static function items(): array
    {
        $items = [];

        foreach (self::sections() as $section) {
            foreach ($section['items'] as $item) {
                $items[$item['key']] = $item;
            }
        }

        return $items;
    }

    /**
     * The full address to open: "api:" and "pay:" paths live on the API and
     * payment-page hosts, everything else on the portal host.
     */
    public static function address(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        [$host, $path] = str_contains($url, ':/') ? explode(':', $url, 2) : ['app', $url];

        return Hosts::url($host, $path);
    }

    /**
     * @param  list<array{key: string, name: string, url: string|null, description: string, steps: list<string>, login: list<string>, tests: list<string>}>  $items
     * @return array{key: string, title: string, items: list<array{key: string, name: string, url: string|null, description: string, steps: list<string>, login: list<string>, tests: list<string>}>}
     */
    private static function section(string $key, string $title, array $items): array
    {
        return ['key' => $key, 'title' => $title, 'items' => $items];
    }

    /**
     * @param  list<string>  $steps
     * @param  list<string>  $login
     * @param  list<string>  $tests
     * @return array{key: string, name: string, url: string|null, description: string, steps: list<string>, login: list<string>, tests: list<string>}
     */
    private static function item(string $key, string $name, ?string $url, string $description, array $steps, array $login, array $tests): array
    {
        return compact('key', 'name', 'url', 'description', 'steps', 'login', 'tests');
    }
}
