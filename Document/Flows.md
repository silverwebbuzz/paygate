# PayGate — Role-wise Flows

Step-by-step business flows, one per role or process. These sit between [Features.md](Features.md) (what) and the database design (how data is stored).
Feature IDs (e.g. `MAP-05`) link each step back to Features.md, and question IDs (e.g. `Q-MAN-3`) point to its §4.

Terms follow [Features.md §0](Features.md#0-terminology-agreed-2026-09-27): partner = external website, partner customer = the person paying, Gateway = our platform, providers = external gateways such as FFPay.

All 21 business flows at approval level are in [Requirements.md §5](Requirements.md). This file keeps the step-level detail for Flows 1–3.

**Tags on every step:**

- **Confirmed**: seen in the existing system or stated by the client
- **Proposal**: design recommendation, not yet agreed
- **Decide**: business rule still open

**Target format for each step** (filled in progressively):
Actor → Screen → Action → Validation → Business rule → Database effect → Status change → Notification/Webhook → Next step.

| #   | Flow                                      | Status                 |
| --- | ----------------------------------------- | ---------------------- |
| 1   | Admin complete flow                       | 📝 Captured 2026-09-27 |
| 2   | Partner / merchant complete flow          | 📝 Captured 2026-09-27 |
| 3   | Branch complete flow                      | 📝 Captured 2026-09-27 |
| 4   | End-user pay-in flow                      | ⏳                     |
| 5   | Manual deposit flow                       | ⏳                     |
| 6   | A/C statement → UTR → reconciliation flow | ⏳                     |
| 7   | Pay-out flow                              | ⏳                     |
| 8   | Settlement flow                           | ⏳                     |
| 9   | Final transaction / state-machine rules   | ⏳                     |

After these come: database entities & schema → API architecture → queues → security & webhooks → final Laravel structure.

---

## Flow 1 — Admin complete flow

_Source: flow draft shared 2026-09-27._

The Admin is the central control layer. The Admin **configures** and **operates**, but business rules are enforced by the transaction engine, never by a screen writing to a balance directly.

```text
Admin login → Dashboard
  ├── Master configuration: Partners · Branches · Users · Roles & permissions ·
  │                         Bank/payment accounts · Payment gateways · Credentials ·
  │                         Global settings · IP management
  ├── Operations:           Pay-in · Pay-out · Manual payment · A/C statement ·
  │                         UTR/reconciliation · Settlement · Reports
  └── Monitoring / audit
```

### 1.1 Login

| Item   | Detail                                                                                      | Tag                           | Refs                |
| ------ | ------------------------------------------------------------------------------------------- | ----------------------------- | ------------------- |
| Input  | Email/username, password, 2FA                                                               | Confirmed (2FA: see Q-AUTH-1) | AUTH-01/03          |
| Checks | User exists and is active; password correct; role allows admin access; not locked/suspended | Confirmed                     | ✔️ built in Phase 1 |
| Checks | IP / device restriction, if enabled                                                         | Decide                        | AUTH-05, Q-IP-1     |
| Result | Authenticated session → Admin dashboard                                                     | Confirmed                     | ✔️                  |
| Log    | A failed login creates a security event                                                     | Confirmed                     | ✔️ `security_logs`  |

### 1.2 Dashboard

| Item            | Detail                                                                                                                     | Tag                          | Refs                        |
| --------------- | -------------------------------------------------------------------------------------------------------------------------- | ---------------------------- | --------------------------- |
| Pay-in metrics  | Total, successful, pending, failed                                                                                         | Confirmed                    | DSH-01                      |
| Pay-out metrics | Total, successful, pending, failed                                                                                         | Confirmed                    | DSH-05                      |
| Money metrics   | Commission, settlement, current balance, net balance, chargeback, **unsettled amount**                                     | Confirmed (formulas: Decide) | DSH-02/06, Q-SET-1, Q-DSH-1 |
| Filters         | Today, yesterday, 1h, 24h, 7d, 30d, this month, previous month, custom                                                     | Confirmed                    | DSH-03                      |
| Rule            | Dashboard is read-only aggregation. Heavy sums come from cached or pre-aggregated figures, not live scans on every request | Proposal                     | SYS-01                      |

### 1.3 Partner management

| Item              | Detail                                                                                                                                                                                                                                                                                                         | Tag                                       | Refs          |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------- | ------------- |
| Fields            | Name, email, description, partner code, return URL, pay-in callback URL, pay-in webhook URL, payout webhook URL, allowed IPs, API version (e.g. V2), manual payment type (e.g. Dynamic QR), QR code / UPI / bank details toggles, deposit + withdrawal commission, theme, logo, payout limit type, active, H2H | Confirmed                                 | PTR-02…08     |
| Withdrawal config | Withdraw URL, group name, min amount, max amount, auto withdrawal, partial withdrawal                                                                                                                                                                                                                          | Confirmed                                 | PTR-07        |
| On create         | System generates the partner code and secret key                                                                                                                                                                                                                                                               | Confirmed                                 | PTR-10        |
| Secret storage    | "Store a hash, not plaintext; provide regeneration/rotation"                                                                                                                                                                                                                                                   | Proposal, **conflicts** with HMAC signing | C-01, Q-SEC-1 |

### 1.4 Partner ↔ branch mapping

| Item    | Detail                                                                                                             | Tag                    | Refs               |
| ------- | ------------------------------------------------------------------------------------------------------------------ | ---------------------- | ------------------ |
| Actions | Assign branch, remove branch, activate/deactivate mapping                                                          | Confirmed              | MAP-01, MAP-05     |
| Options | Priority/order per mapping; transaction limits per mapping                                                         | Decide ("if required") | MAP-06/07, Q-MAP-3 |
| Used by | Allocation: payment request → partner → eligible branches → eligible bank/UPI accounts → allocation → payment page | Confirmed              | ALC-01             |

### 1.5 Branch management

| Item    | Detail                                                                                                                                                                    | Tag       | Refs              |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- | ----------------- |
| Fields  | Name, limit type, deposit limit, deposit commission, withdrawal limit, withdrawal commission, min/max withdrawal per transaction, **Is Deposit**, **Is Withdraw**, active | Confirmed | BRN-02…06, BRN-08 |
| Concept | A branch is a business entity, not a bank account. It owns users, banks, UPI accounts, deposit + withdrawal config, partner mappings and transaction activity             | Confirmed |                   |

### 1.6 Branch users

| Item                  | Detail                                                                                                           | Tag       | Refs           |
| --------------------- | ---------------------------------------------------------------------------------------------------------------- | --------- | -------------- |
| Structure             | Branch → branch admin → branch operators                                                                         | Confirmed | USR-05, USR-08 |
| Example: branch admin | Dashboard, manual payment, manual deposit, manual payout, A/C statement, UTR reconciliation, reports, settlement | Proposal  |                |
| Example: operator     | Manual deposit, view transactions, upload statement                                                              | Proposal  |                |
| Rule                  | Access comes from roles + permissions, never hard-coded in controllers                                           | Confirmed | ROLE-01        |

### 1.7 Roles & permissions

| Item             | Detail                                                                                                                                                          | Tag       | Refs          |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- | ------------- |
| Model            | Role → permissions → users; Admin manages roles                                                                                                                 | Confirmed | ROLE-01/02    |
| Permission style | Granular `module.action`, e.g. `partner.view/create/update/delete`, `branch.view/create/update`, `manual_deposit.view/approve/reject`, `settlement.view/create` | Proposal  | ROLE-04, C-03 |

### 1.8 Bank / payment accounts

| Item      | Detail                                                                                                                                                                                             | Tag       | Refs              |
| --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- | ----------------- |
| Who       | Admin can manage accounts belonging to branches (in addition to branches adding their own)                                                                                                         | Confirmed | ACC-07, ACC-10    |
| Fields    | Bank name, holder, account number, IFSC, UPI ID, **UPI code**, deposit limit, daily limit, status, verification status, payment method, **Dynamic QR available**, **UPI intent available**, active | Confirmed | ACC-01…06, ACC-08 |
| Lifecycle | Created → verification → verified → active → eligible for allocation. Creating an account never makes it usable by itself                                                                          | Confirmed | ACC-09            |

### 1.9 Payment gateways

| Item             | Detail                                                                                                                                           | Tag       | Refs            |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | --------- | --------------- |
| Fields           | Name, code, type, credentials, status, configuration                                                                                             | Confirmed | PGW-01…03       |
| Existing entries | **FFPay**, **Payin Self**, **Payout Self**                                                                                                       | Confirmed | PGW-04, Q-PGW-2 |
| Design           | Provider-agnostic interface with one adapter per provider (FFPay, self pay-in, self payout), so new providers don't touch the transaction engine | Proposal  | PGW-05          |

### 1.10 Manual payment methods

| Item    | Detail                                                                                                                    | Tag       | Refs   |
| ------- | ------------------------------------------------------------------------------------------------------------------------- | --------- | ------ |
| Methods | Bank account, UPI, Dynamic QR, UPI intent                                                                                 | Confirmed | PMT-01 |
| Rule    | Methods shown = partner config ∩ branch config ∩ account config ∩ account status. The page never shows everything blindly | Confirmed | PMT-03 |

### 1.11 Pay-in monitoring

| Item       | Detail                                                                                                                                                | Tag                              | Refs          |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------- | ------------- |
| Lifecycle  | Partner API → payment request → session → branch/account allocation → customer pays → bank statement / UTR → reconciliation → approval → SUCCESS      | Confirmed (who approves: Decide) | IN-01, Q-IN-2 |
| Admin list | Transaction ID, partner, branch, customer, amount, method, assigned account, UTR, status, created, completed, failure reason, callback/webhook status | Confirmed                        | IN-03         |

### 1.12 Manual deposit approval

| Item       | Detail                                                                                                           | Tag                          | Refs               |
| ---------- | ---------------------------------------------------------------------------------------------------------------- | ---------------------------- | ------------------ |
| Flow       | Customer/partner request → manual deposit → pending → branch/admin review → UTR/proof check → approve/reject     | Confirmed (reviewer: Decide) | MAN-02/03, Q-MAN-3 |
| States     | CREATED, PENDING, PAYMENT_PENDING, HOLD, APPROVED, DECLINED                                                      | Proposal                     | MAN-07             |
| On approve | Transaction → balance update → commission → partner/branch ledger → webhook → audit, as **one atomic operation** | Confirmed                    | MAN-08             |

### 1.13 A/C statements & 1.14 UTR reconciliation

| Item           | Detail                                                                                                  | Tag       | Refs   |
| -------------- | ------------------------------------------------------------------------------------------------------- | --------- | ------ |
| Statement flow | Upload/import → parse → validate → store entries → match UTR → match branch → match payment → reconcile | Confirmed | STM-02 |
| Rule           | A bank-statement entry is a separate record from a payment transaction and is linked to it later        | Confirmed | STM-05 |
| Matching       | UTR → find payment request → verify amount → verify account → verify branch → reconcile                 | Confirmed | REC-02 |
| States         | UNMATCHED, MATCHED, PENDING_REVIEW, RECONCILED, REJECTED                                                | Proposal  | REC-05 |

### 1.15 Pay-out monitoring

| Item       | Detail                                                                                                                                    | Tag                              | Refs            |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------- | --------------- |
| Flow       | Partner → payout request → validation → **balance check** → branch or provider allocation → processing → bank/provider → SUCCESS / FAILED | Confirmed (routing rule: Decide) | OUT-07, Q-OUT-4 |
| Admin list | Partner, amount, beneficiary, transaction ID, provider, branch, status, failure reason, UTR, created, completed                           | Confirmed                        | OUT-07          |

### 1.16 Settlement, 1.17 Reports, 1.18 Commission

| Item       | Detail                                                                                                                                                                                                 | Tag       | Refs              |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------- | ----------------- |
| Settlement | Transactions → gross → commission → charges → adjustments → net → settlement record → report                                                                                                           | Confirmed | SET-01…04         |
| Rule       | Settlement never changes transaction amounts; it has its own records and ledger references                                                                                                             | Confirmed | SET-05            |
| Reports    | Deposit (branch, partner, branch-partner, pay-in) · Withdrawal (branch-wise, partner-wise, branch-partner-wise, pay-out) · Settlement (admin, branch, partner) · **Balance (branch, payout, partner)** | Confirmed | RPT-01…05, RPT-07 |
| Commission | At partner and branch level, feeding settlement. Rules open (see Q-COM-1)                                                                                                                              | Decide    | COM-01/02         |

### 1.19 Webhooks & 1.20 Audit

| Item           | Detail                                                                                                                                                           | Tag       | Refs                      |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- | ------------------------- |
| Webhooks       | Async: transaction → outbox/event → Redis queue → Horizon worker → partner webhook → retry. The customer never waits for the partner's server                    | Confirmed | WHK-01/02                 |
| Audit examples | Deposit approved/rejected; partner commission changed; branch mapping changed; bank account changed; secret regenerated; permissions changed; settlement changed | Confirmed | LOG-03                    |
| Audit fields   | Actor, action, entity, entity ID, before, after, IP, user agent, timestamp, request ID                                                                           | Confirmed | ✔️ `audit_logs` (Phase 1) |

### 1.21 Principle: admin actions go through the engine

```text
Admin action → authorization → domain service → validation → DB transaction
  → ledger → balance projection → event → webhook/notification → audit
```

An "Approve" button never does `$balance += $amount`. Tag: **Confirmed**, and consistent with Architecture.md §8–9.

---

## Flow 2 — Partner / merchant complete flow

_Source: flow draft shared 2026-09-27 (received after Flow 3)._

Seen from the **partner's external website**. The partner owns the customer-facing site, and our Gateway provides payments.

**Ownership model (Confirmed; the foundation for API and database design):**

| Party                | Owns                                                                                                                                           |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| **Partner**          | Its website, its customers, the customer's wallet/order/account, its business logic                                                            |
| **Gateway** (us)     | Payment transaction, payment session, payment URL, branch allocation, bank/UPI account allocation, reconciliation, webhooks, gateway reporting |
| **Partner customer** | Nothing with us; uses our payment page to pay                                                                                                  |

```text
Partner backend ─1 create payment─▶ Gateway API ─2 validate ─3 create transaction ─4 payment URL─▶ Partner
Partner ─5 redirect─▶ Customer ─6 opens URL─▶ Our payment page ─7 picks method─▶ Bank / UPI / QR
Customer ─8 pays─▶ Gateway ─9 verify / reconcile─▶ SUCCESS / FAILED / PENDING ─▶ partner webhook + customer return URL
```

### 2.1 Onboarding

| Item                 | Detail                                                                                                                                                                                        | Tag       | Refs      |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- | --------- |
| Who                  | Admin creates the partner (e.g. `ABC Website`, code `ABC001`)                                                                                                                                 | Confirmed | PTR-02    |
| Generated/configured | Partner ID, partner code, API credentials, secret key, API version, allowed IPs, return URL, pay-in callback URL, pay-in webhook URL, payout webhook URL, enabled methods, commission, status | Confirmed | PTR-02…10 |
| Rule                 | The partner calls our API **from its backend only**. The secret never reaches the partner's frontend or the customer's browser                                                                | Confirmed | API-05    |

### 2.2 Create pay-in request

| Item     | Detail                                                                                                                                | Tag                       | Refs                     |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------- | ------------------------- | ------------------------ |
| Endpoint | `POST /api/v2/payments` (illustrative)                                                                                                | Decide                    | API-02, C-09, Q-API-1    |
| Fields   | `partner_transaction_id`, `amount`, `customer_id`, `customer_name`, `customer_email`, `customer_mobile`, `return_url`, `callback_url` | Proposal (contract later) | API-06, Q-CUS-1, Q-API-4 |
| Optional | Currency, description, order ID, customer IP, customer metadata, expiry time, preferred method                                        | Proposal                  | API-06                   |

### 2.3 Authentication & idempotency

| Item        | Detail                                                                                                                         | Tag       | Refs                  |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------ | --------- | --------------------- |
| Checks      | Partner code → API credential → signature/secret → allowed IP → partner active → API version valid                             | Confirmed | API-01, API-03        |
| Errors      | 400 / 401 / 403 depending on the failure                                                                                       | Confirmed | API-09                |
| Idempotency | A repeated `partner_transaction_id` (e.g. network retry) returns the **existing** transaction instead of creating a second one | Confirmed | API-07, C-07, Q-API-5 |

### 2.4 Transaction, session, response

| Item          | Detail                                                                                                                      | Tag                        | Refs        |
| ------------- | --------------------------------------------------------------------------------------------------------------------------- | -------------------------- | ----------- |
| IDs           | Keep **both** the partner transaction ID (`ABC123`) and our gateway transaction ID (e.g. `PGW-20260925-000001`) for tracing | Confirmed (format: Decide) | IN-04, C-08 |
| Session & URL | Transaction → payment session → URL like `/pay/{secure-token}`. Token unique, unpredictable, expiring, no internal IDs      | Confirmed                  | SES-01/02   |
| Response      | `success`, `transaction_id`, `payment_url`, `status: PENDING`                                                               | Proposal                   | SES-08      |

### 2.5 Payment page & allocation

| Item       | Detail                                                                                                                                 | Tag                        | Refs           |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------- | -------------------------- | -------------- |
| Page       | Shows partner name, amount, and method choice: UPI / QR / bank transfer                                                                | Confirmed                  | SES-03, SES-09 |
| Options    | Depend on the partner's configuration **and** the currently eligible branch/bank accounts                                              | Confirmed                  | PMT-03         |
| Allocation | Partner → mapped branches → active branches → eligible bank/UPI accounts → limits/availability → allocation engine → one account shown | Confirmed (timing: Decide) | ALC-01, C-06   |
| Payment    | Customer pays from their own bank/UPI; the Gateway then detects it via bank statement → UTR → matching → reconciliation (Flow 6)       | Confirmed                  | REC-02         |

### 2.6 Transaction states

| Item       | Detail                                                                         | Tag                         | Refs            |
| ---------- | ------------------------------------------------------------------------------ | --------------------------- | --------------- |
| Main path  | CREATED → PENDING → PAYMENT_DETECTED → UNDER_REVIEW / RECONCILIATION → SUCCESS | Proposal ("finalize later") | IN-02, C-10     |
| Alternates | PENDING → EXPIRED / CANCELLED / FAILED; PAYMENT_DETECTED → REJECTED            | Proposal                    | IN-02           |
| Rule       | A proper state machine, never arbitrary status changes                         | Confirmed                   | Architecture §8 |

### 2.7 Webhooks, return URL, status API

| Item            | Detail                                                                                                                                                                  | Tag                           | Refs           |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------- | -------------- |
| Webhook         | Server-to-server on important state changes via queue. Example payload: `event: payment.success`, `transaction_id`, `partner_transaction_id`, `amount`, `status`, `utr` | Confirmed (payload: Proposal) | WHK-01         |
| Signed          | The partner must be able to verify the notification really came from us                                                                                                 | Confirmed                     | WHK-01, SEC-03 |
| Retry & history | Partner returns 500 → retry queue → retries. Keep history: event created, each attempt, response, final status                                                          | Confirmed                     | WHK-02, WHK-03 |
| Return URL      | Customer is redirected back to the partner for UX only. The partner must **not** trust the redirect as proof of payment                                                 | Confirmed                     | WHK-04         |
| Status API      | `GET /api/v2/payments/{transaction}` for missed or delayed webhooks and for partner reconciliation                                                                      | Confirmed                     | API-08         |

### 2.8 Partner reports

| Item       | Detail                                                                                       | Tag       | Refs   |
| ---------- | -------------------------------------------------------------------------------------------- | --------- | ------ |
| Pay-in     | Transaction ID, partner order ID, customer, amount, method, status, UTR, date/time           | Confirmed | RPT-08 |
| Pay-out    | Payout ID, amount, beneficiary, status, UTR, date/time                                       | Confirmed | RPT-08 |
| Settlement | Gross, commission, charges, net, settlement status                                           | Confirmed | RPT-08 |
| Scope      | A partner never sees another partner's data; enforced in the backend query, not the frontend | Confirmed | SEC-05 |

---

## Flow 3 — Branch complete flow

_Source: flow draft shared 2026-09-27._

A branch is an **internal operational payment entity**. It operates the bank/UPI accounts used for partner-customer payments and handles manual deposits, statements, UTR matching and branch reporting.

**Ownership rule (Confirmed):** the **partner** owns the customer relationship, the **Gateway** owns the payment transaction, and the **branch** provides and operates the payment infrastructure. A transaction therefore references `partner_id`, `branch_id`, the customer reference and `payment_account_id`. The branch never talks to the partner's website; the Gateway's allocation engine sits in between.

### 3.1 Branch ↔ partner relationship

| Item           | Detail                                                                                                                      | Tag       | Refs                              |
| -------------- | --------------------------------------------------------------------------------------------------------------------------- | --------- | --------------------------------- |
| Cardinality    | **Many-to-many**: a branch can serve several partners, and a partner can use several branches, via a partner↔branch mapping | Confirmed | MAP-01, Q-MAP-1 (partly answered) |
| Mapping status | Each mapping has its own status. A disabled mapping means the allocation engine must not pick that branch for that partner  | Confirmed | MAP-05                            |

### 3.2 Admin creates the branch

| Item   | Detail                                                                                                                                                               | Tag       | Refs              |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- | ----------------- |
| Steps  | Create branch (e.g. `BRANCH-001`) → configure limits → configure deposit/withdrawal → activate                                                                       | Confirmed | BRN-02            |
| Fields | Name, deposit enabled, withdrawal enabled, deposit limit, withdrawal limit, deposit commission, withdrawal commission, min/max withdrawal, branch limit type, active | Confirmed | BRN-02…06, BRN-08 |

### 3.3 Branch users

| Item          | Detail                                                                                                                 | Tag       | Refs                                               |
| ------------- | ---------------------------------------------------------------------------------------------------------------------- | --------- | -------------------------------------------------- |
| Who creates   | Admin creates users belonging to the branch                                                                            | Confirmed | USR-05; self-service by branch still open (USR-08) |
| Example roles | Branch Admin (approve/reject manual deposits), Deposit Operator, Statement Operator (import statements, view matching) | Proposal  | ROLE-01                                            |
| Rule          | Permissions come from roles, never hard-coded                                                                          | Confirmed | ROLE-01, C-03                                      |

### 3.4 Payment accounts & verification

| Item                | Detail                                                                                                                      | Tag       | Refs             |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------- | --------- | ---------------- |
| Bank account        | Bank name, holder, account number, IFSC, deposit limit, daily limit, status, verification status                            | Confirmed | ACC-01, ACC-03   |
| UPI                 | UPI ID, merchant/display name, UPI code/reference, daily limit, status, verification status, intent available, QR available | Confirmed | ACC-04, ACC-08   |
| Verification states | NEW → VERIFICATION_PENDING → VERIFIED → ACTIVE, or VERIFICATION_PENDING → REJECTED                                          | Confirmed | ACC-09           |
| Rule                | Only **VERIFIED + ACTIVE** accounts are eligible for allocation                                                             | Confirmed | ACC-09           |
| Not mentioned here  | Architecture §5.1 also proposes branch-initiated **pause** and re-verification after bank-detail changes                    | —         | for gap analysis |

### 3.5 Eligibility & allocation

| Item               | Detail                                                                                                                                                     | Tag                                                 | Refs             |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- | ---------------- |
| Eligibility chain  | Account verified → account active → branch active → partner mapped → partner payment method enabled → account limit available → daily limit available      | Confirmed                                           | ALC-01           |
| Not mentioned here | Architecture §6 also checks mapping active, branch deposit-enabled flag, branch deposit limit, and a cap on open sessions per account                      | —                                                   | for gap analysis |
| Example            | Partner ABC, customer pays ₹5,000 → eligible branches → Branch 001 → eligible accounts → Bank Account A shown                                              | Confirmed                                           | SES-03           |
| Strategy           | **Configurable**, not hard-coded random: RANDOM, ROUND_ROBIN, WEIGHTED, PRIORITY, LIMIT_BASED (e.g. choose using remaining limit A ₹50k / B ₹20k / C ₹80k) | Confirmed (configurable); default and scope: Decide | ALC-02, Q-ALC-1  |

### 3.6 Manual deposit: review, approve, reject

| Item             | Detail                                                                                                                                                                                                   | Tag       | Refs                                                        |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- | ----------------------------------------------------------- |
| Lifecycle        | Request → manual deposit created → PENDING → **branch reviews** UTR / proof → approve or reject                                                                                                          | Confirmed | MAN-02/03, Q-MAN-3 (branch approves; Admin role still open) |
| List shows       | Created, pending, approved, payment pending/hold, declined, UTR, transaction ID, username/customer, amount, bank details, proof, created time                                                            | Confirmed | MAN-01                                                      |
| Approve (atomic) | BEGIN → still pending? → authorized? → amount ok? → UTR ok? → mark approved → ledger entries → balance projection → commission → webhook event → audit event → COMMIT. Any failure rolls back everything | Confirmed | MAN-08                                                      |
| Reject           | PENDING → DECLINED; store reason, rejected by, rejected at, previous status, transaction ID, UTR, audit record                                                                                           | Confirmed | MAN-09                                                      |
| Reject reasons   | Invalid UTR, wrong amount, duplicate payment, payment not found, invalid proof, other. The list is configurable                                                                                          | Proposal  | MAN-09                                                      |
| Payment proof    | Viewable by authorized users only, never via a public or predictable URL                                                                                                                                 | Confirmed | REC-04                                                      |

### 3.7 Statement import, matching & unsettled UTR

| Item              | Detail                                                                                                                                                                            | Tag       | Refs                                                    |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- | ------------------------------------------------------- |
| Import            | Upload → parse → validate → store; each line becomes its own statement-entry record                                                                                               | Confirmed | STM-02, STM-05                                          |
| Match             | Bank entry (credit ₹5,000, UTR 123456789, date) ↔ transaction (amount, UTR, branch). Matched entries are linked; unmatched ones go to manual review                               | Confirmed | REC-02, Q-REC-1 (UTR + amount + branch; date role open) |
| Branch match      | Compare the **bank-entry branch** (whose statement it came from) with the **transaction branch** (whose account was allocated). A mismatch → "Branch match: NO" → unsettled queue | Confirmed | MAN-06, REC-01                                          |
| Unsettled UTR     | An **operational queue** (not a transaction status) for entries that didn't match cleanly                                                                                         | Confirmed | REC-06                                                  |
| Unsettled reasons | UTR doesn't match, amount mismatch, wrong branch, duplicate UTR, transaction not found, **bank entry arrived before the transaction**, manual review required                     | Confirmed | REC-03                                                  |

### 3.8 Branch dashboard, reports, data scope

| Item       | Detail                                                                                                                                                                                                                       | Tag                                   | Refs   |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------- | ------ |
| Dashboard  | Pay-in and pay-out (total / success / pending / failed); pending deposits, pending payouts, unsettled UTRs; branch balance, deposit total, withdrawal total, commission, settlement, net balance; same date filters as Admin | Proposal (scope matches reference UI) | DSH-07 |
| Reports    | Branch deposit, branch withdraw, pay-in history/report, pay-out history/report, branch balance, payout balance, settlement                                                                                                   | Confirmed                             | RPT-03 |
| Data scope | The **backend** restricts every query to the user's own branch; hiding things in the UI is not enough                                                                                                                        | Confirmed                             | SEC-05 |

### 3.9 Settlement, payouts, limits

| Item                 | Detail                                                                                                                                                                  | Tag       | Refs              |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- | ----------------- |
| Settlement           | Branch transactions → gross → commission → charges/adjustments → net → settlement, recorded separately. Transaction, ledger and settlement stay separate concepts       | Confirmed | SET-02, SET-05    |
| Payout               | Partner payout request → Gateway → eligible branch → branch processes → bank/provider → SUCCESS/FAILED                                                                  | Confirmed | OUT-08, Q-OUT-1   |
| Payout eligibility   | Branch max withdrawal limit, min/max per transaction, withdrawal commission, active                                                                                     | Confirmed | OUT-08, BRN-06    |
| Three kinds of limit | **Per-transaction** (e.g. ₹1,000–₹50,000), **daily volume** (e.g. ₹10,00,000/day), **available balance/capacity**. These are different things and must not be conflated | Confirmed | BRN-09, Q-BRN-1/2 |
