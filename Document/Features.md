# PayGate — Feature Requirements

The single list of **what** PayGate must do. [Architecture.md](Architecture.md) covers **how**.
**Database design starts only after this list and the role-wise flows (§5) are agreed.**

|              |                                                                                                                                                                                                                                                                                                                                    |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Version      | v0.5 — detailed inventory. The **approval baseline** is now [Requirements.md](Requirements.md) v1                                                                                                                                                                                                                                  |
| Last updated | 2026-09-27 (v0.5)                                                                                                                                                                                                                                                                                                                  |
| Sources      | (1) Original project brief, 2026-09-25 · (2) 4 videos + 2 screenshot ZIPs of the reference UI → Feature List v0.1 · (3) Flow 1 Admin draft, 2026-09-27 → [Flows.md](Flows.md) · (4) Terminology clarification, 2026-09-27 → §0 · (5) Flow 3 Branch draft, 2026-09-27 · (6) Flow 2 Partner draft, 2026-09-27 → [Flows.md](Flows.md) |

### How to read this

- **IDs** (e.g. `PTR-07`) are permanent. Use them in discussions, commits and tests. New items take the next number, and removed items are ~~struck through~~ rather than deleted.
- **Certainty** of each feature:
    - **A**: clearly visible in the reference UI/videos
    - **B**: from the original project brief
    - **C**: indicated, but the business rules still need confirming before development
- **Status:** 📝 Captured · ❓ Needs clarification · ✅ Agreed · 🚧 In progress · ✔️ Done

---

## 0. Terminology (agreed 2026-09-27)

Use these terms everywhere: database, API names, webhooks, reports, UI and code.

| Term                                     | Meaning                                                                                              | Not to be confused with                                      |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| **Admin**                                | Our platform's operators                                                                             |                                                              |
| **Partner** (merchant)                   | An **external website/business** that integrates with us through the API, e.g. `examplemerchant.com` | The person paying: a partner never pays in, its customers do |
| **Partner customer** (end user)          | The partner's customer who actually makes the payment, e.g. topping up ₹5,000 on the partner's site  | Our users: customers have **no login** with us               |
| **Gateway**                              | **Our platform**                                                                                     | External providers (see C-05)                                |
| **Branch**                               | An operational entity inside our platform that provides payment accounts                             | A bank branch or a single bank account                       |
| **Payment account** (bank account / UPI) | The actual receiving instrument owned by a branch                                                    |                                                              |

**"Partner pay-in"** means a pay-in made by a **partner's customer** through the partner's website, never money paid by the partner itself.

**Ownership chain of a pay-in:**

```text
Partner → Partner customer → Payment transaction → Assigned branch → Assigned bank/UPI account
```

**End-to-end example:**

```text
Customer on examplemerchant.com clicks "Add ₹5,000"
  → examplemerchant.com calls our API
  → Gateway creates the payment transaction and returns a payment URL
  → customer opens our payment page and sees the bank / UPI / QR details we allocated
  → customer pays from their bank app
  → Gateway verifies / reconciles the payment → SUCCESS
  → webhook to examplemerchant.com
  → examplemerchant.com credits the customer's wallet on its own side
```

Architecture.md calls the partner customer the "payer". Both terms mean the same person, and "partner customer" is the canonical term from now on.

---

## 1. Module map

| #   | Module                    | Code | #     | Module                                                 | Code |
| --- | ------------------------- | ---- | ----- | ------------------------------------------------------ | ---- |
| 01  | Authentication            | AUTH | 22    | Bank / A/C statements                                  | STM  |
| 02  | Dashboard                 | DSH  | 23    | Auto statement import                                  | STM  |
| 03  | Users                     | USR  | 24    | UTR / reconciliation                                   | REC  |
| 04  | Roles & permissions       | ROLE | 25    | Pay-out                                                | OUT  |
| 05  | Partners                  | PTR  | 26    | Manual payout                                          | OUT  |
| 06  | Partner API               | API  | 27    | Payout balance                                         | OUT  |
| 07  | Partner ↔ branch mapping  | MAP  | 28    | Refunds                                                | RCB  |
| 08  | Branches                  | BRN  | 29    | Chargebacks                                            | RCB  |
| 09  | Branch users              | USR  | 30    | Commissions                                            | COM  |
| 10  | Branch map                | MAP  | 31    | Settlement                                             | SET  |
| 11  | Bank accounts             | ACC  | 32–36 | Reports (partner, branch, pay-in, pay-out, settlement) | RPT  |
| 12  | UPI accounts              | ACC  | 37    | Alert notifications                                    | NTF  |
| 13  | Payment gateways          | PGW  | 38    | Pages / CMS                                            | CMS  |
| 14  | Credentials               | PGW  | 39    | Global settings                                        | CFG  |
| 15  | Payment methods           | PMT  | 40    | IP management                                          | IP   |
| 16  | Payment sessions          | SES  | 41    | Audit / activity logs                                  | LOG  |
| 17  | Account allocation engine | ALC  | 42    | System / infrastructure                                | SYS  |
| 18  | Pay-in                    | IN   | —     | Webhooks                                               | WHK  |
| 19  | Manual payment            | MAN  | —     | Search, filtering, export                              | UX   |
| 20  | Manual deposit            | MAN  | —     | Security                                               | SEC  |
| 21  | Manual deposit unsettled  | MAN  |       |                                                        |      |

---

## 2. Features by module

### AUTH — Authentication & access

| ID      | Feature                                                       | Cert. | Status | Notes                                                       |
| ------- | ------------------------------------------------------------- | ----- | ------ | ----------------------------------------------------------- |
| AUTH-01 | Login for Admin, Partner and Branch users (username/password) | A     | ✔️     | Phase 1: one login page, each user goes to their own portal |
| AUTH-02 | Remember-me, logout, session expiry                           | A     | ✔️     | Phase 1                                                     |
| AUTH-03 | Two-factor authentication (mandatory for Admin/Branch)        | B     | ✔️     | Phase 1                                                     |
| AUTH-04 | Login/security log (failed logins, lockouts, 2FA failures)    | B     | ✔️     | Phase 1                                                     |
| AUTH-05 | IP / device restriction on admin login, if enabled            | C     | ❓     | Source 3. Q-IP-1                                            |

### USR — Users (admin, partner and branch users)

| ID     | Feature                                    | Cert. | Status | Notes                                                 |
| ------ | ------------------------------------------ | ----- | ------ | ----------------------------------------------------- |
| USR-01 | User list with search and pagination       | A     | 📝     |                                                       |
| USR-02 | Create / edit user                         | A     | 📝     | Invite by email, and the user sets their own password |
| USR-03 | Activate / deactivate user                 | A     | 📝     | Suspend is already built (Phase 1)                    |
| USR-04 | Assign role                                | A     | 📝     |                                                       |
| USR-05 | Assign user to a partner or branch         | A     | 📝     |                                                       |
| USR-06 | User-specific permissions (on top of role) | A     | ❓     | Q-ROLE-2                                              |
| USR-07 | User activity / history                    | A     | 📝     | From audit + security logs                            |
| USR-08 | Branch creates its own branch admin/users  | A     | ❓     | Can branch owners create users, or only Admin?        |

### ROLE — Roles & permissions

| ID      | Feature                                                                                                                                                  | Cert. | Status | Notes                                                                     |
| ------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | ------------------------------------------------------------------------- |
| ROLE-01 | Role list, create, edit, activate/deactivate, delete                                                                                                     | A     | 📝     | Source 3 confirms Admin manages roles. **Impacts Phase 1**, see §3 item 3 |
| ROLE-02 | Assign permissions to a role (module-level + action-level)                                                                                               | A     | ❓     |                                                                           |
| ROLE-03 | Permission areas: Pay-in, Pay-out, Manual payment, Manual deposit, Manual payout, Reports, Settlement, Branch mgmt, Partner mgmt, Account/statement mgmt | A     | 📝     |                                                                           |
| ROLE-04 | Granular `module.action` permission names (e.g. `manual_deposit.approve`)                                                                                | —     | ❓     | Source 3, proposal. C-03                                                  |

### DSH — Admin dashboard

| ID     | Feature                                                                                                                                                               | Cert. | Status | Notes                                      |
| ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | ------------------------------------------ |
| DSH-01 | Counts: total / successful / failed / pending transactions                                                                                                            | A     | 📝     |                                            |
| DSH-02 | Amounts: pay-in, pay-out, deposit, withdrawal, commission, settlement, chargeback, current balance, net balance                                                       | A     | ❓     | Formulas: Q-SET-1                          |
| DSH-03 | Date filters: 1h, 24h, 7d, 30d, today, yesterday, this month, previous month, custom range                                                                            | A     | 📝     | Day boundary = Asia/Kolkata                |
| DSH-04 | Partner and Branch dashboards (same figures scoped to own data)                                                                                                       | C     | ❓     | Implied by partner/branch settlement views |
| DSH-05 | Pay-out counts: total / successful / pending / failed                                                                                                                 | A     | 📝     | Source 3                                   |
| DSH-06 | "Unsettled amount" metric                                                                                                                                             | A     | ❓     | Source 3. Q-DSH-1                          |
| DSH-07 | Branch dashboard: own pay-in/pay-out counts, pending deposits/payouts, unsettled UTRs, branch balance, deposit/withdrawal totals, commission, settlement, net balance | A/C   | 📝     | Source 5                                   |

### PTR — Partners (merchants)

| ID     | Feature                                                                                                         | Cert. | Status | Notes                                             |
| ------ | --------------------------------------------------------------------------------------------------------------- | ----- | ------ | ------------------------------------------------- |
| PTR-01 | Partner list: search, pagination, status, edit, deactivate                                                      | A     | 📝     | Deactivate rather than delete (financial history) |
| PTR-02 | Basic: name, email, description, partner code, return URL                                                       | A     | 📝     |                                                   |
| PTR-03 | Integration: pay-in callback URL, pay-in webhook URL, pay-out webhook URL, allowed IPs, API version, secret key | A     | 📝     |                                                   |
| PTR-04 | Payment configuration: manual payment type, enabled methods (Dynamic QR / UPI / Bank details / QR code)         | A     | ❓     | Q-PMT-1                                           |
| PTR-05 | Commission: deposit commission, withdrawal commission                                                           | A     | ❓     | Q-COM-1                                           |
| PTR-06 | Theme/branding for the payment page: theme type, logo                                                           | A     | 📝     |                                                   |
| PTR-07 | Pay-out config: pay-out URL, group, min/max amount, auto withdrawal, partial withdrawal, pay-out limit type     | A     | ❓     | Q-OUT-2                                           |
| PTR-08 | Toggles: active, pay-in enabled, pay-out enabled, H2H enabled                                                   | A     | ❓     | Q-API-2 (H2H)                                     |
| PTR-09 | Partner can have multiple users                                                                                 | B     | 📝     | Roles owner/developer/viewer exist (Phase 1)      |
| PTR-10 | On create: generate partner code + secret key; secret regeneration/rotation (audited)                           | A     | ❓     | Source 3. Storage method conflicts: C-01          |

### MAP — Partner ↔ branch mapping, branch map

| ID     | Feature                                                                                               | Cert. | Status | Notes                                                         |
| ------ | ----------------------------------------------------------------------------------------------------- | ----- | ------ | ------------------------------------------------------------- |
| MAP-01 | View a partner's branches; assign / remove a branch. Partner↔branch is **many-to-many**               | A     | ✅     | Source 5 confirms many-to-many. Core input to allocation      |
| MAP-02 | Partner-specific branch pool with assignment status                                                   | A     | 📝     |                                                               |
| MAP-03 | Branch-level vs account-level assignment                                                              | B     | ❓     | Q-MAP-1                                                       |
| MAP-04 | "Branch Map" screen (location / map selection)                                                        | A     | ❓     | Q-MAP-2: is this a geographic map or just the mapping screen? |
| MAP-05 | Activate / deactivate a mapping (without removing it); a disabled mapping is excluded from allocation | A     | ✅     | Sources 3, 5                                                  |
| MAP-06 | Priority / order per partner↔branch mapping                                                           | C     | ❓     | Source 3 ("if required"). Q-MAP-3                             |
| MAP-07 | Transaction limits per mapping                                                                        | C     | ❓     | Source 3 ("if required"). Q-MAP-3                             |

### BRN — Branches

| ID     | Feature                                                                                                                                                                                                                                            | Cert. | Status | Notes                              |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | ---------------------------------- |
| BRN-01 | Branch list: name, active bank count, deposit max limit, deposit commission, total deposits (date range), withdrawal max limit, withdrawal commission, withdrawal per-transaction limits, total withdrawals, partner mapping, branch admin, status | A     | 📝     |                                    |
| BRN-02 | Create/edit branch: name, status                                                                                                                                                                                                                   | A     | 📝     |                                    |
| BRN-03 | Branch limit type: "Deposit limit" / "Assign top-up balance"                                                                                                                                                                                       | A     | ❓     | Q-BRN-1, **key for ledger design** |
| BRN-04 | Balance behaviour: deposit affects balance, withdrawal affects balance                                                                                                                                                                             | A     | ❓     | Q-BRN-1                            |
| BRN-05 | Deposit config: max deposit limit, deposit commission                                                                                                                                                                                              | A     | ❓     | Limit per day? total? Q-BRN-2      |
| BRN-06 | Withdrawal config: max withdrawal limit, commission, min/max per transaction                                                                                                                                                                       | A     | ❓     |                                    |
| BRN-07 | Branch panel (own portal)                                                                                                                                                                                                                          | B     | ✔️     | Portal shell built in Phase 1      |
| BRN-08 | Branch flags **Is Deposit** / **Is Withdraw** (branch takes part in pay-in and/or pay-out)                                                                                                                                                         | A     | 📝     | Source 3                           |
| BRN-09 | Three separate limit kinds: per-transaction (min/max), daily volume, available balance/capacity                                                                                                                                                    | A     | 📝     | Source 5. Q-BRN-1/2                |

### ACC — Bank & UPI accounts

| ID     | Feature                                                                                                                 | Cert. | Status | Notes                                                           |
| ------ | ----------------------------------------------------------------------------------------------------------------------- | ----- | ------ | --------------------------------------------------------------- |
| ACC-01 | Bank account: bank name, holder name, account number, IFSC                                                              | A     | 📝     | Account number encrypted at rest, masked in lists               |
| ACC-02 | Bank "credentials/details"                                                                                              | A     | ❓     | Q-ACC-1: netbanking logins? If so, a separate security decision |
| ACC-03 | Limits per account: deposit limit, daily limit, minimum deposit                                                         | A     | 📝     |                                                                 |
| ACC-04 | UPI: UPI ID, UPI/merchant name, intent configuration                                                                    | A     | 📝     |                                                                 |
| ACC-05 | Verification status (Admin verifies)                                                                                    | A/B   | 📝     |                                                                 |
| ACC-06 | Enable/disable, availability, partner visibility, branch association                                                    | A     | 📝     |                                                                 |
| ACC-07 | Branch adds its own accounts in its panel                                                                               | B     | 📝     |                                                                 |
| ACC-08 | Account fields: UPI code, Dynamic QR available, UPI intent available                                                    | A     | 📝     | Source 3                                                        |
| ACC-09 | Lifecycle: NEW → VERIFICATION_PENDING → VERIFIED → ACTIVE (or → REJECTED). Only VERIFIED + ACTIVE accounts are eligible | A/B   | ✅     | Sources 3, 5; matches Architecture §5.1                         |
| ACC-10 | Admin can also create/manage accounts on a branch's behalf                                                              | A     | 📝     | Source 3                                                        |

### PGW — Payment gateways & credentials

| ID     | Feature                                                                           | Cert. | Status | Notes                                            |
| ------ | --------------------------------------------------------------------------------- | ----- | ------ | ------------------------------------------------ |
| PGW-01 | Gateway list: name, code, active                                                  | A     | ❓     | **Impacts architecture**, see §3 item 2          |
| PGW-02 | Add/update credentials per gateway, separately for pay-in and pay-out             | A     | ❓     | Stored encrypted; never shown again after saving |
| PGW-03 | Gateway configuration                                                             | A     | ❓     | Which providers? Q-PGW-1                         |
| PGW-04 | Existing gateways: **FFPay**, **Payin Self**, **Payout Self**; gateway has a type | A     | ❓     | Source 3. Q-PGW-2                                |
| PGW-05 | Provider-agnostic interface with one adapter per gateway                          | —     | 📝     | Source 3, proposal                               |

### PMT — Payment methods

| ID     | Feature                                                                          | Cert. | Status | Notes    |
| ------ | -------------------------------------------------------------------------------- | ----- | ------ | -------- |
| PMT-01 | Methods: Dynamic QR, UPI, Bank details, UPI intent, gateway/provider             | A     | ❓     | Q-PMT-1  |
| PMT-02 | Partner configuration decides which methods are enabled                          | A     | 📝     |          |
| PMT-03 | Methods shown = partner config ∩ branch config ∩ account config ∩ account status | A     | ✅     | Source 3 |

### SES — Payment sessions (payer-facing)

| ID     | Feature                                                                                                                    | Cert. | Status | Notes                                                              |
| ------ | -------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | ------------------------------------------------------------------ |
| SES-01 | Partner creates a payment via API → secure payment URL with runtime token                                                  | B     | 📝     | Architecture §5.3                                                  |
| SES-02 | Unique transaction ID, partner reference, amount, expiry                                                                   | B     | 📝     |                                                                    |
| SES-03 | Payment page shows bank details / UPI / QR / UPI intent + instructions                                                     | A/B   | 📝     | With partner branding (PTR-06)                                     |
| SES-04 | Payer submits UTR and optional payment proof                                                                               | A     | 📝     | See MAN-04                                                         |
| SES-05 | Payer sees payment status; returns to partner return URL                                                                   | A/B   | 📝     |                                                                    |
| SES-06 | Refresh keeps the same session and account                                                                                 | B     | 📝     |                                                                    |
| SES-07 | Each pay-in records the partner customer (partner's own customer reference) separately from the partner                    | B     | 📝     | Source 4 (terminology). What is stored about the customer: Q-CUS-1 |
| SES-08 | Create-payment response: gateway transaction ID, payment URL, status (PENDING)                                             | B     | 📝     | Source 6                                                           |
| SES-09 | Customer chooses the method (UPI / QR / bank transfer) on our page; options = partner config ∩ currently eligible accounts | A/B   | 📝     | Source 6. Allocation timing: C-06                                  |

### ALC — Account allocation engine

| ID     | Feature                                                                                                                                | Cert. | Status                               | Notes                                                          |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------- | ----- | ------------------------------------ | -------------------------------------------------------------- |
| ALC-01 | Eligibility: account verified → account active → branch active → partner mapped → partner method enabled → account limit → daily limit | B     | 📝                                   | Sources 1, 5; Architecture §6 adds more checks (see Flows 3.5) |
| ALC-02 | Strategy is **configurable**: RANDOM, ROUND_ROBIN, WEIGHTED, PRIORITY, LIMIT_BASED                                                     | B     | ✅ configurable / ❓ default & scope | Source 5. Q-ALC-1                                              |
| ALC-03 | Safe under concurrency (no double allocation beyond limits)                                                                            | B     | 📝                                   | Postgres row locks                                             |

### IN — Pay-in

| ID    | Feature                                                                                                                                                      | Cert. | Status | Notes                       |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----- | ------ | --------------------------- |
| IN-01 | API-based pay-in creation with account allocation                                                                                                            | A/B   | 📝     |                             |
| IN-02 | Pay-in states (created, pending, success, failed, declined, chargeback, refund, unsettled, settled)                                                          | A     | ❓     | Final state machine: Q-IN-1 |
| IN-03 | Admin pay-in list: ID, partner, branch, customer, amount, method, assigned account, UTR, status, created, completed, failure reason, callback/webhook status | A     | 📝     | Source 3                    |
| IN-04 | Every pay-in keeps both the partner transaction ID and our gateway transaction ID                                                                            | B     | ✅     | Source 6. ID format: C-08   |

### MAN — Manual payment, manual deposit, unsettled

| ID     | Feature                                                                                                                                                                       | Cert. | Status | Notes                                                    |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | -------------------------------------------------------- |
| MAN-01 | Manual payment list: branch, account, status, amount, UTR, transaction ID, customer, deposit type, proof, manual link, bank details, dates, action history                    | A     | ❓     | Q-MAN-1: how does "manual payment" differ from a pay-in? |
| MAN-02 | Manual deposit request: customer, amount, bank details, UTR, proof, transaction ID                                                                                            | A     | 📝     |                                                          |
| MAN-03 | Approve / decline with reason; approval history; "action performed by"                                                                                                        | A     | 📝     |                                                          |
| MAN-04 | Dates: request created, user submitted, last modified, manual entry                                                                                                           | A     | 📝     |                                                          |
| MAN-05 | "Manual link": a payment link created by Admin/Branch without the API                                                                                                         | A     | ❓     | Q-MAN-2                                                  |
| MAN-06 | Unsettled manual deposits: UTR, bank entry, transaction branch vs bank-entry branch, branch match, match status                                                               | A     | ❓     | Q-REC-1                                                  |
| MAN-07 | Manual deposit states: CREATED, PENDING, PAYMENT_PENDING, HOLD, APPROVED, DECLINED                                                                                            | C     | ❓     | Source 3, proposal. Q-IN-1                               |
| MAN-08 | Approval is one atomic operation: transaction → balance → commission → ledger → webhook → audit                                                                               | B     | ✅     | Source 3, matches Architecture §8–9                      |
| MAN-09 | Reject a deposit: DECLINED + reason, rejected by/at, previous status, audit. Configurable reason list (invalid UTR, wrong amount, duplicate, not found, invalid proof, other) | A     | 📝     | Source 5                                                 |

### STM — Account statements & import

| ID     | Feature                                                                                                                                        | Cert. | Status | Notes                                             |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | ------------------------------------------------- |
| STM-01 | Manual A/C statement entry: bank, branch, date, credit, debit, description, amount, status, branch match                                       | A     | 📝     |                                                   |
| STM-02 | Statement import for pay-in: upload, parse, identify credit/debit, match UTR/reference and branch, update transactions, keep unmatched entries | A     | ❓     | File formats per bank: Q-STM-1                    |
| STM-03 | Auto A/C statement entry (automated import)                                                                                                    | A     | ❓     | Q-STM-2: source? (bank API, email, SMS, scraping) |
| STM-04 | Import history: date, bank, branch, record count, success/failure, amount, errors                                                              | A     | 📝     |                                                   |
| STM-05 | A statement entry is a separate record from a payment transaction and is linked to it on match                                                 | A     | ✅     | Source 3                                          |

### REC — UTR management & reconciliation

| ID     | Feature                                                                                                                          | Cert. | Status | Notes                                  |
| ------ | -------------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | -------------------------------------- |
| REC-01 | Unsettled UTR list: bank, UTR, transaction, transaction branch, bank-entry branch, branch match, amount                          | A     | 📝     |                                        |
| REC-02 | Matching: statement entry → UTR → internal transaction → branch check → approve/settle                                           | A     | ❓     | Q-REC-1                                |
| REC-03 | Exceptions: UTR not found, wrong branch, amount mismatch, duplicate UTR, already processed                                       | A/C   | ❓     | Resolution rules: Q-REC-2              |
| REC-04 | Payment proof upload, view, history; review by Admin/Branch; authorized access only (never a public/predictable URL)             | A     | ✅     | Source 5. Private storage, signed URLs |
| REC-05 | Reconciliation states: UNMATCHED, MATCHED, PENDING_REVIEW, RECONCILED, REJECTED                                                  | C     | ❓     | Source 3, proposal                     |
| REC-06 | Unsettled UTR is an **operational queue**, not a transaction status. Reasons include "bank entry arrived before the transaction" | A     | 📝     | Source 5                               |

### OUT — Pay-out, manual payout, payout balance

| ID     | Feature                                                                                                                                                                      | Cert. | Status | Notes                                  |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | -------------------------------------- |
| OUT-01 | Pay-out request (via API) and pay-out transaction with status                                                                                                                | A     | ❓     | **New money direction**, see §3 item 1 |
| OUT-02 | Manual payout: customer, amount, bank details, UTR/reference, approve/decline, history                                                                                       | A     | ❓     | Q-OUT-1                                |
| OUT-03 | Pay-out limits and commission                                                                                                                                                | A     | 📝     |                                        |
| OUT-04 | Payout balance report: partner, date range, total deposit, deposit service charge, net deposit, withdrawal service charge, net withdrawal, previous balance, updated balance | A     | ❓     | Formula: Q-SET-1                       |
| OUT-05 | Add payout balance (top-up) + balance history                                                                                                                                | A     | ❓     | Q-OUT-3                                |
| OUT-06 | Auto withdrawal / partial withdrawal behaviour                                                                                                                               | A     | ❓     | Q-OUT-2                                |
| OUT-07 | Payout processing: validate → balance check → branch **or** provider allocation → SUCCESS/FAILED; admin list incl. beneficiary, provider, failure reason, UTR                | A     | ❓     | Source 3. Q-OUT-4                      |
| OUT-08 | Branch payout processing: eligible branch (active, max withdrawal limit, min/max per transaction) processes the payout via bank/provider                                     | A     | ❓     | Source 5. Q-OUT-1                      |

### RCB — Refunds & chargebacks

| ID     | Feature                                                         | Cert. | Status | Notes   |
| ------ | --------------------------------------------------------------- | ----- | ------ | ------- |
| RCB-01 | Payout refund: request, status, amount, history, report         | A     | ❓     | Q-RCB-1 |
| RCB-02 | Chargeback (pay-in and payout): status, amount, history, report | A     | ❓     | Q-RCB-1 |

### COM — Commissions

| ID     | Feature                                                                                          | Cert. | Status | Notes   |
| ------ | ------------------------------------------------------------------------------------------------ | ----- | ------ | ------- |
| COM-01 | Deposit commission at partner level and at branch level                                          | A     | ❓     | Q-COM-1 |
| COM-02 | Withdrawal commission at partner level and at branch level                                       | A     | ❓     | Q-COM-1 |
| COM-03 | Deposit / withdrawal commission reports (date, partner, branch, transaction, amount, commission) | A     | 📝     |         |

### SET — Settlement

| ID     | Feature                                                                                                 | Cert. | Status | Notes                             |
| ------ | ------------------------------------------------------------------------------------------------------- | ----- | ------ | --------------------------------- |
| SET-01 | Admin settlement: deposit, withdrawal, commission, settlement, chargeback, current balance, net balance | A     | ❓     | Q-SET-1                           |
| SET-02 | Branch settlement (same, scoped to a branch)                                                            | A     | ❓     |                                   |
| SET-03 | Partner settlement (same, scoped to a partner)                                                          | A     | ❓     |                                   |
| SET-04 | Recording a settlement (who paid whom, how much, reference)                                             | C     | ❓     | Q-SET-2                           |
| SET-05 | Settlement never modifies transaction amounts; it has its own records and ledger references             | B     | ✅     | Source 3, matches Architecture §9 |

### RPT — Reports

| ID     | Feature                                                                                                                                                                                                        | Cert. | Status | Notes    |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | -------- |
| RPT-01 | Pay-in: history, report, request report, chargeback report                                                                                                                                                     | A     | 📝     |          |
| RPT-02 | Pay-out: history, report, request report, refund report, chargeback report, partner payout, branch payout, payout balance                                                                                      | A     | 📝     |          |
| RPT-03 | Branch: branch, branch-wise, balance, deposit, withdrawal, branch-partner, branch-partner-wise, settlement, transactions                                                                                       | A     | 📝     |          |
| RPT-04 | Partner: partner, partner-wise, pay-in, pay-out, settlement, balance, transactions, partner-branch, partner-branch-wise                                                                                        | A     | 📝     |          |
| RPT-05 | Settlement: admin, branch, partner                                                                                                                                                                             | A     | 📝     |          |
| RPT-06 | Transaction detail: ID, partner, branch, customer, amount, method, bank/UPI, UTR, status, all dates, approved/rejected by, action history, proof, statement reference                                          | A     | 📝     |          |
| RPT-07 | Balance reports: branch balance, payout balance, partner balance                                                                                                                                               | A     | 📝     | Source 3 |
| RPT-08 | Partner portal reports: pay-in (ID, partner order ID, customer, amount, method, status, UTR, time), pay-out (ID, amount, beneficiary, status, UTR, time), settlement (gross, commission, charges, net, status) | A/B   | 📝     | Source 6 |

### UX — Search, filtering, export

| ID    | Feature                                                                                                                                           | Cert. | Status | Notes  |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | ------ |
| UX-01 | On operational screens: search, date range + quick dates, partner / branch / bank / status filters, reference & UTR search, pagination, page size | A     | 📝     |        |
| UX-02 | Export CSV / Excel (PDF where required), filtered and date-range based; large exports generated in background                                     | C     | ❓     | Q-UX-1 |

### WHK / API — Webhooks & partner API

| ID     | Feature                                                                                                                                                                                                            | Cert. | Status | Notes                                                  |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----- | ------ | ------------------------------------------------------ |
| WHK-01 | Signed webhooks to pay-in webhook URL, pay-out webhook URL; callback URL; return URL                                                                                                                               | A     | 📝     | Q-API-3: callback vs webhook difference                |
| WHK-02 | Retries with backoff, delivery status, failed-webhook list, history, manual resend                                                                                                                                 | B     | 📝     | Architecture §10                                       |
| WHK-03 | Webhook delivery history: event, every attempt, response, final status                                                                                                                                             | B     | 📝     | Source 6                                               |
| WHK-04 | Return URL is for customer UX only; partners must confirm via webhook or status API (stated in partner docs)                                                                                                       | B     | ✅     | Source 6                                               |
| API-01 | Credentials (key + secret), API version, allowed IPs                                                                                                                                                               | A     | 📝     |                                                        |
| API-02 | Endpoints: create payment, payment status, pay-in, pay-out                                                                                                                                                         | A/B   | ❓     | Contracts: Q-API-1                                     |
| API-03 | Request signing (HMAC), validation, idempotency, rate limiting                                                                                                                                                     | B     | 📝     |                                                        |
| API-04 | H2H (host-to-host) mode                                                                                                                                                                                            | A     | ❓     | Q-API-2                                                |
| API-05 | Secret used server-to-server only; never in the partner's frontend or the customer's browser                                                                                                                       | B     | ✅     | Source 6                                               |
| API-06 | Create pay-in fields: `partner_transaction_id`, `amount`, `customer_id/name/email/mobile`, `return_url`, `callback_url`; optional currency, description, order ID, customer IP, metadata, expiry, preferred method | B     | ❓     | Source 6. Contract: Q-API-1; per-request URLs: Q-API-4 |
| API-07 | Idempotency on `partner_transaction_id`: a retry returns the existing transaction                                                                                                                                  | B     | ✅     | Source 6. Mechanism: C-07; different body: Q-API-5     |
| API-08 | Status API by gateway transaction ID or partner transaction ID                                                                                                                                                     | B     | 📝     | Source 6                                               |
| API-09 | Errors: 400 invalid request, 401 bad credentials/signature, 403 IP/partner/version not allowed                                                                                                                     | B     | 📝     | Source 6                                               |

### NTF / CMS / CFG / IP / LOG — Platform administration

| ID     | Feature                                                                                                                                                           | Cert. | Status | Notes                                  |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | -------------------------------------- |
| NTF-01 | Alert notifications: system, transaction, account, failed and unsettled transaction alerts; history                                                               | A     | ❓     | Events and channels: Q-NTF-1           |
| CMS-01 | Pages: create/edit static content (terms, privacy, help, payment-page text)                                                                                       | A     | ❓     | Q-CMS-1                                |
| CFG-01 | Global settings (system, payment, commission, transaction, notification)                                                                                          | A     | ❓     | List of actual settings: Q-CFG-1       |
| IP-01  | IP management: whitelist per partner, add/remove, activate/deactivate                                                                                             | A     | 📝     | Also used for admin access? Q-IP-1     |
| LOG-01 | Action history on transactions/accounts: who, when, before → after                                                                                                | A     | 📝     | Audit log exists (Phase 1)             |
| LOG-02 | Admin/user activity log                                                                                                                                           | A     | 📝     |                                        |
| LOG-03 | Mandatory audit events: deposit approve/reject, commission change, mapping change, bank account change, secret regeneration, permission change, settlement change | A     | 📝     | Source 3. Audit table exists (Phase 1) |

### SEC / SYS — Security & infrastructure (from Architecture.md)

| ID     | Feature                                                                                                                             | Cert. | Status | Notes                          |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------- | ----- | ------ | ------------------------------ |
| SEC-01 | RBAC, 2FA, secure sessions, login/security logs                                                                                     | B     | ✔️     | Phase 1                        |
| SEC-02 | HMAC signing, idempotency, rate limits, IP whitelist, secure expiring payment tokens                                                | B     | 📝     |                                |
| SEC-03 | Encryption of bank details and credentials; webhook signatures                                                                      | B     | 📝     |                                |
| SEC-04 | Concurrency protection, WAF, HTTPS, database access isolation                                                                       | B     | 📝     |                                |
| SEC-05 | Tenant scoping enforced in the backend: branch users only see their branch, partner users only their partner                        | B     | ✅     | Sources 5, 6; Architecture §11 |
| SYS-01 | Queues (Horizon): webhooks, retries, notifications, statement processing, reconciliation, reports, exports, settlement calculations | B     | ✔️     | Queues configured (Phase 0)    |
| SYS-02 | Scale: ~100 partners, ~100 branches, thousands of payers                                                                            | B     | 📝     |                                |

---

## 3. Impact on the architecture (to decide before database design)

The feature list is larger than the first brief in five places. Each one changes Architecture.md.

1. **Pay-out (money out) is a second money direction.** The architecture assumed pay-in only. Pay-out adds:
    - partner payout requests
    - branches paying customers manually
    - payout balance and top-ups
    - refunds and chargebacks

    This roughly doubles the transaction model, and the **ledger must be designed for both directions from day one**.

2. **Payment gateways and credentials mean the system is not purely manual.** If some methods (Dynamic QR, gateway) go through a real provider, we need:
    - provider integrations
    - encrypted credential storage
    - **inbound provider webhooks**, which Architecture.md §2 had removed

    Which providers, and whether they are in the first release, decides a lot.

3. **Editable roles.** The reference UI lets Admin create roles and tick permissions. Phase 1 has **fixed roles defined in code**. The recommended change: keep the **permission list in code**, since developers own it, and make **roles editable in the database**, which Admin owns. This is a contained change to Phase 1.
4. **Branch balances.** "Assign top-up balance", "deposit affects balance" and "withdrawal affects balance" mean branches carry a running balance, not just limits. This is the core of the ledger and settlement design.
5. **Commission at two levels.** Partner and branch each have deposit and withdrawal commissions, so a single transaction produces several ledger entries. We need to decide who earns what.

Smaller additions: partner branding on the payment page, H2H mode, IP management UI, CMS pages, global settings, alert notifications, and exports.

---

## 4. Open questions (for the next meeting)

> **Consolidated (2026-09-27):** every question and contradiction below has been merged into [Requirements.md §9](Requirements.md) as gaps **G-01 to G-53**, each with why it matters, options and a recommended approach. The Q-/C- IDs are shown there in brackets. From now on, track answers in Requirements.md.

| #        | Question                                                                                                                                                                                                                                                 | Features              |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------- |
| Q-MAP-1  | ~~Assign whole branches or individual accounts?~~ **Partly answered (Source 5): branch-level, many-to-many.** Still open: can a single account be excluded for one partner?                                                                              | MAP-01/03             |
| Q-MAP-2  | "Branch Map": a geographic map, or just the partner↔branch mapping screen?                                                                                                                                                                               | MAP-04                |
| Q-ROLE-2 | Do we need per-user permission overrides, or are roles enough?                                                                                                                                                                                           | USR-06, ROLE-01       |
| Q-BRN-1  | Explain branch limit types ("Deposit limit" vs "Assign top-up balance") and how deposits/withdrawals change a branch balance                                                                                                                             | BRN-03/04             |
| Q-BRN-2  | Is the branch "max deposit limit" per day, per month, or a running total?                                                                                                                                                                                | BRN-05                |
| Q-ACC-1  | What are bank "credentials"? (Netbanking logins would need a separate security design)                                                                                                                                                                   | ACC-02                |
| Q-PGW-1  | Which payment gateways/providers, and are they needed in the first release?                                                                                                                                                                              | PGW-*, PMT-01         |
| Q-PMT-1  | What exactly is "Dynamic QR", and who generates it: us (UPI QR string) or a provider?                                                                                                                                                                    | PMT-01, PTR-04        |
| Q-ALC-1  | **Partly answered (Source 5): configurable strategy.** Still open: default strategy, and is it set globally, per partner or per mapping?                                                                                                                 | ALC-02                |
| Q-IN-1   | Final pay-in states and what each means. **Three vocabularies now exist** (Architecture §8, Flow 1 manual deposit, Flow 2); see C-10                                                                                                                     | IN-02                 |
| Q-MAN-1  | Difference between "Manual payment", "Manual deposit" and API pay-in                                                                                                                                                                                     | MAN-01/02             |
| Q-MAN-2  | What is a "manual link", and who creates it?                                                                                                                                                                                                             | MAN-05                |
| Q-STM-1  | Which banks, and what statement file formats (CSV/XLS/PDF)?                                                                                                                                                                                              | STM-02                |
| Q-STM-2  | "Auto statement entry": where does the data come from?                                                                                                                                                                                                   | STM-03                |
| Q-REC-1  | **Partly answered (Source 5): UTR + amount + branch; "branch match" = statement's branch vs the allocated account's branch.** Still open: date tolerance, partial amounts, how a mismatch is resolved                                                    | REC-02, MAN-06        |
| Q-REC-2  | What happens for each exception (not found, wrong branch, amount mismatch, duplicate)?                                                                                                                                                                   | REC-03                |
| Q-OUT-1  | Pay-out flow end to end: who requests it, who pays, how is it confirmed?                                                                                                                                                                                 | OUT-01/02             |
| Q-OUT-2  | Payout "group", "limit type", "auto withdrawal", "partial withdrawal": meaning of each                                                                                                                                                                   | PTR-07, OUT-06        |
| Q-OUT-3  | Payout balance: who tops it up, and how is it funded?                                                                                                                                                                                                    | OUT-05                |
| Q-RCB-1  | Refund and chargeback flows and their effect on balances                                                                                                                                                                                                 | RCB-*                 |
| Q-COM-1  | Commission: percentage or flat? Who earns each (platform, branch)? How do partner and branch commissions combine? Who bears it? Calculated at creation or at success? Do refunds / chargebacks reverse it?                                               | COM-*, PTR-05, BRN-05 |
| Q-SET-1  | Formulas for current balance, net balance and payout balance                                                                                                                                                                                             | SET-*, DSH-02, OUT-04 |
| Q-SET-2  | How is a settlement recorded and approved?                                                                                                                                                                                                               | SET-04                |
| Q-API-1  | Exact API request/response contracts. Flow 2 uses `/api/v2/…` and partners have an "API version" (e.g. V2): **must we stay compatible with an existing V2 API that live partners already use?**                                                          | API-02, C-09          |
| Q-API-2  | What does "H2H" mean here? (e.g. partner shows bank details in its own UI without our payment page)                                                                                                                                                      | API-04, PTR-08        |
| Q-API-3  | Difference between pay-in "callback URL" and "webhook URL"                                                                                                                                                                                               | WHK-01                |
| Q-NTF-1  | Which alerts, to whom, and via which channels (panel, email, SMS, Telegram)?                                                                                                                                                                             | NTF-01                |
| Q-CMS-1  | Which pages are needed?                                                                                                                                                                                                                                  | CMS-01                |
| Q-CFG-1  | The actual list of global settings                                                                                                                                                                                                                       | CFG-01                |
| Q-IP-1   | IP whitelisting for partner API only, or for admin/branch panel logins too?                                                                                                                                                                              | IP-01                 |
| Q-UX-1   | Which reports need export, and in which formats?                                                                                                                                                                                                         | UX-02                 |
| Q-AUTH-1 | Flow 1 says admin 2FA is "optional, later"; Phase 1 made it mandatory for Admin and Branch. Keep mandatory?                                                                                                                                              | AUTH-03, C-02         |
| Q-SEC-1  | Partner secret: HMAC request signing (needs an encrypted, retrievable secret) or a bearer API key (can be stored hashed)?                                                                                                                                | PTR-10, API-03, C-01  |
| Q-MAP-3  | Are mapping priority/order and per-mapping limits needed in the first release?                                                                                                                                                                           | MAP-06/07             |
| Q-MAN-3  | **Partly answered (Source 5): the branch reviews and approves.** Still open: can Admin also approve/override? Is a second approval ever needed?                                                                                                          | MAN-03, C-04          |
| Q-IN-2   | What makes a pay-in SUCCESS: branch confirmation, statement reconciliation, Admin approval, or a combination?                                                                                                                                            | IN-01, REC-02, C-04   |
| Q-OUT-4  | How is a payout routed to a branch (manual) vs a provider (e.g. FFPay)? Per partner, per amount, or manual choice?                                                                                                                                       | OUT-07                |
| Q-PGW-2  | What are "Payin Self" / "Payout Self": our own manual channel modelled as a gateway? What does FFPay provide (pay-in, pay-out, QR)?                                                                                                                      | PGW-04                |
| Q-DSH-1  | ✅ Answered 2026-09-28 (G-49): owed but not yet settled (ledger positions now). Definition of "unsettled amount" (not yet reconciled? approved but not settled?)                                                                                         | DSH-06                |
| Q-CUS-1  | **Partly answered (Source 6): each request carries `customer_id`, name, email, mobile.** Still open: store only on the transaction, or keep a reusable customer record (for customer-level reports/blocking)? How much customer PII must we keep (DPDP)? | SES-07, API-06        |
| Q-API-4  | May `return_url` / `callback_url` be sent per request, overriding the configured ones? If so, must they match the partner's registered domain (prevents open redirects and our server calling arbitrary URLs)?                                           | API-06                |
| Q-API-5  | Same `partner_transaction_id` sent again with a **different** amount or customer: reject with 409, or return the original?                                                                                                                               | API-07                |

### 4.1 Contradictions to resolve

| #    | Conflict                                                                                                                                                                                                                         | Between                                                | Recommendation                                                                                                                                                                                                           |
| ---- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| C-01 | Secret "stored as a hash" vs HMAC request signing, which needs the server to know the secret                                                                                                                                     | Flow 1 §1.3 ↔ Architecture §5.2 / §10                  | Keep HMAC and store the secret **encrypted** (shown once, rotatable). Hashing works only with a bearer key, which is weaker (the key travels on every request). See Q-SEC-1                                              |
| C-02 | Admin 2FA "optional, later" vs mandatory now                                                                                                                                                                                     | Flow 1 §1.1 ↔ Phase 1                                  | Keep mandatory: admins move money. See Q-AUTH-1                                                                                                                                                                          |
| C-03 | Editable roles with `module.action` permissions vs Phase 1's fixed roles and different permission names                                                                                                                          | Flow 1 §1.7 ↔ Phase 1                                  | Move roles to the DB and rename permissions to `module.action` before Phase 2 (see §3 item 3)                                                                                                                            |
| C-04 | Who confirms a pay-in: Architecture has the **branch** confirm after the payer submits a UTR; Flow 1 has **statement reconciliation + approval**; Flow 3 has the **branch** approving manual deposits **and** statement matching | Flow 1 §1.11–1.12, Flow 3 §3.6–3.7 ↔ Architecture §5.6 | Decide in Flows 4–6. See Q-IN-2, Q-MAN-3                                                                                                                                                                                 |
| C-05 | "Gateway" = our platform, but the Payment Gateways module (FFPay, Payin Self, Payout Self) uses the same word for **external providers**                                                                                         | Terminology ↔ PGW module, Flow 1 §1.9                  | Call external ones **payment providers** (`providers` in code/DB); keep "Payment Gateway" only as a UI label if the client wants it                                                                                      |
| C-06 | **When is the account allocated?** Architecture §5.3 allocates inside the create-payment API call; Flow 2 §8–9 allocates after the customer opens the page and picks a method                                                    | Flow 2 ↔ Architecture §5.3                             | Create the transaction at API time, allocate (and reserve the limit) when the customer picks a method. Capacity isn't reserved for customers who never open the link, and the method is known before choosing an account |
| C-07 | **Idempotency mechanism:** Architecture uses an `Idempotency-Key` header; Flow 2 uses `partner_transaction_id`                                                                                                                   | Flow 2 ↔ Architecture §10                              | Use a unique (partner, `partner_transaction_id`) constraint as the key: simpler for partners and enforced by the database. See Q-API-5                                                                                   |
| C-08 | **Gateway transaction ID format:** Flow 2 shows a sequential `PGW-20260925-000001`; Architecture proposes a non-sequential reference                                                                                             | Flow 2 ↔ Architecture §18 #1                           | Date + random suffix (e.g. `PG-260925-8F3K2Q`). Sequential numbers reveal daily volume to partners and customers                                                                                                         |
| C-09 | **API path and version:** `/api/v2/payments` vs `api.paygate.local/v1/payment-sessions`                                                                                                                                          | Flow 2 ↔ Architecture §10                              | Depends on Q-API-1. If live partners use a V2 contract, match it; otherwise start at v1                                                                                                                                  |
| C-10 | **Transaction state names differ** in Architecture §8, Flow 1 (manual deposit) and Flow 2                                                                                                                                        | Flows 1, 2 ↔ Architecture §8                           | Unify in Flow 9 (state-machine rules)                                                                                                                                                                                    |

---

## 5. Next step: role-wise end-to-end flows

Before database design, each flow is written step by step (who does what, what the screen shows, what changes in the data). This answers most of §4:

1. Admin complete flow
2. Partner complete flow
3. Branch complete flow
4. End-user pay-in flow
5. Manual deposit flow
6. A/C statement → UTR → reconciliation flow
7. Pay-out flow
8. Settlement flow

Output: [Flows.md](Flows.md) (Flows 1–3 captured 2026-09-27), then the transaction state machine, then the PostgreSQL schema.
