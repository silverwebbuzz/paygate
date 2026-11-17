# PayGate — Security & Performance Testing

How PayGate is tested for security and performance before go-live and before the client's own testers (VAPT) start, and how the client report is produced. Last updated 2026-11-17.

**Rule:** test only servers we own, with the server owner's written OK and an agreed time window. Use a staging server with test data where possible. On production, run only the read-only scan (part 2) and never the load test in part 4 at full size.

---

## 1. Code review (done 2026-11-17)

A source review of the areas a payment gateway is usually attacked through. Summary:

| Area                                  | Result                                                                                                                                                                                |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Partner API authentication            | ✅ HMAC-SHA256 signed requests, constant-time compare, ±5 min timestamp, single-use nonce, IP allowlist (empty list = blocked), per-partner rate limit                                |
| Tenant isolation (partner / branch)   | ✅ Every API query is limited to the calling partner. Portal actions check scope (TransactionPolicy, UserPolicy, ReconciliationScope, per-controller `partner_id`/`branch_id` checks) |
| Money safety (double approval, races) | ✅ Approve / payout / ledger use row locks (`lockForUpdate`) and advisory locks; UTR uniqueness is enforced in the database                                                           |
| Public checkout                       | ✅ Unguessable HMAC token (only its SHA-256 stored), rate limits per IP, no branch or commission data shown                                                                           |
| File uploads (payment proofs)         | ✅ Type and size checked, stored privately with random names, served only after a permission check with `nosniff` and a strict CSP                                                    |
| SQL injection                         | ✅ Eloquent bindings everywhere; the few raw SQL fragments use fixed (whitelisted) column names                                                                                       |
| XSS                                   | ✅ React escapes output; CMS pages are Markdown with raw HTML and unsafe links stripped                                                                                               |
| Open redirect                         | ✅ `return_url` must be on the partner's registered domain                                                                                                                            |
| SSRF (webhooks)                       | ✅ https only, private/reserved addresses refused, no redirects followed; **fixed:** connection now pinned to the checked address (DNS rebinding)                                     |
| CSV / Excel injection                 | ✅ Cells starting with `= + - @` are escaped in every export                                                                                                                          |
| Audit trail                           | ✅ `audit_logs` / `security_logs` are append-only (database trigger)                                                                                                                  |
| Secrets                               | ✅ API secrets and bank numbers encrypted at rest, blind indexes for uniqueness, API keys shown once                                                                                  |

Fixed in this round (Phase 13 hardening):

| #   | Finding                                                                                     | Fix                                                                                                     |
| --- | ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| H-1 | No browser security headers (HSTS, X-Frame-Options, CSP, nosniff, Referrer-Policy)          | `App\Http\Middleware\SecurityHeaders` on every response; API answers `Cache-Control: no-store`          |
| H-2 | Failed Partner API authentications were not limited per IP (guessing, junk load)            | 30 failures per IP per minute, then `429 rate_limited` (`AuthenticatePartner::MAX_FAILURES_PER_MINUTE`) |
| H-3 | Private files (payment proofs) could be opened by Admin/Branch users who had not set up 2FA | `files/{file}` now has `two-factor.required` like the portals                                           |
| H-4 | Webhook SSRF check and the request resolved DNS separately (DNS rebinding)                  | `WebhookTarget::pinnedCurlOptions` pins curl to the checked public IP                                   |
| H-5 | No quick way to verify a server's security settings                                         | `php artisan paygate:security-check` (read-only; exit 1 on FAIL)                                        |

Open decisions (client to confirm):

| #   | Item                                                                                                            | Recommendation                                                             |
| --- | --------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| D-1 | Password minimum is 6 characters (set deliberately in commit e19c02a)                                           | At least 10 characters, checked against known leaked passwords             |
| D-2 | 2FA is optional for Partner users                                                                               | Mandatory for `partner.owner` and `partner.developer` (they hold API keys) |
| D-3 | QA Checklist "Test data" creates real pay-ins/payouts and is on by default                                      | `PAYGATE_QA_CHECKLIST=false` on production (the security check warns)      |
| D-4 | CSP is a baseline (framing, plugins, `<base>`), not a full `script-src` policy                                  | Add nonce-based `script-src` after browser testing of every page           |
| D-5 | Approval limits per user/amount (G-44) still open: anyone with `payins.approve` in scope can approve any amount | Decide limits; maker-checker above a threshold                             |

---

## 2. Automated external scan (read-only)

Run from your Mac against the live server after each deploy:

```bash
# On the server first (as the site user, in the app folder):
php artisan paygate:security-check

# Then from your Mac:
scripts/security-scan.sh https://<portal> https://<api> https://<pay>
# one host with paths, e.g.:
scripts/security-scan.sh https://aidemo.in https://aidemo.in/api https://aidemo.in/pay
```

It writes `security-report-<host>-<date>.md`. It never logs in and never creates data. It checks:

| Area        | Checks                                                                                                                                                   |
| ----------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| TLS         | TLS 1.0/1.1 refused, TLS 1.2 works, certificate valid > 14 days, http → https redirect                                                                   |
| Headers     | HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, CSP; no PHP or server version shown                                                      |
| Cookies     | Session and XSRF cookies have Secure / HttpOnly / SameSite                                                                                               |
| Exposure    | `.env`, `.git`, `composer.*`, logs, `vendor/`, phpinfo, adminer, server-status, Telescope, Ignition not public; `/horizon` and every portal need a login |
| Errors      | No stack traces or SQL on 404 or bad API input; TRACE disabled                                                                                           |
| Partner API | Unsigned and forged requests get 401; lockout (429) after repeated failures; CORS not open                                                               |
| Checkout    | Random or injected tokens get 404; token guessing is rate-limited                                                                                        |
| Login       | CSRF token present; POST without it refused                                                                                                              |
| Performance | Light load with Apache Bench (default 300 requests, 10 at a time): req/s, p50/p95/p99, failures                                                          |

The scan trips the lockout and checkout rate limits **for the scanning machine's IP for about a minute**. Real partners are not affected.

Also run, from a browser:

- **SSL Labs** (`https://www.ssllabs.com/ssltest/`): target grade A.
- **securityheaders.com**: target grade A.
- **Mozilla Observatory** (`https://developer.mozilla.org/en-US/observatory`).

And dependency audits in Docker: `make composer cmd="audit"` and `make npm cmd="audit --omit=dev"`.

---

## 3. Manual authenticated tests (staging, test data)

Use one test login per role plus a test partner API key. Expected result in brackets.

### Access control and tenant isolation (OWASP A01)

1. Partner A opens Partner B's transaction, user or webhook by changing the ID in the URL or request (404).
2. Branch A approves, holds or declines a deposit of Branch B (403/404).
3. Branch A starts or completes a payout assigned to Branch B (403/404).
4. Branch A edits Branch B's bank account (`PUT /branch/accounts/{id}`) (404).
5. `branch.operator` opens Users or Accounts › Add; `partner.viewer` creates an API key (403).
6. A partner user opens `/admin/...` and a branch user opens `/partner/...` (403).
7. An admin user without `horizon.view` opens `/horizon` (403).
8. A payment proof URL (`/files/{id}`) opened by a partner, another branch, or after logout (404 / login).
9. An admin or branch user without 2FA opens any portal page or file (sent to Security settings).

### Authentication and sessions (A07)

10. Six wrong passwords in a minute (throttled); six wrong 2FA codes (throttled).
11. Deactivate a logged-in user; their next click (logged out).
12. Log out, press Back, resubmit a form (login page / 419).
13. Password reset link reused or older than 60 minutes (refused).

### Partner API (A04 / A08)

14. Replay a captured signed request (`nonce_reused`).
15. A timestamp older than 5 minutes (`timestamp_out_of_range`).
16. Change one byte of the body after signing (`invalid_signature`).
17. Call from an IP not on the allowlist (`ip_not_allowed`).
18. The same `order_id` twice (same pay-in returned, no duplicate); two payouts at the same moment that together exceed the balance (one refused).
19. `return_url` on another domain (`return_url_not_allowed`).
20. Webhook URL `https://127.0.0.1/`, `https://169.254.169.254/`, or a domain that resolves to a private IP (refused).

### Money logic

21. Two browser tabs approve the same deposit at the same time (one succeeds, one gets an error; one ledger entry).
22. A UTR already used on a successful pay-in submitted again (refused, Admin alerted).
23. Change the amount in the checkout form or request (ignored: the amount comes from the server).
24. Payout larger than the partner↔branch balance (refused, "balance is low").
25. Reverse a transaction twice (second refused).

### Injection and uploads (A03 / A05)

26. `'"><script>alert(1)</script>` in every text field: customer name, account label, notes, CMS page, statement description (shown as text, never runs).
27. A CSV statement with a cell `=HYPERLINK("http://x")`, then export it (cell starts with `'`).
28. Upload a `.php`, `.svg`, `.html` or a PHP file renamed `.png` as payment proof (refused or stored without running; served with `nosniff`).
29. A 20 MB upload (refused: 5 MB limit).

Record each test as Pass/Fail with a screenshot in the client report (part 5).

---

## 4. Performance and load testing

| Test       | How                                                                                                              | Target                           |
| ---------- | ---------------------------------------------------------------------------------------------------------------- | -------------------------------- |
| Baseline   | `scripts/security-scan.sh` (300 requests, 10 at a time)                                                          | p95 < 1 s, 0 failures            |
| Load       | `LOAD_REQUESTS=3000 LOAD_CONCURRENCY=50 scripts/security-scan.sh …` (**staging only**, agreed window)            | p95 < 1.5 s, < 1 % errors        |
| API create | A partner test script creating signed pay-ins (TestPayins command, `php artisan paygate:test-payins` on staging) | No duplicate allocations, no 5xx |
| Soak       | Load test repeated every 5 min for 1 hour while watching Horizon queues                                          | Queues drain, memory stable      |

While load tests run, watch on the server: `top` (CPU/RAM), Horizon (queue wait times), PostgreSQL connections, `storage/logs/laravel.log` (errors).

---

## 5. Client report

The report is built in the app: **Admin › Testing › Security Report** (super admins only, every environment, including production).

1. **Report details:** client name, round (1, 2, …), environment, tester and an optional summary note. They appear on the cover and in the summary.
2. **Automated scan:** _Run scan_ queues `RunSecurityScan` (queue `reports`). The server checks its own configuration (the same rules as `paygate:security-check`) and then calls its own public addresses with the checks of `scripts/security-scan.sh`. A light load follows: `PAYGATE_SCAN_LOAD_REQUESTS` (default 100) per page, `PAYGATE_SCAN_LOAD_CONCURRENCY` (default 10) at a time. Results are stored in `security_scans`, and the page refreshes until the scan is done. Times are measured from the server itself, so internet distance is not included.
3. **Manual tests:** each test from part 3 gets Pass / Fail / N/A plus a note.
4. **Findings & decisions:** set each finding to Open / Fixed (not deployed) / Verified / Accepted, and each client decision to Pending / Agreed / Declined. Notes are printed in the report.
5. **Preview & PDF:** shows the report as HTML; _Download PDF_ gives the same document for the client.

The fixed text (verified areas, findings, decisions, manual tests) lives in `app/Domain/SecurityReport/Catalogue.php`. Add new findings there, and keep this document in step.

Each report has:

1. Scope: hosts, dates, tester, what was excluded (no DoS, no social engineering).
2. Summary: number of checks, pass/fail, severity of open items (Critical / High / Medium / Low / Info).
3. Code review: part 1 above.
4. Automated scan: the generated `security-report-*.md` plus SSL Labs / securityheaders grades.
5. Manual tests: the part 3 list with Pass/Fail.
6. Performance: the scan's table plus any load test.
7. Open items with owner and due date (part 1 "Open decisions" plus anything new).

Never put real customer data, passwords, API secrets or the server's `.env` in the report.
