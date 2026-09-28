# PayGate — Developer Guide

Everything a developer needs to run PayGate on their own machine and carry on building it.
Read this first, then [Architecture.md](Architecture.md) for the why.

---

## 1. What this project is

PayGate is a manual payment-collection platform with three portals in one Laravel app:

- **Admin**: controls partners and branches, verifies bank/UPI accounts, assigns them to partners, and sees every transaction.
- **Partner** (merchant): gets API keys + webhooks, and sends its users to our payment page.
- **Branch** (bank-account provider): adds bank accounts / UPI IDs and confirms deposits.

**Stack:** Laravel 13 · PHP 8.5 (FrankenPHP) · PostgreSQL 18 · Redis 8 + Horizon · React 19 + TypeScript via Inertia · Tailwind · Docker Compose.

**Progress:** see [Architecture.md §19](Architecture.md#19-implementation-roadmap). Phases 0 (environment) and 1 (auth & roles) are done, and the **full database** (48 tables) is built, and the code is organised in domain modules. The build plan is [Implementation-Plan.md](Implementation-Plan.md). The business baseline is [Requirements.md](Requirements.md), the database design is [Database.md](Database.md), and staging/production setup is [Deployment.md](Deployment.md).

---

## 2. Prerequisites

| Need               | Notes                                                                                                 |
| ------------------ | ----------------------------------------------------------------------------------------------------- |
| **Docker Desktop** | Give it at least 4 GB RAM (Settings → Resources)                                                      |
| **git**            |                                                                                                       |
| **make**           | macOS: `xcode-select --install`. Windows: use **WSL2 (Ubuntu)** and run everything inside it.         |
| Free ports         | `80`, `5173`, `5432`, `6379`, `8025`, `8080`. Stop any local Apache/Nginx/MySQL/Postgres/Valet first. |

You do **not** need PHP, Composer or Node on your machine, because they all run inside Docker.

---

## 3. First-time setup

```bash
# 1. Get the code
git clone <repo-url> paygate
cd paygate

# 2. Local domains (one time, asks for your computer password)
sudo sh -c 'echo "127.0.0.1 paygate.local api.paygate.local pay.paygate.local" >> /etc/hosts'

# 3. Build and start everything
make setup
```

`make setup` does all of this:

1. builds the PHP image
2. creates `.env` from `.env.example`
3. runs `composer install` and `npm install`
4. starts all containers
5. generates the app key
6. runs the migrations
7. creates the demo users

The first run takes a few minutes.

Open **http://paygate.local** and log in with `admin@paygate.local` / `password`.

> **2FA on first login.** A fresh `.env` has `PAYGATE_ENFORCE_2FA=true`, so admin and branch users are sent to set up two-factor authentication first. Scan the QR code with any authenticator app (Google Authenticator, 1Password, …).
> For faster local work you may set `PAYGATE_ENFORCE_2FA=false` in **your own** `.env`. Never change it in `.env.example`.

---

## 4. URLs and logins (local only)

### Application

| URL                                     | What                                                                                                                                     |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| http://paygate.local                    | Portals: login, `/admin`, `/partner`, `/branch`                                                                                          |
| http://paygate.local/horizon            | Queue dashboard (open locally; super admins only on servers)                                                                             |
| http://paygate.local/admin/partners     | Partners: list, create wizard, drawer (keys, rates, branches, activity)                                                                  |
| http://paygate.local/admin/branches     | Branches: list, form, drawer (accounts, partners, users, top-ups, rates)                                                                 |
| http://paygate.local/admin/mappings     | Partner ↔ branch pairs: switches, pair limits, pair rates                                                                                |
| http://paygate.local/admin/accounts     | All bank & UPI accounts; “Review” to verify (log in as admin@ or ops@)                                                                   |
| http://paygate.local/branch/accounts    | Branch portal: the branch's accounts (log in as branch@)                                                                                 |
| http://paygate.local/partner/api-docs   | Partner API documentation (signing, endpoints, errors)                                                                                   |
| http://paygate.local/partner/api-logs   | The partner's own API calls                                                                                                              |
| http://paygate.local/partner/developers | Partner portal: API keys, endpoints, allowed IPs (log in as partner@ or developer@)                                                      |
| http://paygate.local/admin/qa-checklist | QA Checklist: every feature, how to test it, who to log in as; Pass / Fail per row, Re-run of its automated tests (local + staging only) |
| http://paygate.local/admin/ui-kit       | UI kit: every shared component with sample data (local only, log in as admin)                                                            |
| http://api.paygate.local/v1/ping        | Partner API health check                                                                                                                 |
| http://pay.paygate.local                | Payer pages (from Phase 5)                                                                                                               |

### Demo users (created by `make setup` / `make fresh`, password for all: `password`)

| Email                   | Portal  | Role        |
| ----------------------- | ------- | ----------- |
| admin@paygate.local     | Admin   | Super admin |
| ops@paygate.local       | Admin   | Operations  |
| finance@paygate.local   | Admin   | Finance     |
| partner@paygate.local   | Partner | Owner       |
| developer@paygate.local | Partner | Developer   |
| branch@paygate.local    | Branch  | Owner       |
| operator@paygate.local  | Branch  | Operator    |

### Tools

| URL / address         | What                                                                          | Login                                                                                          |
| --------------------- | ----------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| http://localhost:8080 | **Adminer** (database browser, like phpMyAdmin)                               | Server: _PayGate PostgreSQL (local)_ · User `paygate` · Password `secret` · Database `paygate` |
| http://localhost:8025 | **Mailpit**, where every email the app sends lands (invites, password resets) | none                                                                                           |
| `localhost:5432`      | PostgreSQL for desktop tools (TablePlus, DBeaver, DataGrip)                   | `paygate` / `secret`, databases `paygate` and `paygate_testing`                                |
| `localhost:6379`      | Redis                                                                         | no password                                                                                    |

These credentials are for **local development only**. Real servers use different secrets, kept outside git.

---

## 5. Daily commands

Run `make help` for the full list.

```bash
make up                 # start everything
make down               # stop everything (data is kept)
make ps                 # container status
make logs s=app         # follow logs: app, horizon, scheduler, vite, postgres, redis
make shell              # bash inside the app container

make artisan cmd="migrate:status"          # any artisan command
make composer cmd="require vendor/package" # any composer command
make npm cmd="install some-package"        # any npm command
make tinker             # Laravel REPL

make migrate            # run new migrations
make fresh              # WIPE the local DB, migrate, re-create demo users

make test               # PHP tests (make test f=PortalAccessTest)
make lint               # auto-fix PHP code style
make types              # static analysis (Larastan)
make check              # everything CI runs, so run it before every push

make horizon-restart    # reload queue workers after changing job code
```

---

## 6. Containers

| Service     | What it does                                                                                |
| ----------- | ------------------------------------------------------------------------------------------- |
| `app`       | FrankenPHP web server on port 80; serves all three hostnames                                |
| `vite`      | Frontend dev server with hot reload (port 5173)                                             |
| `horizon`   | Queue workers: `webhooks`, `notifications`, `default`, `maintenance`, `matching`, `reports` |
| `scheduler` | Runs scheduled tasks every minute                                                           |
| `postgres`  | Main database (source of truth)                                                             |
| `redis`     | Queues, cache, sessions, rate limits                                                        |
| `mailpit`   | Catches outgoing email                                                                      |
| `adminer`   | Database browser                                                                            |

PHP code changes apply immediately (refresh the page). React changes hot-reload. Only **queue jobs** need `make horizon-restart`.

---

## 7. Where things are

```text
app/
  Domain/                     BUSINESS LOGIC, one folder per module (see below)
    Core/
      Identity/               User, UserType, UserStatus, password/profile rules
      Rbac/                   Role, Permission catalogue, SystemRoles (9 built-in roles)
      Audit/                  AuditLog, SecurityLog, SecurityEvent, RecordSecurityEvents listener
    Partner/                  Partner (+ API credentials, configuration: coming phases)
    Branch/                   Branch (+ limits, top-ups: coming phases)
    Network/ PaymentAccount/ PaymentSession/ Allocation/ Transaction/ Payout/
    Commission/ Ledger/ Reconciliation/ Settlement/ Webhook/ Notification/
    Reporting/ Platform/      created as each phase starts
      (each module: Models/ Actions/ Services/ Enums/ Events/ Listeners/ Policies/ Jobs/)
  Http/                       DELIVERY ONLY (thin): calls domain actions, no business logic
    Admin/ Branch/ Partner/   portal controllers + requests (coming phases)
    Api/                      partner API (coming: Phase 6)
    Checkout/                 customer payment page (coming: Phase 6)
    Shared/Settings/          profile & security settings (all portals)
    Middleware/               request id, portal type, 2FA, active user, host restriction
    Controller.php            base controller
  Support/Database/           Pg helper (CHECK constraints, append-only triggers)
  Console/Commands/           paygate:create-admin
  Providers/                  app (gates, morph map), Fortify, Horizon
bootstrap/app.php             host routing, middleware, listener discovery in app/Domain
config/app.php                "domains" + business timezone
config/paygate.php            PayGate settings (2FA enforcement)
config/horizon.php            queue supervisors
routes/
  web.php                     portals (paygate.local)
  portals/*.php               admin / partner / branch routes
  api.php                     partner API (api.paygate.local)
  pay.php                     payer pages (pay.paygate.local)
resources/js/
  pages/                      Inertia pages (admin/, partner/, branch/, auth/, settings/,
                              users/ = the Users screen shared by all three portals)
  layouts/                    portal-layout (sidebar + top bar, all portals), auth-layout, settings/
  components/pg/              PayGate components from the design: sidebar, topbar, page header,
                              KPI card, data table, filters, status badge, drawer, confirm dialog,
                              wizard steps, empty state (see /admin/ui-kit)
  components/ui/              shadcn base components
  lib/money.ts                formatPaise(): paise -> "₹1,23,456.00" (never format money by hand)
  lib/status.ts               database status -> label + colour (e.g. under_review -> "Payment hold")
  lib/portal-nav.ts           sidebar menus per portal; `soon: n` = planned for phase n, `soon: 'later'` =
                              waiting for a client decision (both shown dimmed)
resources/css/app.css         design tokens: --pg-* colours, dark mode, portal accents, density
database/
  migrations/                 schema (2026_09_27_1000xx = the PayGate business tables)
  sql/                        app-privileges.sql (production database-user privileges)
  seeders/                    LocalDemoUserSeeder (local only: demo partner, branch, 7 users)
docker/                       PHP image, Postgres init script, Adminer config
tests/                        PHPUnit: Feature/, Unit/ (incl. ArchitectureTest)
Document/                     Requirements, Implementation-Plan, Architecture, Database, Deployment,
                              Features, Flows, Legacy-API, this guide
  PayGate UI redesign/        design export (claude.ai): excluded from formatting; do not edit by hand
```

**Module rules** (enforced by `tests/Unit/ArchitectureTest.php`, which fails the build):

- Business logic lives in `app/Domain/<Module>`; controllers in `app/Http/...` only validate input and call a domain action.
- Only the **Ledger** module may write ledger tables; everything else calls a Ledger action.
- `app/Domain` never depends on `app/Http`; `app/Http` never runs raw `DB::` queries.
- No `app/Models` folder: every model belongs to a module. Polymorphic columns store short names (`user`, `partner`, …) from the morph map in `AppServiceProvider`; register new models there.

---

## 8. The database in short

| Area                   | Tables                                                                                                                                   |
| ---------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Access                 | `users`, `roles`, `role_permissions`, `audit_logs`, `security_logs`                                                                      |
| Network                | `partners`, `partner_api_keys`, `partner_ip_rules`, `branches`, `branch_limit_topups`, `partner_branch_mappings`, `commission_rates`     |
| Accounts & limits      | `payment_accounts`, `usage_counters`                                                                                                     |
| Payments               | `partner_customers`, `transactions`, `payment_sessions`, `payout_beneficiaries`, `transaction_events`, `files`                           |
| Reconciliation         | `statement_imports`, `statement_entries`, `reconciliation_cases`, `statement_templates`                                                  |
| Ledger                 | `ledger_accounts`, `ledger_journals`, `ledger_entries`, `ledger_balances`                                                                |
| Settlement             | `settlements`, `settlement_lines`, `settlement_payments`, `adjustments`                                                                  |
| Integration & platform | `webhook_events`, `webhook_attempts`, `api_request_logs`, `settings`, `pages`, `reason_codes`, `verification_documents`, `notifications` |

Browse it in Adminer (http://localhost:8080). Full details and the ledger rules are in [Database.md](Database.md). The database itself refuses unsafe writes; `tests/Feature/Database/SchemaConstraintsTest.php` proves each rule.

## 9. Rules of the codebase

- **Three hostnames, one app.** `paygate.local` serves the portals, `api.` the Partner API, and `pay.` the payer pages. A route only answers on its own host.
- **Roles live in the database** (Admin edits them on _Roles & Permissions_), **permissions in code**: `app/Domain/Core/Rbac/Enums/Permission.php`, named `menu.action` (`payins.approve`, `users.create`, …), one grid row per `Menu` enum case. The 9 built-in roles are in `SystemRoles.php`. Check with `$user->can('payins.approve')` or `Gate::authorize(...)`; record-level rules (own organisation only, can't manage yourself) are in `UserPolicy` / `RolePolicy`. Portal routes are guarded by `user.type:<type>`.
- **No privilege escalation:** nobody can grant a permission, assign a role or manage a user holding more than they hold themselves. The super admin role is locked and always has every admin permission. The last active super admin can't be suspended or demoted.
- **Adding a permission:** add the case to `Permission` (and a `Menu` case if it's a new menu), grant it in `SystemRoles` if built-in roles need it, then add a migration that inserts it into `role_permissions` for existing roles on servers. Super admin gets it automatically.
- **Money in forms:** people type rupees ("1500.50"); `App\Support\Money::toPaise()` converts to paise in the form request. Empty limit = no limit (null). Never use floats for money or rates.
- **Commission rates are never edited:** `SetCommissionRate` closes the current rate (`effective_to = now`) and starts a new one, so history stays and past transactions keep their rate. Rates are strings with up to 4 decimals (`RatePercent`); a rate below a mapped branch's rate is allowed but flagged in the audit log (`negative_margin_pairs`).
- **API secrets are shown once:** `IssueApiKey` returns the plain secret a single time (flashed to the page as `credentials`); it is stored encrypted because we need it for HMAC checks and webhook signing. Rotation keeps the old key valid for 24 hours. Generating, rotating and revoking require the person's password.
- **Partner status:** draft → active (needs rates for enabled directions and an API key) → suspended / offboarded (`PartnerStatus::transitions()`). The partner code can't change after it goes live.
- **Bank account numbers and UPI IDs** are stored encrypted (`*_encrypted`), with a blind index (`*_hash`, `BlindIndex::of()`, key `PAYGATE_HASH_KEY`) so the same account can't be registered twice by any branch, and `*_last4` for display. Never log, return or show full numbers; the only exception is Admin's verification dialog, which records `payment_account.revealed` in the audit log.
- **Account lifecycle:** added → `verification_pending` → Admin approves (`verified`) or rejects with a reason → branch/Admin activates (`active`) ⇄ `paused` → `disabled` (final). Changing the holder, bank, IFSC, account number or UPI ID sends it back to `verification_pending`; limits and labels don't. Only `active` accounts will receive customers (Phase 6).
- **Branch deposit limits:** `daily_reset` = a cap per day (00:00 IST); `topup` = a running allowance changed only through `TopUpBranchLimit` (append-only `branch_limit_topups`, never below zero).
- **Pair rates:** a mapping may override the partner's or the branch's rate for that pair only (`RateBook::forPair()`); an empty override means the default applies.
- **Partner API** (`routes/api.php`, `app/Http/Api`): every call is signed (`ApiAuthenticator`: key id, timestamp ±5 min, single-use nonce in the cache, HMAC of `timestamp\nnonce\nMETHOD\npath\nsha256(body)`), must come from an allowed IP (off locally via `PAYGATE_API_ENFORCE_IP=false`), is rate limited per partner and logged to `api_request_logs` without bodies. Errors are always `{ "error": { "code", "message", "request_id" } }`; throw `ApiException` with a stable code. Amounts in the API are integer paise.
- **Pay-in flow:** `CreatePayin` (duplicate order_id + same details = original returned) → customer opens `pay.paygate.local/p/{token}` → picks a method → `AllocateAccount` (round robin by `last_allocated_at`, SKIP LOCKED then one waiting retry, reserves account/branch/pair/partner capacity in `usage_counters` with conditional updates) → `SubmitPayinProof` (UTR and/or screenshot on the private disk) → branch approval (Phase 7). `ClosePayin` expires (scheduler, every minute) or cancels and releases the reservation (`ReleaseAllocation`). The scheduler container must be running for expiry.
- **Payment page tokens** are derived from the session id with an HMAC under `APP_KEY` (so a retried create returns the same link) and only their SHA-256 is stored.
- **Trying the API locally:** give the demo partner a key under Admin › Partners › Demo Partner › API & security, then sign requests as shown in the partner API documentation (tests: `tests/Concerns/BuildsPayinNetwork.php::api()`).
- **Approving a pay-in** (`DecidePayin::approve`) is one database transaction: bank UTR (unique per receiving account), commission snapshot (`CommissionCalculator`, pair rate else partner/branch rate, half-up to the paisa), ledger journal (`Ledger::post`), reservation → confirmed usage, top-up allowance reduced, webhook outbox row, timeline event, audit. Hold and decline follow the same pattern; decline releases the reservation.
- **The ledger** (`app/Domain/Ledger/Ledger.php`) is the only code that touches `ledger_*` tables (ArchitectureTest). Post balanced journals only; never update or delete entries (the database refuses). Corrections are new reversal journals.
- **Webhooks** use an outbox: `QueueWebhook::forPayin()` writes `webhook_events` in the same transaction as the status change, `DeliverWebhook` (queue `webhooks`) sends it signed (`X-PayGate-Signature: t=…,v1=HMAC(secret, "t.body")`), retries 1m/5m/15m/1h/6h/24h, then `failed`; the scheduler re-queues due ones every minute. Private-network targets are refused on servers (`PAYGATE_WEBHOOKS_ALLOW_PRIVATE=false`). Locally, webhook URLs must be reachable from inside the Docker containers (`*.paygate.local` is not).
- **Payment page slots:** `max_open_sessions` counts customers currently on the payment page; submitting proof frees the slot (the amount stays reserved until the branch decides).
- **Private files** (payment proofs, imported statements) are only served by `FileController` after an access check (Admin and the receiving branch), never cached, and each view is audited. Statement files download; proofs show inline.
- **Payouts** (`app/Domain/Payout`): `CreatePayout` asks `PayoutRouter` for a mapped branch (round robin by `last_payout_assigned_at`) whose partner position covers amount + fee (`Ledger::reserve` is a conditional update — it waits, never skips), plus the branch / pair / partner daily withdrawal limits. None → `insufficient_balance` and nothing stored. The hold lives in the `assigned` / `reassigned` event (`PayoutReservation`). `ProcessPayout` starts, completes (W-A journal: partner −(amount+fee), branch +(amount+branch commission), margin the rest), fails, cancels or reassigns, always releasing or confirming the hold in the same transaction.
- **Beneficiary numbers** are encrypted; the branch sees them in full only in its queue while the payout is open (it has to make the transfer), masked everywhere else.
- **Statements & reconciliation** (`app/Domain/Reconciliation`, Phase 9): statement lines (`StatementEntry`) are typed in (`RecordStatementEntry`) or imported (`ImportStatement`: upload is staged privately, `StatementParser::guess()` proposes the header row and columns, the person confirms, the mapping is saved per header row in `statement_templates`). A line is stored once per account (`row_hash`; re-imports and overlapping files only add new lines). `StatementMatcher` links a line only on **exact UTR + amount + account** (debits: paid payouts of the branch) and opens one `ReconciliationCase` otherwise; it also runs the other way after `SubmitPayinProof`, `DecidePayin` (approve / decline) and `ProcessPayout::complete`, so a line that arrived first links itself later. A match never approves: the branch still approves (also from the statement line, via the deposit routes). `ResolveCase` links by hand, approves a late payment (Admin, `DecidePayin::approveLate`, partner gets `payin.success` with `late: true`) or closes a case as not a customer payment / returned to customer. Nothing here writes the ledger except the late approval.
- **Adding a bank's statement format:** usually nothing to code. Import one file, fix the columns on screen, give the layout a name; the next file with the same header row is mapped automatically. Reading rules (dates, `1,23,456.50`, `Cr`/`Dr`, UTRs inside narrations, zero-padded references) are in `StatementParser` with tests in `tests/Feature/Reconciliation/StatementParserTest.php`.
- **Settlement** (`app/Domain/Settlement`, Phase 10): `CalculateSettlement` builds a party's settlement from the ledger (`Ledger::statement()`: opening + movements = closing per pair account; commissions from the transactions booked). The scheduler job `settlements:daily` runs every minute but only acts once per cut-off (`Settings::lastCutoff()`, time and timezone in Admin › Global Settings); parties with no postings get no daily record. Admin can also calculate on demand. `RecordSettlementPayment` posts one `settlement` journal per pair line (partial allowed, never more than what's open, and for partners never more than balance − payout holds). Only the party's newest settlement takes payments (`Settlement::current()` scope). `ManageAdjustment` is maker–checker: request → a different admin approves (books an `adjustment` journal) or rejects with a note.
- **Global settings** are read and written through `App\Domain\Platform\Settings` (defaults in code, cached 60 s, every change audited). Add new keys there.
- **Dashboards & reports** (`app/Domain/Reporting`, Phase 11): `DashboardMetrics` computes each portal's dashboard for a `Scope` (Admin / one partner / one branch) and a `Period` (range buttons, business days); live queries cached 30 s, the page reloads `metrics` every 30 s. Reports live in `Reports/` (one class each, registered in `ReportCatalog`); a report declares its columns per scope (`Scope::sees()`), and the controller sends only those columns, so hidden figures never reach the browser. Exports: `ManageReportExports::request()` audits and queues `GenerateReportExport` on the `reports` queue (Horizon "slow" supervisor, connection `redis-long`); `ReportWriter` streams CSV / Excel (OpenSpout); the file is private to the requester and `reports:prune-exports` (hourly) deletes it after 7 days. After changing a report class, run `make horizon-restart`.
- **Adding a report:** subclass `Report` (key, title, columns per scope, `rows()` as a generator), add it to `ReportCatalog::all()`, and set `portals()` if not everyone should have it.
- **Refunds & chargebacks** (Phase 12, `ReverseTransaction`): only a successful transaction, once (`transaction_reversals`). Chargeback: Admin picks who bears it (partner → partner −amount / branch +amount; branch → nothing booked). Pay-in refund: same postings as a partner-borne chargeback. Returned payout: full reversal. Each posts a `reversal` journal pointing at the success journal, changes the status (`chargeback` / `refunded` / `returned`), sends a webhook and alerts partner and branch. Permission `reversals.view` / `reversals.create`.
- **Alerts** (`app/Domain/Notification`): `Alert` enum lists the events; `AlertDispatcher` decides who gets each (portal + organisation + permission) and is called from the action where the event happens; `PortalAlert` is a queued Laravel notification (queue `notifications`) stored for the bell and emailed unless the person turned email off (`users.notification_preferences`, Profile & settings › Notifications). The deposit-waiting alert runs every 5 minutes (`alerts:deposits-waiting`) and reports each deposit once. Locally the emails land in Mailpit. Don't name a page prop `alerts`: that's the shared bell prop.
- **Global Settings** (Admin › Global Settings): settlement cut-off, deposit-waiting alert threshold, payment-page support contact, decline / fail reasons (codes never change; switch a reason off instead; "other" always stays). **Content pages** (Global Settings › Content pages) are Markdown, public at `/legal/{slug}` on the portal and payment hosts once published; raw HTML is stripped.
- **QA Checklist** (Admin › Testing › QA Checklist, `app/Domain/Qa`; local and staging only, `PAYGATE_QA_CHECKLIST`, 404 on production): the list of features lives in **`app/Domain/Qa/Checklist.php`**: per row a stable key, URL (`api:` / `pay:` prefixes for the other hosts), description, steps, logins (`Checklist::LOGINS`) and the tests that cover it (`ClassTest` or `ClassTest::test_name`). **When you add a screen or a rule, add or update its row in the same commit;** `QaChecklistTest` fails if a named test doesn't exist. Testers' Pass / Fail + note and the latest automated result are stored in `qa_results`. _Re-run_ queues `RunQaChecks` on the `qa` queue (Horizon supervisor `qa`, local and staging only, one job at a time); `TestRunner` runs `vendor/bin/phpunit --filter …` in a clean environment (`env -i`) so phpunit.xml's `paygate_testing` database is used, and refuses to run if that is the app's own database. _Run all_ runs the whole suite once (about 4 minutes locally) and spreads the results. _Test data_ creates a pay-in (with its payment link) or payout through `CreatePayin` / `CreatePayout`, so testers don't need to sign API calls. Avoid `make test` while a run is in progress: both use the testing database.
- **Users are invited, never created with a password:** Admin (any portal) or a partner/branch owner (own organisation) sends an invitation; the emailed link is valid 72 hours (`invites` password broker) and setting the password verifies the email. Locally the emails land in Mailpit.
- **Money:** integer paise (`bigint`), never float.
- **IDs:** UUIDv7 primary keys (`HasUuids`).
- **Times:** stored in UTC. A business "day" uses `Asia/Kolkata` (`config('app.business_timezone')`).
- **PostgreSQL is the source of truth.** Redis only speeds things up, so never keep money or state only in Redis.
- **Logs are append-only.** `audit_logs` and `security_logs` can't be updated or deleted, because the database blocks it. Record changes with `AuditLog::record(...)` and `SecurityLog::record(...)`.
- **Users are suspended, never deleted.** Suspending, reactivating and resetting 2FA require a reason, which goes into the audit log.
- **Queued jobs** wait for the DB transaction to commit (`after_commit`), and each job must be safe to run twice.
- **Tests use real PostgreSQL** (`paygate_testing`), not SQLite.
- **UI:** build screens from `components/pg/*` and the design tokens (`bg-sf`, `text-tx2`, `border-ln`, `text-ac`, …), not raw colours. Show money with `formatPaise()` and statuses with `<StatusBadge>`. Each portal's accent colour comes from `data-portal` automatically. Check new components on `/admin/ui-kit`.
- **Before pushing,** `make check` must pass. GitHub Actions runs the same checks.

---

## 10. Troubleshooting

| Problem                                                                                         | Fix                                                                                                                                                                                                                                    |
| ----------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `paygate.local` doesn't open                                                                    | Hosts entry missing. Run `make hosts` to check, then step 3.2 above.                                                                                                                                                                   |
| Port 80 / 5432 / 6379 already in use                                                            | Stop the local web server / database using it (`sudo lsof -i :80`), then `make up`.                                                                                                                                                    |
| Adminer says "Connection refused"                                                               | Choose server **PayGate PostgreSQL (local)**; Adminer's MySQL default won't work. If it persists, check `make ps` shows postgres as healthy.                                                                                           |
| Page loads without styles / blank                                                               | Vite isn't running: `make logs s=vite`. Try `make npm cmd=install`, then `docker compose restart vite`.                                                                                                                                |
| "No application encryption key"                                                                 | `make artisan cmd="key:generate"`                                                                                                                                                                                                      |
| Forced to set up 2FA locally                                                                    | Expected when `PAYGATE_ENFORCE_2FA=true`; set it to `false` in your own `.env`.                                                                                                                                                        |
| `php artisan tinker` prints nothing                                                             | Its config folder isn't writable in the container; use `docker compose exec app php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); …'` |
| Queue job / email changes not picked up, or a queued email fails with "Route [...] not defined" | Horizon still runs the old code: `make horizon-restart` (needed after changing PHP code or routes)                                                                                                                                     |
| Statement import: "This file can't be read"                                                     | PDF statements aren't supported; download the statement from the bank as CSV or Excel. Password-protected Excel files must be saved without the password first.                                                                        |
| A statement line didn't match its deposit                                                       | Matching is exact (G-24): the UTR, the amount and the receiving account must all agree. The case in _Unsettled UTR_ / _Deposit Unsettled_ says which part differs.                                                                     |
| QA Checklist: Re-run stays "Queued" or shows "Stuck"                                            | Horizon isn't running the `qa` supervisor: `make horizon-restart` (and `make up` if Horizon is down). A run that doesn't finish in 20 minutes shows "Stuck" and can be started again                                                   |
| QA Checklist: a row shows "No test found"                                                       | The test names in `app/Domain/Qa/Checklist.php` don't match any test (renamed?). Fix the row; `make test f=QaChecklistTest` lists the wrong one                                                                                        |
| DB in a weird state                                                                             | `make fresh` (wipes local data)                                                                                                                                                                                                        |
| Demo partner/branch users show `MIGRATED-P` / `MIGRATED-B`                                      | Your DB predates the business tables; run `make fresh` for clean demo data                                                                                                                                                             |
| Start completely from scratch                                                                   | `docker compose down -v` (deletes DB + Redis volumes), then `make setup`                                                                                                                                                               |
| Files owned by root (Linux)                                                                     | Always use `make …` commands; they pass your user ID into the containers.                                                                                                                                                              |

---

## 11. Handover checklist

- [ ] Clone repo, add hosts entry, `make setup`
- [ ] Log in as each demo user and open each portal
- [ ] Open Adminer and Mailpit
- [ ] `make check` passes
- [ ] Open Admin › QA Checklist, click _Run all automated tests_, then walk through the rows by hand
- [ ] Read [Architecture.md](Architecture.md) §5 (flows), §6 (allocation), §8 (state machine), §11 (security & roles)
- [ ] Read [Requirements.md](Requirements.md) (business baseline) and [Database.md](Database.md)
- [ ] Before any staging/production deploy: follow [Deployment.md](Deployment.md)
- [ ] Check the roadmap (§19) for the next phase
