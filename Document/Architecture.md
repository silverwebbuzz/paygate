# PayGate — Architecture

> Manual payment-collection platform: **Admin** controls **Partners** (merchants) and **Branches** (bank-account providers).
> Partners send their users to our payment page. The page shows one of the bank/UPI accounts assigned to that partner. The user pays, and the payment is verified and reported back to the partner by webhook.

|              |                                                                         |
| ------------ | ----------------------------------------------------------------------- |
| Status       | Draft v1 — Phases 0–1 done (environment, auth & roles); Phase 2 next    |
| Last updated | 2026-09-25                                                              |
| Scope        | Architecture, core flows, security, local setup, implementation roadmap |

> **Scope update pending (2026-09-27):** the business model is now defined in [Requirements.md](Requirements.md) v1 (partner↔branch network, no customer funds in our account, four commission rates, external net settlement). Sections 2, 5, 8, 9 and §18–19 of this document will be revised once Requirements v1 is approved. Phase 2 is on hold until then.

---

## 1. What we are building

### 1.1 Actors

| Actor       | Who                                                   | What they do                                                                                                                                         |
| ----------- | ----------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Admin**   | Platform operators (super admin, admin, ops, finance) | Create/suspend partners and branches, verify bank/UPI accounts, assign accounts to partners, watch every transaction, resolve disputes, settle money |
| **Partner** | Merchant websites/apps                                | Get API credentials + webhook secret, create payment sessions for their users, receive webhooks when a payment is confirmed/failed                   |
| **Branch**  | Bank-account providers                                | Add bank accounts / UPI IDs in their panel, see deposits made to their accounts, confirm or reject them                                              |
| **Payer**   | End user of a partner                                 | Opens the payment URL, sees bank/UPI details, pays from their own bank app, submits the UTR/reference                                                |

### 1.2 Glossary

> Canonical terms are defined in [Features.md §0](Features.md#0-terminology-agreed-2026-09-27). In this document, **payer** = **partner customer** (the partner's end user), and external payment gateways are called **providers**.

| Term                | Meaning                                                                                                                         |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| **Payment account** | One bank account or UPI ID owned by a branch. It is shown to payers after admin verification.                                   |
| **Assignment**      | Link between a payment account and a partner. Only assigned accounts are shown to that partner's payers.                        |
| **Payment session** | One payment attempt. It has a public token, an amount, an expiry and exactly one allocated account.                             |
| **Transaction**     | The financial record behind a session. It holds the amount, its state and the event history.                                    |
| **UTR**             | Unique Transaction Reference — the 12-digit reference number of an IMPS/UPI/NEFT transfer. It is our main proof-of-payment key. |
| **Settlement**      | Money moving from branch → partner (minus fees), recorded in the ledger.                                                        |

### 1.3 Expected scale (initial)

- ~100 partners, ~100 branches, a few hundred to a few thousand payment accounts
- Thousands of payers per day → realistically **tens of thousands of sessions/day**
- Peak ≈ **50–200 payment-session requests/second** in bursts

A single well-configured Laravel server handles this comfortably. The architecture stays **simple first** and keeps a clear path to horizontal scaling (§16).

---

## 2. Corrections to the original draft (important)

The first draft (ChatGPT) was a sound generic payment-gateway architecture. Several points needed changing for **this** system:

1. **There is no "Payment Provider" and no inbound provider webhook.** This is a _manual_ gateway, so money goes directly into the branches' bank accounts. The confirmation source is:
    - the **payer** submitting a UTR (+ optional screenshot),
    - the **branch** confirming it against their bank statement/app,
    - later: **statement upload / auto-matching** (CSV/Excel from the bank), and possibly SMS/email alert parsing.

    Every "inbound provider webhook" in the draft is replaced by the **UTR submission + branch confirmation** flow (§5.5–5.6).

2. **Deposit matching is the hardest problem, not concurrency.** Many payers may send the same amount to the same account, so we need UTR uniqueness, optional amount tagging, expiry rules and a dispute path (§7).
3. **"Capacity" is concrete here.** It means bank/UPI limits: per-transaction min/max, daily amount limit, daily count limit, and a cap on how many sessions can be open on one account at a time. These drive allocation (§6).
4. **Octane is optional at this scale.** We keep it because it is cheap to adopt and we use FrankenPHP. Correctness never depends on it.
5. **Compliance is an architecture input** (§14). Collecting funds on behalf of merchants into third-party accounts is regulated in India (RBI Payment Aggregator rules, KYC/AML, account-usage rules). This affects audit logging, KYC data, retention and what we store, so it must be reviewed with a legal/compliance advisor before going live.

Everything else from the draft is kept: PostgreSQL is the source of truth, Redis only accelerates, it is a modular monolith, there are HMAC + idempotency on the API, row locks with `SKIP LOCKED`, Horizon queues and staged scaling.

---

## 3. Technology stack

| Layer                      | Choice                                                                    | Why                                                                                                                                        |
| -------------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Language / framework       | **PHP 8.5 + Laravel 13**                                                  | Mature and fast to build with. First-party queues (Horizon), auth, rate limiting, encryption and testing.                                  |
| App server                 | **FrankenPHP** (Laravel Octane driver in production, classic mode in dev) | One binary with Caddy built in and HTTP/2/3. Worker mode gives Octane-level speed.                                                         |
| Database                   | **PostgreSQL 18**                                                         | Strong transactions, `FOR UPDATE SKIP LOCKED`, partial indexes, check constraints, JSONB                                                   |
| Queue / cache / rate limit | **Redis 8** + **Laravel Horizon**                                         | Horizon gives supervised workers, metrics and retries. (Horizon does not support Redis Cluster, so we use a single primary + replica.)     |
| Portals UI                 | **React + TypeScript via Inertia.js**                                     | One codebase and session auth (CSRF-safe) without building a separate token-based SPA. Admin, Partner and Branch portals share components. |
| Payment page               | Lightweight Inertia/Blade page                                            | Must load fast on low-end phones and shows a UPI QR + deep link                                                                            |
| Object storage             | S3-compatible (local disk in dev for now)                                 | Payment screenshots, KYC docs, statement uploads, exports                                                                                  |
| Edge                       | **Cloudflare** (WAF, DDoS, rate limits) → Nginx/Caddy                     | Standard                                                                                                                                   |
| Monitoring                 | Sentry + structured logs + Horizon + DB/Redis metrics                     | Grafana/Prometheus later if needed                                                                                                         |
| Tests / quality            | PHPUnit, Larastan (PHPStan), Pint, Vite+ lint, GitHub Actions CI          |                                                                                                                                            |
| Local dev                  | **Docker Compose** at `paygate.local`                                     | Nothing needs to be installed on the host except Docker                                                                                    |

---

## 4. System context

```text
   Payer (browser/phone)              Partner server                Branch user          Admin user
          │                                 │                            │                    │
          │ pay.paygate.local/p/{token}     │ api.paygate.local/v1       │   paygate.local/branch   paygate.local/admin
          ▼                                 ▼                            ▼                    ▼
   ┌───────────────────────────────── Cloudflare (WAF / DDoS / rate limit / TLS) ─────────────────────────┐
   └───────────────────────────────────────────────┬──────────────────────────────────────────────────────┘
                                                   ▼
                              ┌─────────────────────────────────────────┐
                              │   Laravel 13 modular monolith           │
                              │   FrankenPHP / Octane (stateless)       │
                              │                                         │
                              │  Auth·RBAC·2FA   Partners   Branches    │
                              │  PaymentAccounts  Allocation  Sessions  │
                              │  Transactions  Matching  Webhooks(out)  │
                              │  Ledger/Settlement  Reports  Audit      │
                              └──────┬───────────────┬──────────────────┘
                                     │               │
                           ┌─────────▼──────┐  ┌─────▼──────────┐     ┌───────────────┐
                           │ PostgreSQL     │  │ Redis          │────►│ Horizon       │
                           │ SOURCE OF TRUTH│  │ queue · cache  │     │ workers       │
                           └────────────────┘  │ rate · locks   │     └──────┬────────┘
                                               └────────────────┘            │
                                                                ┌────────────┴──────────────┐
                                                                ▼                           ▼
                                                     Partner webhook URLs       Email / SMS / S3
```

**Golden rule:** _no money, account state, reservation or transaction state exists only in Redis._ If Redis is lost we lose speed, never correctness.

---

## 5. Core flows

### 5.1 Branch onboarding & account verification

```text
Admin creates Branch ──► Branch admin created with a password ──► logs in, sets up 2FA
Branch adds Payment Account (bank a/c + IFSC  or  UPI VPA, holder name, limits)
        status = PENDING_VERIFICATION
Admin reviews (optional penny-drop / test deposit) ──► VERIFIED ──► ACTIVE
Admin assigns account (or all accounts of a branch) to one or more Partners
```

Account statuses: `pending_verification → verified → active ⇄ paused → disabled` (plus `rejected`).
A branch can **pause** its own account (for example when the bank limit is hit). Only an admin can activate, disable or re-verify it.
Changes to bank details after verification send the account back to `pending_verification` (maker–checker).

### 5.2 Partner onboarding

- Admin creates the partner and configures: allowed IPs (optional), webhook URL, min/max amount, fee plan, and session TTL.
- The partner receives:
    - `key_id` (public) + `api_secret` (shown **once**, stored **encrypted** because HMAC verification needs the plaintext)
    - `webhook_secret` for verifying our outbound webhooks
- Both secrets can be rotated with an overlap window.

### 5.3 Create payment session (Partner → API) — synchronous

```text
Partner server ── POST /v1/payment-sessions ─────────────────────────────►
   headers: X-Key-Id, X-Timestamp, X-Nonce, X-Signature (HMAC-SHA256), Idempotency-Key
   body:    { order_id, amount (paise), payer_ref, method: upi|bank, return_url, metadata }

API: verify signature + timestamp (±5 min) + nonce unused + partner active + IP allow-list
     rate-limit (per partner) → idempotency lookup (partner_id, idempotency_key)
     BEGIN
        allocate account (§6)  – row lock, SKIP LOCKED
        insert transaction (state = CREATED), payment_session (token_hash, expires_at)
        insert transaction_event, idempotency record
     COMMIT
◄── 201 { session_id, reference, payment_url: https://pay.paygate.local/p/{token}, expires_at }
```

The partner redirects its payer to `payment_url`. The transaction must stay short: no HTTP calls, emails or webhooks inside it.

> The "payment URL with secret key" idea from the brief (a static URL per partner) is **not recommended**: anyone who copies it can create sessions. The server-to-server API above is the default. If a partner really cannot call an API, we can offer a _signed redirect_ (partner signs `amount+order_id+expiry` with HMAC and redirects the payer). This is an open decision (§18).

### 5.4 Payer page

```text
GET /p/{token} → hash(token) → session lookup
   ACTIVE    → show allocated account: UPI QR (upi://pay?pa=…&am=…&tn=REF), VPA, or bank a/c + IFSC,
               copy buttons, exact amount, countdown timer, UTR form
   SUBMITTED → "we are verifying your payment"
   COMPLETED → success + redirect to partner return_url
   EXPIRED / INVALID → message, no account details shown
```

- **Refreshing never re-allocates.** The session is stuck to its account.
- Account details are rendered only for a valid active token. The page is rate-limited per token/IP, and `noindex` + no-cache headers are set.

### 5.5 Payer submits proof

`POST /p/{token}/submit { utr, screenshot? }`

- UTR format is validated (12 digits for UPI/IMPS, 16–22 chars for NEFT/RTGS, configurable).
- A **unique constraint on (payment_account_id, utr)** blocks the same UTR being claimed twice.
- Transaction → `SUBMITTED`, and the branch is notified (in-panel + optional push/SMS).

### 5.6 Branch confirmation

The branch panel shows a live queue of `SUBMITTED` (and `AWAITING_PAYMENT`) deposits for **its own** accounts.
The branch checks its bank statement/app, then:

- **Confirm** (optionally correcting the received amount) → `CONFIRMED` → ledger entries → outbound webhook `payment.confirmed`
- **Reject** (reason: UTR not found / amount mismatch / …) → `REJECTED` → webhook `payment.failed`

An admin can override either decision. Every override is audited.
**Statement lines** (typed in, or imported from the bank's CSV / Excel file; Phase 9) are matched on exact UTR + amount + receiving account. A match links the line and tells the branch the credit is in the bank; it never approves by itself (decided 2026-09-28, G-23). Anything that doesn't match exactly becomes a case in the unsettled queue (Unsettled UTR / Deposit Unsettled).

### 5.7 Expiry, late payments, disputes

- A scheduled job expires sessions past `expires_at` with no submission → `EXPIRED`, the reservation is released, and webhook `payment.expired` is sent.
- **Late payment** (money arrives after expiry): the branch can still find it by UTR. An admin/branch _late-confirms_ it → `CONFIRMED` with a `late` flag, and the partner webhook fires. This never silently goes nowhere.
- **Unmatched deposit** (money in the bank with no session): recorded as an `unmatched_deposit` for admin to resolve (refund or attach to a session).
- **Dispute** (payer says paid, branch says no): the transaction goes to `DISPUTED` and admin resolves it with evidence (screenshots, statements).

---

## 6. Account allocation

Requirement: payers see a **random** eligible account from those assigned to their partner, without overloading any one account.

### Eligibility (all must be true)

- the account is assigned to this partner (and the assignment is active)
- account status is `active` and the branch is active
- method matches (UPI / bank)
- `min_amount ≤ amount ≤ max_amount`
- today's reserved + confirmed amount + `amount` ≤ daily amount limit
- today's count < daily count limit
- open (unexpired, unsettled) sessions on this account < `max_open_sessions`
- not in cooldown (for example after repeated rejections or a manual pause)

### Algorithm (PostgreSQL decides, inside the session-creation transaction)

```sql
SELECT a.id
FROM payment_accounts a
JOIN partner_account_assignments pa ON pa.payment_account_id = a.id
JOIN account_daily_usage u ON u.payment_account_id = a.id AND u.usage_date = current_date
WHERE pa.partner_id = :partner AND pa.active
  AND a.status = 'active' AND a.method = :method
  AND :amount BETWEEN a.min_amount AND a.max_amount
  AND u.reserved_amount + u.confirmed_amount + :amount <= a.daily_amount_limit
  AND u.open_sessions < a.max_open_sessions
ORDER BY random()          -- or weighted: -ln(random()) / a.weight
LIMIT 1
FOR UPDATE OF u SKIP LOCKED;
```

Then, in the same transaction: increment `reserved_amount` and `open_sessions`, and create the transaction and session.
On confirm, move the amount reserved → confirmed. On expire/reject, release it.

- `SKIP LOCKED` means concurrent requests never queue behind the same account; each one takes a different free account.
- If **no** account is eligible → `503 NO_ACCOUNT_AVAILABLE`. Admin is alerted (capacity alarm), and we never "guess".
- Per-partner candidate sets are small (tens of accounts), so `ORDER BY random()` is cheap. Weighted selection lets admin favour some accounts.
- The daily usage row is created lazily (`INSERT … ON CONFLICT DO NOTHING`) at day start. The day boundary is **Asia/Kolkata**, while timestamps are stored in UTC.

---

## 7. Deposit matching & fraud controls

| Risk                                                                | Control                                                                                                                                        |
| ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Same UTR claimed twice                                              | `UNIQUE (payment_account_id, utr)`, plus a global UTR check with admin alert                                                                   |
| Two payers pay the same amount to the same account at the same time | UTR is the key. Optional **amount tagging**: add 1–99 paise so each open session on an account has a unique amount (configurable per partner). |
| Fake screenshot                                                     | Screenshot is evidence only. Confirmation always comes from the branch/statement.                                                              |
| Branch confirms fake deposits (collusion)                           | Audit trail, admin sampling, statement upload reconciliation, per-branch risk score, settlement holds                                          |
| Branch never confirms                                               | SLA timers per branch; admin escalation dashboard                                                                                              |
| Payer submits a random UTR                                          | Rejected by branch; repeated rejections per payer_ref/IP → throttle/block                                                                      |
| Account frozen by bank                                              | Branch/admin pauses the account; open sessions can be re-routed only if nothing was submitted                                                  |

---

## 8. Transaction state machine

```text
             ┌──────────── expire (no UTR) ─────────► EXPIRED ──(late payment found)──┐
             │                                                                        │
CREATED ─► AWAITING_PAYMENT ─► SUBMITTED ─► CONFIRMED ─► SETTLED                      │
             │                   │   ▲         ▲                                       │
             │                   │   └─────────┴──────────── late-confirm ◄────────────┘
             │                   ├─► REJECTED
             │                   └─► DISPUTED ─► CONFIRMED | REJECTED
             └─► CANCELLED (partner/admin)
```

- Transitions are implemented in **one place** (a state-machine service). Each transition is an `UPDATE … WHERE state = :expected` (optimistic check), so two people can never confirm the same transaction.
- Every transition writes an append-only `transaction_events` row: who, when, from, to, reason, request_id.
- Terminal states are `CONFIRMED`, `REJECTED`, `EXPIRED` and `CANCELLED`. `SETTLED` is reached only after money is settled, and `CONFIRMED`/`SETTLED` can never be undone. Corrections are made with a **reversal** entry in the ledger.

---

## 9. Money & ledger

- Amounts are stored as **integer paise (`bigint`)**, never float, and currency is INR only for now (a currency column is kept for the future).
- **Double-entry ledger** (`ledger_entries`, append-only). Each confirmation writes balanced entries, for example:
    - Branch _collected_ +₹1000
    - Partner _payable_ +₹980, Platform _fee_ +₹20
- Balances (partner payable, branch holding, platform revenue) are derived from the ledger. Cached balance columns are updated in the same DB transaction.
- **Settlements**: admin records a payout (branch → partner, or via platform) with a reference → ledger entries → transactions move to `SETTLED`.
- Fee plans (percentage / flat / slab per partner, and optionally a branch commission) are defined in the database phase.

---

## 10. Partner API

| Item              | Decision                                                                                                                                                                                                                                                                                                                    |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Base URL          | `https://api.paygate.local/v1/` (local)                                                                                                                                                                                                                                                                                     |
| Auth              | `X-Key-Id` + HMAC-SHA256 of `timestamp\nnonce\nMETHOD\npath\nsha256(body)` using `api_secret`                                                                                                                                                                                                                               |
| Replay protection | Timestamp ±300 s, and the nonce is stored in Redis for 10 min (fallback: DB unique)                                                                                                                                                                                                                                         |
| Idempotency       | `Idempotency-Key` header is required on POST. It is unique per `(partner_id, key)` in PostgreSQL and stores a request hash + response. Same key + same body → replay the stored response; same key + different body → `409`.                                                                                                |
| Endpoints (v1)    | **As built (Phase 6):** `POST /v1/payins`, `GET /v1/payins/{id}`, `GET /v1/payins?order_id=`, `POST /v1/payins/status` (up to 100), `POST /v1/payins/{id}/cancel`. Idempotency uses the partner's `order_id` + a request hash instead of an Idempotency-Key header. Partner-facing docs: partner portal › API documentation |
| Errors            | JSON `{ error: { code, message, request_id } }`, with stable machine codes                                                                                                                                                                                                                                                  |
| Rate limits       | Per partner (Redis) + Cloudflare edge rules                                                                                                                                                                                                                                                                                 |
| Versioning        | URL-versioned (`/v1`). Breaking changes go to `/v2`.                                                                                                                                                                                                                                                                        |

### Outbound webhooks (to partners)

- Events: `payment.submitted`, `payment.confirmed`, `payment.failed`, `payment.expired`, `payment.late_confirmed`, `settlement.completed`
- Delivery: an `webhook_deliveries` row is written **in the same DB transaction** as the state change (outbox pattern). Horizon then sends it, which gives no lost webhooks even if Redis/workers restart.
- Signature header: `X-PayGate-Signature: t=<unix>,v1=<hmac_sha256(webhook_secret, t + "." + body)>`, plus an `X-PayGate-Event-Id` for partner-side deduplication.
- Retries: exponential backoff (for example 1m, 5m, 15m, 1h, 6h, 24h). After the last retry the delivery goes to `failed` and is visible to admin/partner with a **manual resend** button.
- Timeout 10 s. Only 2xx counts as success. SSRF protection blocks private/internal IPs in webhook URLs.

---

## 11. Security architecture

### Layers

| Layer       | Controls                                                                                                                                                                                                                  |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Edge        | Cloudflare WAF, DDoS protection, bot management, edge rate limits, TLS 1.2+ only, API hostname can be IP-allow-listed per partner                                                                                         |
| Web server  | HTTPS only, HSTS, security headers (CSP, X-Frame-Options, Referrer-Policy), request size limits                                                                                                                           |
| Application | RBAC with policies on every query, **tenant scoping** (a partner only sees its data, a branch only its accounts), CSRF on portals, validation on every input, Eloquent parameter binding, output escaping                 |
| Portal auth | Separate login areas per role, **2FA (TOTP) mandatory for Admin and Branch** and optional for Partner, login throttling, session timeout, re-auth for sensitive actions (bank detail change, secret rotation, settlement) |
| Data        | Bank account numbers / UPI IDs / secrets are **encrypted at column level** (Laravel encrypted casts, key from env/secret manager). Account numbers are masked in lists. Tokens are stored as hashes only.                 |
| Network     | PostgreSQL/Redis only on the private network, least-privilege DB users, TLS to managed services                                                                                                                           |
| Secrets     | Never in git, JS or logs. `.env` locally, secret manager in production. `APP_KEY` rotation plan.                                                                                                                          |
| Audit       | Append-only `audit_logs` (admin/branch config changes), `security_logs` (failed logins, bad signatures, rate-limit hits), `transaction_events`. These tables are never updated or deleted by the app DB user.             |

### Separation of duties

- Sensitive actions (activate account, change partner fees, record settlement) can require a **second admin approval** (maker–checker). This is phased in.

### Roles & permissions (implemented in Phase 1)

Each user has one **type** (portal) and one **role** within it. Roles and permissions are defined in code (`app/Enums/Role.php`, `app/Enums/Permission.php`), and every permission is a Gate ability (`$user->can('accounts.verify')`). The database rejects a role that doesn't match the user's type.

| Type    | Role                | Can                                                                                    |
| ------- | ------------------- | -------------------------------------------------------------------------------------- |
| Admin   | `admin.super`       | Everything in the admin portal, including Horizon                                      |
| Admin   | `admin.ops`         | View partners/branches, verify & assign accounts, view/resolve transactions, audit log |
| Admin   | `admin.finance`     | View partners/branches/transactions, manage settlements, audit log                     |
| Admin   | `admin.viewer`      | View partners, branches, transactions                                                  |
| Partner | `partner.owner`     | Transactions, API keys, webhooks, partner users                                        |
| Partner | `partner.developer` | Transactions, API keys, webhooks                                                       |
| Partner | `partner.viewer`    | Transactions                                                                           |
| Branch  | `branch.owner`      | Bank/UPI accounts, confirm deposits, branch users                                      |
| Branch  | `branch.operator`   | View accounts, confirm deposits                                                        |

Other Phase 1 rules:

- One login page. After login, each user goes to their own portal (`/admin`, `/partner`, `/branch`) and gets 403 on the others.
- 2FA is mandatory for Admin and Branch users (`PAYGATE_ENFORCE_2FA`). They are redirected to set it up before they can use their portal.
- Inactive users cannot log in, and an active session ends on the next request after deactivation.
- There is no self-registration or self-deletion, and users can change only their own name. Email changes are made by an admin.
- Users are never deleted, only deactivated. The database blocks deleting a user who appears in the logs.
- `audit_logs` and `security_logs` are append-only. A Postgres trigger rejects UPDATE, DELETE and TRUNCATE.
- Every request gets an `X-Request-Id`. It is stored on log rows and passed into queued jobs.

---

## 12. Queues (Horizon)

| Queue           | Jobs                                       | Priority  |
| --------------- | ------------------------------------------ | --------- |
| `webhooks`      | Outbound partner webhooks                  | high      |
| `notifications` | Branch alerts (new submission), email, SMS | high      |
| `default`       | Misc domain jobs                           | medium    |
| `matching`      | Statement import & auto-match              | medium    |
| `reports`       | Exports, analytics, daily summaries        | low       |
| `maintenance`   | Expiry sweeps, usage-row creation, cleanup | scheduled |

- Payment-critical steps (allocate, create session, confirm) are **never** queued. They are synchronous DB transactions.
- Every job is idempotent and safe to run twice. Failed jobs are stored and alerted, never silently dropped.

---

## 13. Data ownership

| Data                                                    | Authority                         |
| ------------------------------------------------------- | --------------------------------- |
| Partners, branches, users, roles                        | PostgreSQL                        |
| Payment accounts, assignments, daily usage/reservations | PostgreSQL                        |
| Sessions, transactions, events, UTRs                    | PostgreSQL                        |
| Ledger, settlements                                     | PostgreSQL                        |
| Idempotency records, webhook outbox                     | PostgreSQL                        |
| Audit / security logs                                   | PostgreSQL (archived to S3 later) |
| Screenshots, KYC docs, statements, exports              | S3 (private, signed URLs)         |
| Queue jobs, cache, rate-limit counters, nonces          | Redis                             |

### Failure behaviour

| Failure          | Behaviour                                                                                                                                              |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Redis down       | Cache misses, queues pause, and outbox rows wait. Rate limiting falls back to Cloudflare. Session creation still works (nonce check falls back to DB). |
| PostgreSQL down  | **Fail closed.** No sessions are created and no account details are shown.                                                                             |
| Worker crash     | Supervisor restarts it. The job is retried, and the outbox guarantees delivery.                                                                        |
| App server crash | Stateless, so another node serves (stage 2+).                                                                                                          |

---

## 14. Compliance & data retention (to review with an advisor)

- The RBI Payment Aggregator / Payment Gateway guidelines and whether this model needs authorisation or must operate under a licensed entity
- KYC of partners and branches (documents stored in S3, encrypted, access-audited)
- AML: velocity limits, suspicious-pattern reports, record retention (typically ≥ 5–10 years for financial records)
- DPDP Act 2023: minimal payer PII, a purpose statement and deletion rules for non-financial data
- Terms/agreements with partners and branches covering who holds the money and settlement timelines

These decisions affect the schema (KYC tables, retention flags), so they are listed as open items in §18.

---

## 15. Observability

- A **request_id** on every request/job/log line/webhook, and a `transaction_id` wherever relevant
- Structured JSON logs, Sentry for errors, Horizon dashboard for queues
- Health: `/up` (liveness), `/ready` (DB reachable + config OK; Redis is **not** required)
- Business alarms: no eligible account for a partner, branch confirmation SLA breached, webhook failure rate, spike in rejected UTRs, daily limit near exhaustion

---

## 16. Deployment & scaling

**Stage 1 (go-live):** 1 app VM (FrankenPHP/Octane + Horizon + scheduler), managed PostgreSQL (with PITR backups), managed Redis, S3, and Cloudflare in front.

**Stage 2:** Load balancer → 2–3 app nodes, dedicated Horizon node(s), PgBouncer/pooled connections, and Redis with a replica.

**Stage 3 (only when metrics prove the need):** read replica for reports, partitioning of `transaction_events`/`ledger_entries` by month, and possibly extraction of reporting.

**Deploy pipeline:** CI (Pint, Larastan, PHPUnit, `composer audit`, frontend build) → staging → approval → production (migrate with expand→migrate→contract, `octane:reload`, `horizon:terminate`, health check).

**Backups:** daily + point-in-time recovery on PostgreSQL and versioned S3. **Restore is tested monthly**, because an untested backup is not a backup.

**Load tests** (k6) before go-live: 100/500/1000 concurrent session creations, including the **hot case** of 1000 requests against a partner with only 5–10 accounts.

---

## 17. Local development environment

Everything runs in Docker, and the host needs only Docker Desktop.

| Service     | Image                             | Purpose             | Local URL / port                                                         |
| ----------- | --------------------------------- | ------------------- | ------------------------------------------------------------------------ |
| `app`       | FrankenPHP (PHP 8.5) custom image | Laravel web         | http://paygate.local, http://api.paygate.local, http://pay.paygate.local |
| `horizon`   | same image                        | Queue workers       | http://paygate.local/horizon                                             |
| `scheduler` | same image                        | `schedule:work`     | —                                                                        |
| `vite`      | same PHP image (has Node 24)      | React/TS hot reload | :5173                                                                    |
| `postgres`  | postgres:18                       | Database            | :5432                                                                    |
| `redis`     | redis:8                           | Queue/cache         | :6379                                                                    |
| `mailpit`   | axllent/mailpit                   | Catches emails      | http://localhost:8025                                                    |

Hostnames (one-time, needs your Mac password):

```bash
sudo sh -c 'echo "127.0.0.1 paygate.local api.paygate.local pay.paygate.local" >> /etc/hosts'
```

Hostname routing inside Laravel:

- `paygate.local` → portals: `/admin`, `/partner`, `/branch`
- `api.paygate.local` → Partner API `/v1/...`
- `pay.paygate.local` → payer pages `/p/{token}`

Day-to-day commands are in the `Makefile` (`make help` lists them) and the root `README.md`.

Notes from Phase 0:

- The PHP image also contains Node, because the Wayfinder Vite plugin runs `php artisan` to regenerate typed routes.
- Tests run against a real PostgreSQL database (`paygate_testing`), not SQLite, because the app relies on PostgreSQL row locking.
- Public self-registration is disabled: admins create every Partner/Branch/Admin user.
- MinIO no longer publishes Docker Hub images; an S3-compatible service will be added when uploads are built (Phase 6).

---

## 18. Decisions

Accepted as proposed on 2026-09-25 (items 6, 7, 10 and 12 still need business input before Phase 8 / go-live).

| #   | Decision                             | Proposal                                                                                                     |
| --- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------ |
| 1   | Primary key type                     | UUIDv7 (Laravel `HasUuids`) for business tables. Public references are human-friendly, e.g. `PG-2609-8F3K2`. |
| 2   | Assignment granularity               | Per **payment account** (many-to-many), with a "assign whole branch" shortcut in the UI                      |
| 3   | Session TTL                          | 15 min default, configurable per partner                                                                     |
| 4   | Amount tagging (paise)               | Off by default, on per partner                                                                               |
| 5   | Signed-redirect integration (no API) | Only if a partner needs it                                                                                   |
| 6   | Who holds/settles money              | Branch → partner directly vs via platform (affects ledger accounts)                                          |
| 7   | Fee model                            | Percentage / flat / slab per partner, and optional branch commission                                         |
| 8   | Payer identity                       | Partner sends `payer_ref` (their user id). We store no payer PII beyond UTR and optional name.               |
| 9   | 2FA for partners                     | Optional initially, mandatory later                                                                          |
| 10  | Hosting                              | Provider choice (AWS Mumbai / DigitalOcean BLR / Hetzner + managed DB) — India region preferred              |
| 11  | SLO targets                          | Session creation p95 < 300 ms, webhook first attempt < 5 s after confirm, 99.9% availability                 |
| 12  | Compliance items                     | §14                                                                                                          |

---

## 19. Implementation roadmap

> **Superseded (2026-09-27):** the current phase plan is [Implementation-Plan.md](Implementation-Plan.md). The table below is the original roadmap, kept for history.

Each phase ends with tests passing, a short demo, and this document updated.

| Phase                                  | Deliverable                                                                                                    |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| **0. Local environment** ✅            | Docker Compose stack, Laravel 13 skeleton, `paygate.local` hosts, Pint/Larastan/PHPUnit, Makefile, CI          |
| **1. Auth & RBAC** ✅                  | Users with type (admin/partner/branch), roles & permissions, 2FA, login areas, audit + security log foundation |
| **2. Admin: partners & branches**      | CRUD, status, users, partner settings                                                                          |
| **3. Branch portal: payment accounts** | Add bank/UPI accounts (encrypted), admin verification, assignments to partners, limits                         |
| **4. Partner API foundation**          | Credentials, HMAC middleware, nonce, idempotency, rate limits, API docs page                                   |
| **5. Payment sessions & allocation**   | Session API, allocation with `SKIP LOCKED`, daily usage, payer page with UPI QR, expiry job                    |
| **6. Confirmation flow**               | UTR submission, branch confirmation queue, state machine, transaction events, disputes, late payments          |
| **7. Outbound webhooks**               | Outbox, Horizon delivery, retries, signing, resend UI, partner test tool                                       |
| **8. Ledger & settlement**             | Double-entry ledger, fee plans, balances, settlements                                                          |
| **9. Reporting & reconciliation**      | Dashboards, exports, statement upload + auto-match, unmatched deposits                                         |
| **10. Hardening & go-live**            | Load tests, security review, backups/restore test, production deploy, monitoring/alerts                        |

---

## 20. Deliberately not building now

Kubernetes, Kafka/event streaming, microservices, separate Laravel apps per role, Redis Cluster, a separate webhook/analytics service. We add any of these only when metrics show the monolith cannot cope, because they cost operations effort without solving a current problem.
