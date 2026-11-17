<?php

namespace App\Domain\SecurityReport;

/**
 * The fixed content of the security report (Admin › Testing › Security
 * Report): what the code review verified, the findings, the decisions the
 * client has to make and the manual tests. The super admin's statuses and
 * notes are stored per key in security_report_entries; automated results in
 * security_scans. Keep in step with Document/Security-Testing.md.
 */
final class Catalogue
{
    public const TEST_STATUSES = ['pass', 'fail', 'na'];

    public const FINDING_STATUSES = ['open', 'fixed', 'verified', 'accepted'];

    public const DECISION_STATUSES = ['pending', 'agreed', 'declined'];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    /**
     * Areas the code review checked and found sound.
     *
     * @return list<array{area: string, result: string}>
     */
    public static function verified(): array
    {
        return [
            ['area' => 'Partner API', 'result' => 'Every request is signed (HMAC-SHA256) and compared in constant time; requests older than 5 minutes and replays are refused; only the partner’s registered IP addresses are allowed; per-partner rate limit.'],
            ['area' => 'Data separation', 'result' => 'A partner sees only its own transactions, users and webhooks; a branch sees and acts only on its own accounts, deposits and payouts. Checked on every page and API call.'],
            ['area' => 'Money safety', 'result' => 'Approvals, payouts and ledger postings lock the records involved: the same deposit can’t be approved twice and payouts can’t exceed the balance. A bank UTR can be used only once (enforced by the database).'],
            ['area' => 'Payment page', 'result' => 'Links are unguessable and only a fingerprint of each is stored; customers never see branch or commission details; rate-limited.'],
            ['area' => 'File uploads', 'result' => 'Type and size limits; files are stored privately under random names and shown only to authorised users, never cached.'],
            ['area' => 'Injection', 'result' => 'Protected against SQL injection, cross-site scripting (including content pages) and spreadsheet-formula injection in exports.'],
            ['area' => 'Redirects', 'result' => 'After payment, customers can only be sent back to the partner’s own website.'],
            ['area' => 'Webhooks', 'result' => 'Only https, never to private or internal addresses, no redirects followed, signed so partners can verify them.'],
            ['area' => 'Sensitive data', 'result' => 'API secrets and bank account / UPI numbers are encrypted in the database; API keys are shown once only.'],
            ['area' => 'Access', 'result' => 'Two-factor authentication mandatory for Admin and Branch users; login attempts rate-limited; deactivated users signed out at once; each user only reaches their own portal.'],
            ['area' => 'Audit trail', 'result' => 'Audit and security logs can’t be edited or deleted, even by the application (database trigger).'],
        ];
    }

    /**
     * Findings, with the status they start with until the super admin changes it.
     *
     * @return list<array{key: string, id: string, title: string, detail: string, severity: string, fix: string, default: string}>
     */
    public static function findings(): array
    {
        return [
            ['key' => 'finding.headers', 'id' => 'F-1', 'severity' => 'medium', 'default' => 'fixed',
                'title' => 'Browser security headers missing',
                'detail' => 'Pages did not tell browsers to always use HTTPS (HSTS), to refuse being embedded in other sites (clickjacking), or to restrict content types and referrer data.',
                'fix' => 'Security headers on every response: HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy and a Content-Security-Policy.'],
            ['key' => 'finding.api_lockout', 'id' => 'F-2', 'severity' => 'low', 'default' => 'fixed',
                'title' => 'No lockout after repeated failed API logins',
                'detail' => 'Every attempt was refused and a signature can’t realistically be guessed, but unlimited attempts could be used to probe the API or load the server.',
                'fix' => 'After 30 failures in one minute, that address is blocked for the rest of the minute.'],
            ['key' => 'finding.cors', 'id' => 'F-3', 'severity' => 'low', 'default' => 'fixed',
                'title' => 'API allowed cross-site browser access (CORS *)',
                'detail' => 'No login data could leak (the API uses signed requests, not cookies), but the API is meant only for partners’ servers.',
                'fix' => 'Cross-site browser access turned off.'],
            ['key' => 'finding.certificate', 'id' => 'F-4', 'severity' => 'low', 'default' => 'open',
                'title' => 'SSL certificate renewal',
                'detail' => 'The certificate must renew automatically before it expires; the automated scan shows the current expiry date.',
                'fix' => 'Confirm automatic renewal in the hosting panel.'],
            ['key' => 'finding.files_2fa', 'id' => 'F-5', 'severity' => 'low', 'default' => 'fixed',
                'title' => 'Payment proofs reachable before two-factor set-up',
                'detail' => 'Admin and branch users who had not yet set up two-factor authentication could open payment-proof files.',
                'fix' => 'Files need two-factor authentication, like the portals.'],
            ['key' => 'finding.webhook_dns', 'id' => 'F-6', 'severity' => 'low', 'default' => 'fixed',
                'title' => 'Webhook DNS rebinding',
                'detail' => 'Webhook delivery could in theory be redirected to the server’s internal network by a fast-changing DNS record.',
                'fix' => 'Each delivery goes to the exact address that was checked.'],
        ];
    }

    /**
     * Business choices that affect security.
     *
     * @return list<array{key: string, id: string, today: string, recommendation: string}>
     */
    public static function decisions(): array
    {
        return [
            ['key' => 'decision.password_length', 'id' => 'D-1', 'today' => 'Passwords need at least 6 characters.', 'recommendation' => 'At least 10 characters; refuse known leaked passwords.'],
            ['key' => 'decision.partner_2fa', 'id' => 'D-2', 'today' => 'Two-factor authentication is optional for partner users.', 'recommendation' => 'Mandatory for partner owners and developers (they manage API keys).'],
            ['key' => 'decision.qa_tools', 'id' => 'D-3', 'today' => 'The QA test-data tool is available on the live system.', 'recommendation' => 'Switch it off on the live system (PAYGATE_QA_CHECKLIST=false).'],
            ['key' => 'decision.approval_limits', 'id' => 'D-4', 'today' => 'No limit on the deposit amount one user may approve.', 'recommendation' => 'Agree approval limits, with a second approver above a set amount.'],
        ];
    }

    /**
     * Manual tests, logged in with one user per role on a test environment.
     *
     * @return list<array{key: string, title: string, tests: list<array{key: string, test: string, expected: string}>}>
     */
    public static function manualTests(): array
    {
        return [
            ['key' => 'access', 'title' => 'Access control & data separation', 'tests' => [
                self::test('access.partner_ids', 'Partner A opens Partner B’s transaction, user or webhook by changing the ID in the address or request', 'Not found (404)'),
                self::test('access.branch_deposit', 'Branch A approves, holds or declines a deposit of Branch B', 'Refused (403/404)'),
                self::test('access.branch_payout', 'Branch A starts or completes a payout assigned to Branch B', 'Refused (403/404)'),
                self::test('access.branch_account', 'Branch A edits Branch B’s bank account', 'Not found (404)'),
                self::test('access.roles', 'Branch operator opens Users; partner viewer creates an API key', 'Refused (403)'),
                self::test('access.portals', 'A partner user opens the admin portal; a branch user opens the partner portal', 'Refused (403)'),
                self::test('access.horizon', 'An admin without the queue permission opens the queue dashboard (/horizon)', 'Refused (403)'),
                self::test('access.files', 'A payment-proof link opened by a partner, another branch, or after logging out', 'Not found / login page'),
                self::test('access.two_factor', 'An admin or branch user without 2FA opens any portal page or file', 'Sent to set up 2FA'),
            ]],
            ['key' => 'auth', 'title' => 'Login & sessions', 'tests' => [
                self::test('auth.password_throttle', 'Six wrong passwords in a minute; six wrong 2FA codes', 'Asked to wait (rate limit)'),
                self::test('auth.deactivated', 'Deactivate a user who is logged in; they click anything', 'Signed out'),
                self::test('auth.back_button', 'Log out, press Back and resubmit a form', 'Login page / expired'),
                self::test('auth.reset_link', 'A password reset link used twice or after 60 minutes', 'Refused'),
            ]],
            ['key' => 'api', 'title' => 'Partner API', 'tests' => [
                self::test('api.replay', 'Send a captured signed request again', 'Refused: nonce_reused'),
                self::test('api.old_timestamp', 'A request with a timestamp older than 5 minutes', 'Refused: timestamp_out_of_range'),
                self::test('api.tampered_body', 'Change one character of the body after signing', 'Refused: invalid_signature'),
                self::test('api.ip', 'Call from an IP address not on the partner’s list', 'Refused: ip_not_allowed'),
                self::test('api.duplicates', 'The same order ID twice; two payouts at once that together exceed the balance', 'No duplicate; one payout refused'),
                self::test('api.return_url', 'A return URL on another website', 'Refused: return_url_not_allowed'),
                self::test('api.webhook_private', 'Webhook URL https://127.0.0.1/ or https://169.254.169.254/', 'Refused'),
            ]],
            ['key' => 'money', 'title' => 'Money logic', 'tests' => [
                self::test('money.double_approve', 'Two browser tabs approve the same deposit at the same moment', 'One succeeds; one ledger entry'),
                self::test('money.utr_reuse', 'A UTR already used on a successful deposit is submitted again', 'Refused; Admin alerted'),
                self::test('money.amount_tamper', 'Change the amount in the payment page form or request', 'Ignored (amount comes from the server)'),
                self::test('money.payout_balance', 'A payout larger than the partner–branch balance', 'Refused: balance is low'),
                self::test('money.double_reverse', 'Reverse the same transaction twice', 'Second refused'),
            ]],
            ['key' => 'input', 'title' => 'Injection & uploads', 'tests' => [
                self::test('input.xss', 'Type "><script>alert(1)</script> into every text field (names, labels, notes, content pages, statements)', 'Shown as text, never runs'),
                self::test('input.csv_formula', 'A statement line =HYPERLINK("http://x"), then export it', 'Exported starting with an apostrophe'),
                self::test('input.upload_type', 'Upload a .php, .svg, .html, or a PHP file renamed .png as payment proof', 'Refused or stored harmlessly'),
                self::test('input.upload_size', 'Upload a 20 MB file', 'Refused (5 MB limit)'),
            ]],
            ['key' => 'external', 'title' => 'Independent tools', 'tests' => [
                self::test('external.ssllabs', 'SSL Labs (ssllabs.com/ssltest) grade for the portal address', 'Grade A or better'),
                self::test('external.headers', 'securityheaders.com grade for the portal address', 'Grade A or better'),
                self::test('external.dependencies', 'Dependency audits: composer audit and npm audit --omit=dev', 'No known vulnerabilities'),
                self::test('external.load', 'Load test on a test environment (3,000 requests, 50 at a time)', '95 % under 1.5 s, under 1 % errors'),
            ]],
        ];
    }

    /**
     * Every key → what it may be set to.
     *
     * @return array<string, list<string>>
     */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::manualTests() as $group) {
            foreach ($group['tests'] as $test) {
                $keys[$test['key']] = self::TEST_STATUSES;
            }
        }

        foreach (self::findings() as $finding) {
            $keys[$finding['key']] = self::FINDING_STATUSES;
        }

        foreach (self::decisions() as $decision) {
            $keys[$decision['key']] = self::DECISION_STATUSES;
        }

        return $keys;
    }

    /**
     * @return array{key: string, test: string, expected: string}
     */
    private static function test(string $key, string $test, string $expected): array
    {
        return ['key' => 'test.'.$key, 'test' => $test, 'expected' => $expected];
    }
}
