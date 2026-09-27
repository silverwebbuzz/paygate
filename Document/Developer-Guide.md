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

| URL                                     | What                                                                                |
| --------------------------------------- | ----------------------------------------------------------------------------------- |
| http://paygate.local                    | Portals: login, `/admin`, `/partner`, `/branch`                                     |
| http://paygate.local/horizon            | Queue dashboard (open locally; super admins only on servers)                        |
| http://paygate.local/admin/partners     | Partners: list, create wizard, drawer (keys, rates, branches, activity)             |
| http://paygate.local/partner/developers | Partner portal: API keys, endpoints, allowed IPs (log in as partner@ or developer@) |
| http://paygate.local/admin/ui-kit       | UI kit: every shared component with sample data (local only, log in as admin)       |
| http://api.paygate.local/v1/ping        | Partner API health check                                                            |
| http://pay.paygate.local                | Payer pages (from Phase 5)                                                          |

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
  lib/portal-nav.ts           sidebar menus per portal; `soon: n` = planned for phase n (shown dimmed)
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
| Reconciliation         | `statement_imports`, `statement_entries`, `reconciliation_cases`                                                                         |
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
- **Commission rates are never edited:** `SetCommissionRate` closes the current rate (`effective_to = now`) and starts a new one, so history stays and past transactions keep their rate. Rates are strings with up to 4 decimals (`RatePercent`); a rate below a mapped branch's rate is allowed but flagged in the audit log (`negative_margin_branches`).
- **API secrets are shown once:** `IssueApiKey` returns the plain secret a single time (flashed to the page as `credentials`); it is stored encrypted because we need it for HMAC checks and webhook signing. Rotation keeps the old key valid for 24 hours. Generating, rotating and revoking require the person's password.
- **Partner status:** draft → active (needs rates for enabled directions and an API key) → suspended / offboarded (`PartnerStatus::transitions()`). The partner code can't change after it goes live.
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

| Problem                                                                                         | Fix                                                                                                                                          |
| ----------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `paygate.local` doesn't open                                                                    | Hosts entry missing. Run `make hosts` to check, then step 3.2 above.                                                                         |
| Port 80 / 5432 / 6379 already in use                                                            | Stop the local web server / database using it (`sudo lsof -i :80`), then `make up`.                                                          |
| Adminer says "Connection refused"                                                               | Choose server **PayGate PostgreSQL (local)**; Adminer's MySQL default won't work. If it persists, check `make ps` shows postgres as healthy. |
| Page loads without styles / blank                                                               | Vite isn't running: `make logs s=vite`. Try `make npm cmd=install`, then `docker compose restart vite`.                                      |
| "No application encryption key"                                                                 | `make artisan cmd="key:generate"`                                                                                                            |
| Forced to set up 2FA locally                                                                    | Expected when `PAYGATE_ENFORCE_2FA=true`; set it to `false` in your own `.env`.                                                              |
| Queue job / email changes not picked up, or a queued email fails with "Route [...] not defined" | Horizon still runs the old code: `make horizon-restart` (needed after changing PHP code or routes)                                           |
| DB in a weird state                                                                             | `make fresh` (wipes local data)                                                                                                              |
| Demo partner/branch users show `MIGRATED-P` / `MIGRATED-B`                                      | Your DB predates the business tables; run `make fresh` for clean demo data                                                                   |
| Start completely from scratch                                                                   | `docker compose down -v` (deletes DB + Redis volumes), then `make setup`                                                                     |
| Files owned by root (Linux)                                                                     | Always use `make …` commands; they pass your user ID into the containers.                                                                    |

---

## 11. Handover checklist

- [ ] Clone repo, add hosts entry, `make setup`
- [ ] Log in as each demo user and open each portal
- [ ] Open Adminer and Mailpit
- [ ] `make check` passes
- [ ] Read [Architecture.md](Architecture.md) §5 (flows), §6 (allocation), §8 (state machine), §11 (security & roles)
- [ ] Read [Requirements.md](Requirements.md) (business baseline) and [Database.md](Database.md)
- [ ] Before any staging/production deploy: follow [Deployment.md](Deployment.md)
- [ ] Check the roadmap (§19) for the next phase
