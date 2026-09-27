# PAY GATEWAY — Final Business Requirements & System Understanding v1

|                   |                                                                                                                                                                                                                                                                                             |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Status            | **v1.4 — accepted as the baseline for database design (2026-09-27).** Remaining open items are Priority 2 (per build phase). The database design is in [Database.md](Database.md).                                                                                                          |
| Date              | 2026-09-27                                                                                                                                                                                                                                                                                  |
| Product           | PAY GATEWAY (codebase name: `paygate`)                                                                                                                                                                                                                                                      |
| Readers           | Client, product manager, business analyst, solution architect, backend/frontend developers, database architect, QA                                                                                                                                                                          |
| Built from        | Original brief (2026-09-25) · reference-system videos & screenshots · Feature List v0.1 · Flows 1–3 · terminology note · master business brief · client answers + legacy API PDFs (2026-09-27)                                                                                              |
| Related documents | [Features.md](Features.md): detailed feature inventory (IDs such as `OUT-05`) · [Flows.md](Flows.md): step-level Flows 1–3 · [Architecture.md](Architecture.md): technical architecture, to be revised after approval · [Legacy-API.md](Legacy-API.md): the existing platform's partner API |

### How to read this document

Every statement carries one of four labels:

| Label            | Meaning                                                                                   |
| ---------------- | ----------------------------------------------------------------------------------------- |
| **[Confirmed]**  | Stated by the client or visible in the reference system                                   |
| **[Assumption]** | Our working assumption, low risk; listed in §10.2 so it can be challenged                 |
| **[Proposal]**   | Our design recommendation; not binding until approved                                     |
| **[Confirm]**    | A business rule we do **not** know. We have not invented an answer. See the gap list (§9) |

`G-xx` = gap / open question (§9) · `A-xx` = assumption (§10.2) · `OOS-xx` = out of scope (§10.4) · `PTR-02`, `REC-06`, … = feature IDs in Features.md.

### What changed in v1.1 – v1.4 (client answers, 2026-09-27)

| Topic                                | Answer                                                                                                                                                                                                                                                                                                                                    | Effect                                                                                                                                                 |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Central counterparty (G-01)          | **The platform is always in the middle.** Commission is collected from both parties **outside** the platform                                                                                                                                                                                                                              | ✅ Answered. The platform only calculates and records                                                                                                  |
| Settlement recording (G-10, G-13)    | Admin just **ticks the amount as settled**                                                                                                                                                                                                                                                                                                | 🟡 A simple "mark as settled" action. Approval steps and proof become optional (still confirm G-11)                                                    |
| Commission rates (G-06)              | Rates are negotiated **by phone, outside the platform**. Admin then enters the final deposit and withdrawal commission                                                                                                                                                                                                                    | ✅ Answered: rates per partner and per branch, entered by Admin (reading to check: G-59)                                                               |
| Allocation (G-29)                    | **Round robin** over the branches and accounts mapped to the partner, as the client requested                                                                                                                                                                                                                                             | ✅ Answered: default strategy = round robin                                                                                                            |
| Pay-in success (G-23)                | The customer sees QR / UPI / bank details with **two options: add UTR number or upload a photo**. The request appears in the **portal of the branch that owns the account**; a branch operator checks their bank and, once the money shows, clicks **Approve**. The partner panel then shows it approved and the partner credits its user | ✅ Answered: **branch approval is the success trigger** (resolves C-04)                                                                                |
| Pay-out success (G-18)               | "Same way": the request appears in the branch portal, the branch pays and approves with the UTR                                                                                                                                                                                                                                           | ✅ Answered                                                                                                                                            |
| Who pays commission (G-60, v1.2)     | **Branches never pay us; we pay branches** their commission for providing banking services. **Partners always pay**, on both pay-in and pay-out                                                                                                                                                                                           | ✅ Platform income = **partner commission − branch commission** (the spread)                                                                           |
| Withdrawal commission (G-02, v1.2)   | Follows from the above: the partner pays its withdrawal commission; the branch earns its withdrawal commission                                                                                                                                                                                                                            | ✅ Option **W-A** confirmed (§6.4)                                                                                                                     |
| Existing API                         | Client shared the legacy pay-in and pay-out API documents                                                                                                                                                                                                                                                                                 | Captured in [Legacy-API.md](Legacy-API.md)                                                                                                             |
| Commission timing (G-05, v1.3)       | Counted **when the transaction succeeds**                                                                                                                                                                                                                                                                                                 | ✅                                                                                                                                                     |
| Settlement period (G-09, v1.3)       | **Daily**, plus **on demand**                                                                                                                                                                                                                                                                                                             | ✅                                                                                                                                                     |
| Losses / chargebacks (G-20, v1.3)    | **The platform never bears a loss.** It works like Upwork: the partner (client) wants a service and pays commission, and we pass the branch's (freelancer's) share on                                                                                                                                                                     | ✅ Platform margin is never reversed at our cost; see §6.8 for what is still open                                                                      |
| Partner integration (v1.3)           | Partner gives us its **webhook URL, callback URL and website URL**; we give it a **secret key**. At checkout our link opens with their token/order ID → the customer pays any branch QR/UPI/bank → gives proof → the request goes to the branch → the branch approves → the partner portal is updated                                     | ✅ Matches F4–F5; adds a **website URL** field                                                                                                         |
| Legacy API role (G-39, G-54, v1.3)   | **A reference only.** It shows what works today; we design **our own** API and reuse ideas where useful                                                                                                                                                                                                                                   | ✅ No compatibility layer needed. G-55, G-56, G-57 no longer block anything                                                                            |
| Providers such as FFPay (G-37, v1.3) | Nobody knows its role                                                                                                                                                                                                                                                                                                                     | Left **out of v1** (OOS-11); the design keeps room to add providers later                                                                              |
| Payout balance (G-15, G-16, v1.4)    | If the partner **doesn't have enough balance with its associated branch**, its customer cannot withdraw: return a **"balance is low"** error                                                                                                                                                                                              | ✅ The partner's balance is kept **per partner↔branch pair**; a payout goes only to a mapped branch where the pair has enough balance                  |
| Limits (v1.4)                        | Deposit and withdrawal limits are set when creating the partner and the branch; the branch sets account limits within the branch's limit                                                                                                                                                                                                  | ✅ Three levels: partner → branch → account. An account limit can't exceed its branch's                                                                |
| Settlement (v1.4)                    | Admin can settle up manually                                                                                                                                                                                                                                                                                                              | ✅ Admin ticks amounts as settled and can post manual adjustments                                                                                      |
| Manual types (G-28, v1.4)            | The system works as a payment gateway: our secret key + API, checkout redirects to our page                                                                                                                                                                                                                                               | ✅ "Manual deposit/payout" = the normal pay-in/payout **confirmed manually by the branch**. One transaction model. "Manual link" is not in v1 (OOS-13) |
| Allocation timing (G-30, v1.4)       | Checkout redirects to our payment page, then payment happens                                                                                                                                                                                                                                                                              | ✅ Allocate when the customer opens the page / picks a method                                                                                          |
| Rates (G-59, G-07, v1.4)             | Both partners and branches have rates; **Admin can assign anything to any partner and branch and is responsible for it**                                                                                                                                                                                                                  | ✅ Rates per partner and per branch, plus an optional per-pair override. A negative margin is **warned about, not blocked**                            |

---

## 0. One-page summary

**What the business is.** PAY GATEWAY is a **trusted network between Partners and Branches**, not a generic payment gateway.

- **Partners** are external websites (merchants) whose customers need to deposit and withdraw money.
- **Branches** are payment operators with bank accounts, UPI IDs, staff and capacity.
- **We** onboard and verify both sides, connect them, route each customer payment to a suitable branch account, track every transaction, calculate commissions, reconcile against bank statements, and calculate the net amount each party must settle.

**How money moves.** Customer money moves **only between the customer and a branch**. **Our company's bank account is never in the payment path.** The platform records the obligations those payments create, and the net amounts are settled **outside the platform** at the end of a period. Admin then records those settlements in the system.

```text
                 PHYSICAL MONEY                            RECORDED OBLIGATIONS (in the platform)

   Deposit:    Customer ──₹──▶ Branch bank account        Branch ──owes──▶ Platform ──owes──▶ Partner
   Withdrawal: Branch bank account ──₹──▶ Customer        Partner ──owes──▶ Platform ──owes──▶ Branch

   Period end: net positions calculated ─▶ settled OUTSIDE the platform ─▶ Admin records the settlement
```

**How we earn.** **[Confirmed v1.2]** Partners always pay commission (on pay-ins and pay-outs); branches never pay commission, and we pay them theirs. Our income is the **spread**: what the partner pays minus what the branch earns. Rates are set separately for deposits and withdrawals, per partner and per branch. For example, on a ₹100 deposit with a 6% partner rate and a 3% branch rate, the branch settles ₹97, the partner receives ₹94, and the platform's margin is ₹3.

**The five separate concepts.** Keeping these apart is the core design principle:

| Concept            | Question it answers                                       | ₹100 deposit example                                                              |
| ------------------ | --------------------------------------------------------- | --------------------------------------------------------------------------------- |
| **Transaction**    | What did the customer do?                                 | Customer paid ₹100 to Branch A's account for Partner A                            |
| **Commission**     | What does each party pay or earn on it?                   | Partner 6% = ₹6, Branch 3% = ₹3                                                   |
| **Ledger**         | Who now owes whom, and why?                               | Branch A owes Platform ₹97; Platform owes Partner A ₹94; margin ₹3                |
| **Reconciliation** | Did the money really arrive in the bank?                  | Statement line with UTR 1234… matched to this transaction                         |
| **Settlement**     | What net amount is paid outside the platform, and was it? | Period net: Branch A → Platform ₹17.6 lakh, recorded with reference EXT-SET-00123 |

**The biggest unknowns** (full list in §9). These must be answered before database design:

- partner and branch balance rules for payouts (G-15, G-16)
- which of partner or branch absorbs a chargeback, if one ever happens (G-20)
- what the "manual" deposit/payout types are (G-28)
- a legal review of the operating model (G-51)

---

## Phase 1 — Business understanding

### 1.1 The business in our own words

**Analogy (client's own, v1.3):** it works like Upwork. The partner is the client who needs work done (collecting and paying out money). The branch is the freelancer who provides the service (banking). We are the marketplace: the partner pays us commission, we pass the branch its share, and we never carry the risk ourselves.

Partners run consumer websites whose users regularly add money and withdraw money. Collecting and paying out at volume needs many reliable bank and UPI accounts plus people to operate them, which most partners don't have. Branches have exactly that: accounts, staff and capacity. What they lack is a steady flow of reputable business.

PAY GATEWAY sits in the middle as the **trusted operator**. It:

1. vets both sides
2. decides which branches may serve which partners
3. gives each partner an API and a hosted payment page
4. sends each customer payment to an eligible branch account
5. confirms, using bank statements and UTRs, that money actually moved
6. books each party's share
7. works out, per period, how much each branch owes and how much is owed to each partner

The platform itself never touches the customers' money. Its product is **routing, trust, record-keeping, reconciliation and settlement calculation**, and it earns the commission spread. **[Confirmed]**

### 1.2 Value to each side

| Party             | Gets                                                                                                                           | Gives                                                               |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------- |
| **Partner**       | Access to verified branches, a payment API and hosted payment page, webhooks, reports, periodic settlement of its net position | Deposit and withdrawal commission                                   |
| **Branch**        | Business volume from vetted partners, an operating portal, reports, periodic settlement                                        | Bank/UPI accounts, operators, capacity; processes withdrawals       |
| **Platform** (us) | Commission spread (partner rate − branch rate)                                                                                 | Trust, matching, technology, accounting, reconciliation, settlement |

### 1.3 What the platform is and is not

| The platform **is**                                                                                  | The platform **is not**                                                             |
| ---------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| A network and matching layer between partners and branches **[Confirmed]**                           | A generic payment gateway **[Confirmed]**                                           |
| The system of record for transactions, commissions, obligations and settlements **[Confirmed]**      | A holder of customer funds: our bank account is not in the flow **[Confirmed]**     |
| A calculator and recorder of net settlement **[Confirmed]**                                          | A bank-transfer engine: settlements happen outside and are recorded **[Confirmed]** |
| The owner of payment transactions, sessions, allocation, reconciliation and webhooks **[Confirmed]** | The owner of the partner's customer relationship **[Confirmed]**                    |

### 1.4 Physical money vs recorded obligations

| Event                 | Physical money                                           | What the platform records                                                                                    |
| --------------------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Customer deposit      | Customer → branch bank account                           | Transaction, commissions, ledger postings (branch owes platform, platform owes partner)                      |
| Customer withdrawal   | Branch bank account → customer                           | Transaction, commissions, ledger postings (partner owes platform, platform owes branch) **[Confirmed v1.2]** |
| Period-end settlement | Branch ↔ our company ↔ partner, **outside the platform** | The settlement record (amount, direction, reference, proof), which clears the ledger positions               |

---

## Phase 2 — Actors

| Actor                           | Who                                                                                     | Login?                  | Does                                                                                                                                                                                                                         | Must never                                                                        |
| ------------------------------- | --------------------------------------------------------------------------------------- | ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| **Admin**                       | Our company's staff (sub-roles e.g. super admin, operations, finance, viewer)           | Yes, admin portal, 2FA  | Onboards and verifies partners and branches, maps them, sets commercial terms, verifies accounts, oversees all transactions, resolves reconciliation cases, calculates and records settlements, manages users/roles/settings | Change financial history directly; every correction is a new, audited entry       |
| **Partner** (organisation)      | External merchant website/business                                                      | via Partner users       | Integrates via API, creates pay-ins and pay-outs for its customers, receives webhooks, views its own data, settles its net position                                                                                          | See other partners' data; see branch internals                                    |
| **Partner user**                | A person working for a partner                                                          | Yes, partner portal     | Views transactions, balance, settlement and reports; manages API keys, webhooks, IP whitelist (by permission)                                                                                                                | Act outside their own partner                                                     |
| **Partner customer** (end user) | The partner's customer who pays or receives money                                       | **No**                  | Opens our payment page, pays the shown account, submits UTR/proof; receives withdrawals                                                                                                                                      | See branch IDs, commissions, settlement or reconciliation data, or other partners |
| **Branch** (organisation)       | Payment operator entity                                                                 | via Branch users        | Provides bank/UPI accounts, receives deposits, pays withdrawals, imports statements, confirms/rejects deposits, settles its net position                                                                                     | See other branches' data, or partner commercial terms                             |
| **Branch user**                 | A person working for a branch (e.g. branch admin, deposit operator, statement operator) | Yes, branch portal, 2FA | Operates the branch by permission                                                                                                                                                                                            | Act outside their own branch                                                      |
| **Platform**                    | Our company as an **accounting party**                                                  | n/a                     | Central counterparty in the ledger; earns the spread; is one side of every settlement                                                                                                                                        | Hold customer money                                                               |
| **Payment account**             | A bank account or UPI ID owned by a branch                                              | n/a                     | Receives customer deposits; is the source of withdrawals                                                                                                                                                                     | Be used before it is verified and active                                          |
| **Payment provider**            | External service, e.g. FFPay (plus "Payin Self"/"Payout Self" in the reference system)  | n/a                     | Possibly generates dynamic QR / processes pay-ins or pay-outs                                                                                                                                                                | **Role not yet defined [Confirm G-37]**                                           |

---

## Phase 3 — Business relationships

### 3.1 Conceptual entity map (not a database design)

```text
                         ┌──────────── Commercial agreement (rates, effective dates) ────────────┐
                         │                                                                          │
   Partner ──1:N── Partner user            Partner ──N:M (Partner↔Branch mapping)── Branch ──1:N── Branch user
      │                                                                                  │
      │ 1:N (customer reference)                                                         │ 1:N
      ▼                                                                                  ▼
   Partner customer ref ──1:N── Transaction (pay-in / pay-out) ──N:1── Payment account (bank / UPI)
                                   │      │        │          │
                   Payment session ┘      │        │          └── Webhook events → deliveries
                                          │        │
                        Commission lines ─┘        └── Reconciliation link ── Statement entry ── Statement import
                                          │
                                   Ledger entries ──N:1── Settlement (party + period) ──1:N── External settlement records
```

### 3.2 Relationships

| Relationship                       | Cardinality             | Rule                                                                                                                                                                               | Label                                                           |
| ---------------------------------- | ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- |
| Partner ↔ Customer                 | 1 : N                   | The partner owns the customer relationship. We keep only the reference and details needed to process, reconcile and report                                                         | Confirmed; data scope **[Confirm G-43]**                        |
| Partner ↔ Branch                   | **N : M** via a mapping | The mapping has its own status, priority, limits, transaction permissions (deposit/withdrawal), effective dates, configuration and audit history. A disabled mapping is never used | Confirmed; which attributes are needed in v1 **[Confirm G-31]** |
| Branch ↔ Bank/UPI account          | 1 : N                   | An account belongs to exactly one branch and is usable only when verified and active                                                                                               | Confirmed                                                       |
| Transaction ↔ Partner              | N : 1                   | Every transaction belongs to exactly one partner                                                                                                                                   | Confirmed                                                       |
| Transaction ↔ Branch               | N : 1                   | Every transaction is served by exactly one branch and one account                                                                                                                  | Confirmed / **[Assumption A-05]** for reassigned payouts        |
| Transaction ↔ Customer             | N : 1                   | Carries the partner's customer reference and details                                                                                                                               | Confirmed                                                       |
| Transaction ↔ Bank statement entry | 0..1 : 0..1 (normally)  | Separate records, **linked** on reconciliation. Edge cases (split or duplicate lines) go to a reconciliation case                                                                  | Confirmed (separation); edge rules **[Confirm G-24]**           |
| Transaction ↔ Commission           | 1 : N                   | Each successful transaction creates commission lines: partner commission and branch commission, using the rates in force at that moment                                            | Confirmed (two sides); timing **[Confirm G-05]**                |
| Transaction ↔ Ledger               | 1 : N                   | Each financial event (success, reversal, adjustment) posts balanced ledger entries. The ledger is append-only                                                                      | Confirmed (principle)                                           |
| Ledger ↔ Settlement                | N : 1                   | A settlement groups one party's ledger movements for a period and states the net amount and direction. External settlement records clear it                                        | Confirmed (principle)                                           |
| Partner/Branch ↔ Users             | 1 : N                   | Every portal user belongs to exactly one partner or branch (admins to neither)                                                                                                     | Confirmed                                                       |

---

## Phase 4 — Feature matrix

**Status:** ✅ Confirmed (scope is clear) · 🟡 Partly confirmed (scope clear, rules open) · ❓ Needs confirmation · 🔨 Already built (Phases 0–1).
**Audit:** A = audit log (who/what/before/after) · S = security log · T = transaction event history · — = none needed.

### 4.1 Access & identity

| #   | Feature               | Actor                            | Purpose                          | Inputs → Outputs                                       | Key rules                                                                              | Depends on | Status                                    | Audit |
| --- | --------------------- | -------------------------------- | -------------------------------- | ------------------------------------------------------ | -------------------------------------------------------------------------------------- | ---------- | ----------------------------------------- | ----- |
| 1   | Authentication        | All portal users                 | Secure access                    | Email, password, 2FA → session in the correct portal   | Suspended users blocked; portal isolation; 2FA for admin and branch **[Confirm G-46]** | —          | 🔨                                        | S     |
| 48  | Roles                 | Admin                            | Group permissions                | Name, permissions → role                               | Admin creates/edits roles **[Confirmed]**; per-user overrides **[Confirm G-45]**       | 49         | 🟡 (built as fixed; must become editable) | A     |
| 49  | Permissions           | System                           | Granular capabilities            | Fixed catalogue in code, e.g. `manual_deposit.approve` | Checked on the server for every action                                                 | —          | 🟡                                        | —     |
| —   | User management       | Admin (+ partner/branch owners?) | Manage portal users              | Name, email, org, role → invite email                  | Users are suspended, never deleted; who may create users **[Confirm G-45]**            | 48         | 🟡                                        | A     |
| 42  | IP management         | Admin, Partner                   | Restrict API (and panel?) access | IP/CIDR list per partner → allow/deny                  | API calls from non-whitelisted IPs are rejected; panels **[Confirm G-46]**             | 37         | 🟡                                        | A     |
| 43  | Audit / activity logs | Admin (+ own org)                | Traceability                     | Every sensitive action → append-only record            | Records can't be edited or deleted (enforced by the database)                          | —          | 🔨 foundation                             | —     |

### 4.2 Network: partners, branches, mapping, commercial terms

| #    | Feature                  | Actor                | Purpose                   | Inputs → Outputs                                                                                                   | Key rules                                                                                                                                                | Depends on | Status                | Audit |
| ---- | ------------------------ | -------------------- | ------------------------- | ------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | --------------------- | ----- |
| 3    | Partner management       | Admin                | Onboard/maintain partners | Profile, URLs, IPs, API version, methods, branding, payout config → partner + credentials                          | Code unique; secret shown once, rotatable; deactivate, never delete                                                                                      | 30, 42     | 🟡                    | A     |
| —    | Partner verification     | Admin                | Trust                     | KYC/business documents, review → verified status                                                                   | Requirements **[Confirm G-34]**                                                                                                                          | 3          | ❓                    | A     |
| 5    | Branch management        | Admin                | Onboard/maintain branches | Name, deposit/withdraw flags, limits, commission, limit type → branch                                              | Limit type semantics **[Confirm G-16]**                                                                                                                  | 30         | 🟡                    | A     |
| —    | Branch verification      | Admin                | Trust                     | KYC, entity documents, operational review → verified status                                                        | Requirements **[Confirm G-35]**                                                                                                                          | 5          | ❓                    | A     |
| 4, 7 | Partner ↔ branch mapping | Admin                | Decide who serves whom    | Partner, branch, status, priority, limits, deposit/withdraw permission, effective dates → mapping                  | Many-to-many; disabled or out-of-date mappings are ignored by allocation                                                                                 | 3, 5       | 🟡 **[Confirm G-31]** | A     |
| 30   | Commission management    | Admin                | Commercial terms          | Rates per partner and branch × deposit and withdrawal (future: fixed, slab, effective dates, versions) → agreement | Never one generic field; a negative spread is blocked unless explicitly allowed **[Confirm G-07]**; rates snapshotted on each transaction **[Proposal]** | 3, 5       | 🟡                    | A     |
| 10   | Branch map               | Admin                | ?                         | ?                                                                                                                  | Geographic map or mapping screen? **[Confirm G-50]**                                                                                                     | 4          | ❓                    | —     |
| 6    | Branch users             | Admin / branch owner | Staff the branch          | User, role → login                                                                                                 | Scoped to one branch                                                                                                                                     | 48         | 🟡                    | A     |

### 4.3 Payment accounts, methods, providers

| #     | Feature                         | Actor                 | Purpose                       | Inputs → Outputs                                                    | Key rules                                                                                                                              | Depends on | Status | Audit |
| ----- | ------------------------------- | --------------------- | ----------------------------- | ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- | ---------- | ------ | ----- |
| 8     | Bank / UPI account management   | Branch, Admin         | Payment instruments           | Bank details or UPI ID, limits, method flags (QR, intent) → account | NEW → VERIFICATION_PENDING → VERIFIED → ACTIVE; changing details requires re-verification **[Proposal]**; numbers encrypted and masked | 5          | 🟡     | A     |
| 10    | Payment methods                 | Admin, Partner config | Which methods a customer sees | Partner, branch and account flags → offered methods                 | Offered = partner ∩ branch ∩ account ∩ status                                                                                          | 8          | ✅     | A     |
| 9, 40 | Payment providers & credentials | Admin                 | External providers (FFPay, …) | Provider, type, credentials → configured provider                   | Credentials encrypted, never displayed again; role of providers **[Confirm G-37]**                                                     | —          | ❓     | A     |

### 4.4 Pay-in

| #      | Feature                         | Actor                   | Purpose                       | Inputs → Outputs                                                                 | Key rules                                                                                                        | Depends on | Status | Audit                |
| ------ | ------------------------------- | ----------------------- | ----------------------------- | -------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- | ---------- | ------ | -------------------- |
| 37     | Partner API                     | Partner backend         | Integration                   | Signed request → transaction + payment URL; status queries                       | Signed requests, IP whitelist, rate limit, idempotent on the partner transaction ID; contract **[Confirm G-39]** | 3, 42      | 🟡     | S (auth failures), T |
| 11     | Payment session / page          | Customer                | Pay the right account         | Token → page with partner branding, amount, methods, instructions, timer, status | Token unguessable and expiring; refresh keeps the same account; customer never sees internals                    | 12         | ✅     | T                    |
| 12     | Account allocation engine       | System                  | Choose the account            | Partner, amount, method → one eligible account                                   | Eligibility chain (Flow 6); strategy is configurable; default and timing **[Confirm G-29, G-30]**                | 4, 8       | 🟡     | T                    |
| 13     | Pay-in lifecycle                | System, Branch, Admin   | Track a deposit               | Events → state changes (§7.1)                                                    | What makes SUCCESS **[Confirm G-23]**                                                                            | 11, 21     | 🟡     | T                    |
| 22     | Payment proof                   | Customer, Branch, Admin | Evidence                      | Upload → private file linked to transaction                                      | Access via authorized, short-lived links only                                                                    | 13         | ✅     | T                    |
| 23     | Payment status / approval       | Branch, Admin           | Decide deposits               | Approve / reject (reason) / hold → state change + ledger                         | Atomic; approval authority **[Confirm G-44]**                                                                    | 13         | 🟡     | A, T                 |
| 15, 16 | Manual payment / manual deposit | Branch, Admin           | Deposits outside the API path | Customer, amount, bank, UTR, proof, manual link → deposit record                 | Definitions and differences **[Confirm G-28]**                                                                   | 13         | ❓     | A, T                 |

### 4.5 Reconciliation

| #   | Feature                              | Actor                 | Purpose                   | Inputs → Outputs                                          | Key rules                                                                                               | Depends on | Status | Audit |
| --- | ------------------------------------ | --------------------- | ------------------------- | --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- | ---------- | ------ | ----- |
| 18  | Manual A/C statement entry           | Branch, Admin         | Record bank lines by hand | Bank, date, credit/debit, UTR, amount → statement entries | Entry ≠ transaction                                                                                     | 8          | ✅     | A     |
| 19  | Statement import (pay-in)            | Branch, Admin         | Bulk import               | File → parsed, validated entries                          | Formats per bank **[Confirm G-27]**; re-importing the same file never duplicates entries **[Proposal]** | 18         | 🟡     | A     |
| —   | Auto statement entry                 | System                | Automated import          | Source?                                                   | Source **[Confirm G-27]**                                                                               | 19         | ❓     | A     |
| 20  | Import history                       | Branch, Admin         | Traceability              | → date, bank, branch, counts, amount, errors              | —                                                                                                       | 19         | ✅     | —     |
| 21  | UTR matching / reconciliation        | System, Branch, Admin | Prove money arrived       | Entry ↔ transaction on UTR + amount + branch → match      | Tolerances **[Confirm G-24]**                                                                           | 13, 19     | 🟡     | A, T  |
| 17  | Unsettled UTR / reconciliation queue | Branch, Admin         | Resolve exceptions        | Unmatched/mismatched items → case → resolution            | An operational queue, not a transaction status; resolutions **[Confirm G-26]**                          | 21         | 🟡     | A     |

### 4.6 Pay-out

| #   | Feature                         | Actor                        | Purpose                     | Inputs → Outputs                                                | Key rules                                                                              | Depends on | Status | Audit |
| --- | ------------------------------- | ---------------------------- | --------------------------- | --------------------------------------------------------------- | -------------------------------------------------------------------------------------- | ---------- | ------ | ----- |
| 24  | Pay-out                         | Partner (API), Branch, Admin | Customer withdrawal         | Beneficiary, amount → payout assigned to branch → paid with UTR | Partner position/balance check **[Confirm G-15]**; branch selection **[Confirm G-17]** | 12, 26     | 🟡     | T     |
| 25  | Manual payout                   | Branch, Admin                | Payouts outside the API     | Beneficiary, amount, UTR → payout                               | Definition **[Confirm G-28]**                                                          | 24         | ❓     | A, T  |
| 26  | Payout balance                  | Admin, Partner               | What a partner can withdraw | Ledger → balance; "add payout balance"                          | Meaning and funding **[Confirm G-15]**                                                 | 31         | ❓     | A     |
| 28  | Payout refund / return          | Branch, Admin                | Undo a failed payout        | Returned funds → reversal                                       | Definition and effect **[Confirm G-21]**                                               | 24         | ❓     | A, T  |
| 29  | Chargebacks (pay-in and payout) | Admin                        | Bank reversals              | Chargeback notice → reversal postings                           | Who bears the loss **[Confirm G-20]**                                                  | 13, 24     | ❓     | A, T  |

### 4.7 Accounting, settlement, reporting

| #      | Feature                                                                     | Actor           | Purpose                           | Inputs → Outputs                                                                                       | Key rules                                                                | Depends on | Status       | Audit                        |
| ------ | --------------------------------------------------------------------------- | --------------- | --------------------------------- | ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------ | ---------- | ------------ | ---------------------------- |
| 30     | Commission calculation                                                      | System          | Each side's share                 | Transaction + agreement → commission lines                                                             | Separate partner and branch lines; rounding **[Confirm G-04]**           | 13, 24     | 🟡           | T                            |
| —      | Ledger                                                                      | System          | Who owes whom                     | Financial events → balanced, append-only entries; balances derived from them                           | Never `balance = balance + amount`; corrections are reversals            | 30         | ✅ principle | — (the ledger is the record) |
| 31     | Settlement calculation                                                      | Admin (finance) | Net position per party and period | Ledger → settlement (gross in/out, commissions, margin, adjustments, previous balance, net, direction) | Period **[Confirm G-09]**; approval **[Confirm G-10]**                   | Ledger     | 🟡           | A                            |
| —      | External settlement recording                                               | Admin (finance) | Record the real-world payment     | Party, amount, direction, method, reference, date, proof → settles/clears                              | Partial settlements carry forward **[Confirm G-11]**                     | 31         | ✅           | A                            |
| —      | Adjustments                                                                 | Admin           | Corrections, goodwill, disputes   | Party, amount, reason, reference → ledger entries                                                      | Approval rules **[Confirm G-44]**                                        | Ledger     | 🟡           | A                            |
| 32–35  | Reports (pay-in, pay-out, partner, branch, settlement, commission, balance) | All (scoped)    | Visibility                        | Filters → tables, totals                                                                               | Scoped on the server; large exports in background                        | Ledger     | ✅           | —                            |
| 2, 50  | Dashboards (admin, partner, branch)                                         | All (scoped)    | At-a-glance status                | Date filter → metrics                                                                                  | Pre-aggregated; definitions (e.g. "unsettled amount") **[Confirm G-49]** | Ledger     | 🟡           | —                            |
| 44, 45 | Search, filters, export                                                     | All             | Operations                        | Filters → results, CSV/Excel                                                                           | Formats **[Confirm G-49]**                                               | —          | 🟡           | —                            |

### 4.8 Integration, notifications, platform

| #   | Feature               | Actor            | Purpose            | Inputs → Outputs                                               | Key rules                                             | Depends on | Status | Audit |
| --- | --------------------- | ---------------- | ------------------ | -------------------------------------------------------------- | ----------------------------------------------------- | ---------- | ------ | ----- |
| 36  | Webhooks              | System → Partner | Notify the partner | State change → signed webhook, retries, history, manual resend | Never lost (outbox); partner verifies the signature   | 13, 24     | ✅     | T     |
| 38  | Alert notifications   | System → users   | Operational alerts | Events → in-panel / email / other                              | Events and channels **[Confirm G-47]**                | —          | ❓     | —     |
| 39  | Pages / CMS           | Admin            | Static content     | Page content → published page                                  | Scope **[Confirm G-48]**                              | —          | ❓     | A     |
| 41  | Global settings       | Admin            | Platform config    | Settings → effect                                              | List of settings **[Confirm G-48]**                   | —          | ❓     | A     |
| 46  | Security architecture | —                | Protection         | See Architecture.md §11                                        | First-class requirement                               | —          | ✅     | S     |
| 47  | Background processing | System           | Async work         | Jobs → Redis + Horizon                                         | Financial state is never changed only by an async job | —          | 🔨     | —     |

### 4.9 Experience requirements **[Confirmed]**

- One design system shared across all panels (typography, spacing, components, tables, drawers, filters, status badges), with a distinct accent per panel:
    - **Admin:** indigo/blue
    - **Branch:** emerald/teal
    - **Partner:** violet/purple
    - **Customer:** the partner's branding on a neutral fintech design
- The panels are dense with information. Organise it with grouping, tabs, drawers, expandable rows, advanced filters, column customisation, saved views, timelines, badges and tooltips; don't remove information to look clean.
- The reference system is a **functional** reference only. Its visual design is not copied.
- The customer page shows the partner's branding, amount, methods (UPI, bank transfer, QR), instructions, a timer, support details and status. It never shows branch IDs, commissions, settlement, reconciliation or other partners' information.

---

## Phase 5 — End-to-end flows

Flows 1–3 in [Flows.md](Flows.md) hold the step-level detail for the Admin, Partner and Branch views. Below are all 21 business flows at the level needed for approval.

### F1. Admin onboards a partner

1. Admin creates the partner: profile, code, **website URL**, return/callback/webhook URLs, allowed IPs, API version, enabled methods, branding, payout config. Status: `DRAFT`. **[Confirmed fields]**
2. Verification: documents, KYC and review → `PENDING_VERIFICATION` → `VERIFIED`. **[Confirm G-34]**
3. Admin sets deposit and withdrawal commission (commercial agreement with an effective date). **[Confirmed]**
4. Admin maps branches (F3).
5. The system generates API credentials. The secret is shown once and is rotatable. **[Confirmed]** Storage **[Confirm G-40]**.
6. Admin activates the partner, and partner users are invited.

Audit: every step. Result: the partner can call the API.

### F2. Admin onboards a branch

1. Admin creates the branch: name, deposit/withdraw flags, limits, commission, limit type. **[Confirmed]**
2. Verification: KYC, entity documents, operational review. **[Confirm G-35]**
3. Branch users are invited (branch admin, operators).
4. The branch (or Admin) adds bank/UPI accounts → Admin verifies each one. Method **[Confirm G-35]**.
5. Admin maps the branch to partners (F3) and activates it.

### F3. Partner ↔ branch mapping

1. Admin selects a partner and branch → sets status, priority, limits, deposit/withdraw permission, effective dates. **[Confirmed as capabilities; v1 set: Confirm G-31]**
2. Before activating, the system checks that the partner's rate exceeds the branch's rate for each direction (otherwise the spread is negative). Blocked unless explicitly allowed. **[Confirm G-07]**
3. Mapping activated → the branch's eligible accounts enter that partner's allocation pool.
4. Deactivation removes the branch from the pool immediately; in-flight transactions continue. **[Proposal]**

### F4. Partner creates a payment (pay-in)

1. The partner's **backend** calls the API with a signed request: partner transaction ID, amount, customer ID/name/email/mobile, return/callback URL, plus optional fields. **[Confirmed; contract Confirm G-39]**
2. The Gateway checks the signature, timestamp, IP, partner status, API version, amount limits and idempotency. The same partner transaction ID returns the existing transaction. **[Confirmed]**
3. The Gateway creates the transaction (`CREATED`) and a payment session, and returns the gateway transaction ID and payment URL. **[Confirmed]**
4. The partner redirects its customer to the URL.

### F5. Customer pay-in

1. The customer opens the URL. The page shows the partner's branding, amount and available methods. **[Confirmed]**
2. The customer picks a method → allocation (F6) → account details / UPI QR / intent shown, with a timer. **[Confirmed; timing Confirm G-30]**
3. The customer pays from their own bank/UPI **directly into the branch account**. **[Confirmed]**
4. Below the payment details the customer has **two options: enter the UTR number, or upload a photo** (screenshot) of the payment. **[Confirmed]** Whether one of the two is enough, or both can be given **[Confirm G-61]**.
5. The request appears in the **portal of the branch that owns the allocated account**. A branch operator checks their bank; when the money has arrived they click **Approve** (or reject). **[Confirmed]**
6. The approved transaction shows as approved in the partner panel and the partner is notified (webhook), so the partner can credit its customer. **[Confirmed]**
7. Status is shown. On completion the customer is redirected to the partner's return URL (for experience only; the partner relies on the webhook or status API). **[Confirmed]**

### F6. Account allocation

1. Candidates: partner → active mappings → active, deposit-enabled branches → verified + active accounts supporting the method. **[Confirmed]**
2. Filter by transaction min/max, account and branch daily limits, remaining capacity, and open sessions per account. **[Confirmed + Proposal]**
3. Choose one account using the configured strategy. **Default: round robin** across the eligible accounts of the partner's mapped branches **[Confirmed v1.1]**. Other strategies (random, priority, weighted, limit-based) remain possible.
4. Reserve the capacity inside a database transaction with a row lock, so parallel customers never over-allocate. **[Proposal]**
5. No eligible account → the customer sees "temporarily unavailable" and Admin is alerted. The system never guesses. **[Proposal]**

### F7. Payment detection

A payment is detected by one or more of:

- the customer submitting a UTR
- a statement import or manual statement entry containing a matching credit
- the branch confirming it in the panel
- a provider notification, if providers are used

**Answered (v1.1):** SUCCESS happens when the **branch operator approves** after seeing the money in their own bank. The customer's UTR/photo tells the branch what to look for. Statement import and matching (F8) are a **supporting control** (finding missed or wrong approvals, unmatched credits), not the trigger. **[Confirmed]** Whether an exact statement match may ever auto-approve is a later option (G-23).

### F8. UTR reconciliation

1. Statement entries are imported and stored as their own records. **[Confirmed]**
2. The system matches on UTR + amount + branch (bank-entry branch vs transaction branch). **[Confirmed]** Date and amount tolerance **[Confirm G-24]**.
3. Exact match → linked; the pay-in progresses (auto-success or awaiting approval **[Confirm G-23]**).
4. Anything else creates a **reconciliation case** in the unsettled-UTR queue:
    - UTR not found
    - amount mismatch
    - wrong branch
    - duplicate UTR
    - transaction without a bank entry
    - bank entry without a transaction
    - bank entry arrived before the transaction

    **[Confirmed]**

5. A user resolves each case: link, reject, refund, or write off. Resolutions **[Confirm G-26]**. Every resolution is audited.

### F9. Manual deposit

1. Created by a branch or Admin, or arising from a "manual link" or from a customer who paid outside a session: customer, amount, bank details, UTR, proof, deposit type. **[Confirmed fields; definition Confirm G-28]**
2. States: CREATED → PENDING → (PAYMENT_PENDING / HOLD) → APPROVED or DECLINED. **[Confirmed from UI]**
3. Review: UTR and proof checked, ideally against a statement entry. Approval authority **[Confirm G-44]**.
4. Approve = one atomic operation: state change → commission → ledger → webhook event → audit. **[Confirmed]**
5. Decline = reason (configurable list), who, when, audit. **[Confirmed]**

### F10. Manual payout

Created by a branch or Admin outside the API: beneficiary, amount, UTR/reference → approve or decline → ledger → webhook. **[Confirmed fields; definition and authority Confirm G-28, G-44]**

### F11. Customer withdrawal (pay-out)

1. The partner's backend requests a payout: partner transaction ID, beneficiary bank/UPI, amount. **[Confirmed]**
2. The Gateway validates it and checks the **partner's financial position** (can the partner fund it?). **[Confirmed that a check exists; rule Confirm G-15]**
3. The Gateway selects an eligible withdraw-enabled branch with capacity, within the mapping and per-transaction limits. **[Confirmed; rule Confirm G-16, G-17]** (Round robin, as for pay-ins, unless the client says otherwise.)
4. The branch sees the payout in its queue, pays the customer from its own account, and records the UTR, payment details and timestamp. **[Confirmed]**
5. The Gateway records the result: SUCCESS → commission + ledger + webhook. FAILED/REJECTED → release the reservation + webhook. **[Confirmed]**
6. **Answered (v1.1):** the branch's approval with the UTR marks the payout SUCCESS, the same way as pay-ins. Reconciling the debit on the branch's statement is an optional later control.

### F12. Commission calculation

1. Triggered when a transaction reaches **SUCCESS** (pay-in or payout). **[Confirmed v1.3]**
2. Look up the partner rate and branch rate for the direction, using the agreement in force at that moment; store a snapshot of the rates on the transaction. **[Proposal]**
3. Compute the partner commission, branch commission and platform margin (§6). Rounding **[Confirm G-04]**.
4. Commission lines are stored separately from the transaction amount. **[Confirmed]**

### F13. Ledger posting

1. Each financial event posts a **balanced** set of entries to party accounts (partner, branch, platform margin, adjustments). **[Proposal]**
2. Entries are append-only. A correction is a new, reversing entry referencing the original. **[Confirmed principle]**
3. Balances (each party's current position) are projections of the ledger, updated in the same database transaction. **[Confirmed principle]**

### F14. Settlement calculation

1. **Daily**, and additionally **on demand** **[Confirmed v1.3]**, Finance runs the calculation per party.
2. For each party: opening (previous) balance + period ledger movements (pay-ins, pay-outs, commissions, reversals, adjustments) − settlements already recorded = **net position** and **direction** (party → platform or platform → party). **[Confirmed concept]**
3. The settlement record is created as `CALCULATED`, with a breakdown: gross pay-in, gross pay-out, partner commission, branch commission, platform margin, adjustments, previous balance, net, direction.
4. Review and approval → `APPROVED`. **[Confirm G-10]**

### F15. External settlement

1. Money moves **outside** the platform (bank transfer between our company and the party). **[Confirmed]**
2. Admin **marks the amount as settled** (a tick). Reference, date and proof are optional details. **[Confirmed v1.1]**
3. The ledger posts the settlement entry, and the party's position moves toward zero.
4. Status becomes `SETTLED` if the full amount is covered, otherwise `PARTIALLY_SETTLED` (the remainder carries forward). **[Confirm G-11]**

### F16. Settlement reconciliation

Admin view per party and period:

- party, period
- gross pay-in and pay-out
- partner commission, branch commission, platform margin
- adjustments
- previous balance, current position, net settlement, direction
- status, external reference, settled at and by, notes, proof

Differences (paid ≠ calculated) become disputes or adjustments. **[Confirmed fields; dispute process Confirm G-12]**

### F17. Refund

- **Pay-in refund:** the customer paid but the payment must be returned (duplicate, overpaid, or no matching order). The branch returns the money physically, and the platform posts reversing entries. **[Confirm G-22: does this exist?]**
- **Payout refund / return:** a payout marked SUCCESS comes back (invalid beneficiary). Reverse the payout postings, move the transaction to `RETURNED`, and send a webhook. **[Confirm G-21]**

### F18. Chargeback

The customer's bank reverses a completed deposit, so the branch's account is debited. Admin records the chargeback against the transaction → reversal postings (§6.8) → transaction `CHARGEBACK` → webhook to the partner. Who bears the loss, commission treatment and fees **[Confirm G-20]**.

### F19. Webhook

A state change writes a webhook event in the **same database transaction** (outbox). A queue worker then POSTs a signed payload to the partner URL (pay-in or payout webhook). A 2xx response counts as delivered. **[Confirmed]**

### F20. Failed webhook and retry

Non-2xx or timeout → retry with backoff (e.g. 1m, 5m, 15m, 1h, 6h, 24h) → `FAILED` after the last attempt → visible to Admin and the partner with the full attempt history → manual resend. The partner can always fetch the status via the API. **[Confirmed; schedule Proposal]**

### F21. Dispute and adjustment

1. Disputes can come from a partner (e.g. "customer paid, not credited"), a branch (e.g. "settlement amount wrong") or Admin.
2. A **case** is opened with evidence → investigation → resolution: no change, transaction correction (via state machine), or an **adjustment** (ledger entries with reason, reference and approver). **[Proposal; process Confirm G-12]**
3. Adjustments never edit history. They appear in the next settlement.

---

## Phase 6 — Financial model

### 6.1 Conventions **[Proposal unless stated]**

- The **platform is the central counterparty** in the books: branches settle with the platform, and the platform settles with partners. **[Confirmed v1.1]**
- **Commission direction [Confirmed v1.2]:** the **partner always pays** commission, and the **branch always earns** it (paid by the platform). A branch never pays commission. When a branch settles deposits it passes on the **customer money it collected** (net of its commission); that is principal, not a commission payment.
- Each party has a **position** derived from the ledger. Sign used in this document: **positive = the platform owes the party**, **negative = the party owes the platform**.
- Amounts are stored as whole paise (₹1 = 100 paise). Rounding rule **[Confirm G-04]**.
- A commission is a percentage of the transaction amount unless the agreement says otherwise (future: fixed, slab, tiered, effective dates). **[Confirmed that this must be extensible]**

### 6.2 Example 1: deposit (from the brief)

Partner A deposit rate 6%, Branch A deposit rate 3%. The customer deposits **₹100** into Branch A's account.

| Line                         | Amount     | Meaning                                             |
| ---------------------------- | ---------- | --------------------------------------------------- |
| Money physically at Branch A | ₹100.00    | Customer → Branch A                                 |
| Branch A commission (3%)     | ₹3.00      | The branch keeps this                               |
| **Branch A owes Platform**   | **₹97.00** | Branch position −97.00                              |
| Partner A commission (6%)    | ₹6.00      | Partner pays this                                   |
| **Platform owes Partner A**  | **₹94.00** | Partner position +94.00                             |
| **Platform margin**          | **₹3.00**  | 97 − 94; recorded, not received at transaction time |

Ledger (balanced): Branch A **−97.00** · Partner A **+94.00** · Platform margin **+3.00** → −97 + 94 + 3 = 0 ✓

### 6.3 Example 2: same partner, different branch

Partner A (6%) customer deposits **₹100**, split across two payments: ₹60 via Branch A (3%) and ₹40 via Branch B (2.5%).

| Party           | Calculation           | Position    |
| --------------- | --------------------- | ----------- |
| Branch A        | 60 − 3%               | owes ₹58.20 |
| Branch B        | 40 − 2.5%             | owes ₹39.00 |
| Partner A       | 100 − 6%              | owed ₹94.00 |
| Platform margin | 58.20 + 39.00 − 94.00 | **₹3.20**   |

The partner's entitlement doesn't depend on which branch served the customer, but the platform's margin does.

### 6.4 Example 3: withdrawal ₹90 **[Confirmed v1.2: option W-A]**

Partner A withdrawal rate 5%, Branch A withdrawal rate 3% (brief §8). Branch A pays **₹90** to the customer from its own account. The client confirmed that the partner pays and the branch earns, so **W-A applies**. W-C is kept below only for the record.

**Option W-A: commission on top. ✅ Confirmed (partner pays, branch earns)**

| Line                             | Amount     | Meaning                 |
| -------------------------------- | ---------- | ----------------------- |
| Paid by Branch A to the customer | ₹90.00     | Physical                |
| Partner A commission (5%)        | ₹4.50      | Partner pays            |
| **Partner A owes Platform**      | **₹94.50** | Partner position −94.50 |
| Branch A commission (3%)         | ₹2.70      | Branch earns            |
| **Platform owes Branch A**       | **₹92.70** | Branch position +92.70  |
| **Platform margin**              | **₹1.80**  | 94.50 − 92.70           |

**Option W-C (rejected v1.2): fee deducted from the customer's payout.** The customer receives ₹85.50 (₹90 − 5%); Branch A pays ₹85.50 and earns 3% of that = ₹2.565, which **rounds to ₹2.57** (a live example of the rounding question G-04). Partner owes ₹90.00; Platform owes Branch ₹88.07; margin ₹1.93.

W-C is rejected because in it the customer, not the partner, would bear the fee. The examples below use the confirmed W-A.

### 6.5 Example 4: period net settlement

One period, Partner A ↔ Branch A only. Rates: Partner A 6% deposit / 5% withdrawal; Branch A 3% / 3%.
Customer deposits ₹50,00,000; customer withdrawals ₹30,00,000.

|                        | Branch A                | Partner A                | Platform margin |
| ---------------------- | ----------------------- | ------------------------ | --------------- |
| Deposits ₹50,00,000    | owes 48,50,000          | owed 47,00,000           | +1,50,000       |
| Withdrawals ₹30,00,000 | owed 30,90,000          | owes 31,50,000           | +60,000         |
| **Net for the period** | **owes ₹17,60,000**     | **owed ₹15,50,000**      | **+₹2,10,000**  |
| **Direction**          | **Branch A → Platform** | **Platform → Partner A** |                 |

**Cash check** (the books agree with physical reality):

- Branch A physically holds 50,00,000 − 30,00,000 = ₹20,00,000 of net customer money. It keeps its commissions (1,50,000 + 90,000 = ₹2,40,000) and passes on **₹17,60,000** ✓
- Partner A's customers put in a net ₹20,00,000. Partner A pays commissions of 3,00,000 + 1,50,000 = ₹4,50,000 and receives **₹15,50,000** ✓
- Platform: receives 17,60,000, pays 15,50,000, keeps **₹2,10,000** = deposit spread 1,50,000 + withdrawal spread 60,000 ✓

**Carry-forward and partial settlement:** if Branch A already owed ₹1,00,000 from the previous period, the amount due is ₹18,60,000. If Branch A pays ₹10,00,000 (reference `EXT-SET-00123`), the settlement becomes `PARTIALLY_SETTLED` and ₹8,60,000 carries forward. **[Confirm G-11]**

### 6.6 Example 5: negative margin guard

Partner C deposit rate 2%, Branch A deposit rate 3%, deposit ₹100 → Partner owed ₹98, Branch owes ₹97 → **platform margin −₹1**. The system blocks this configuration (mapping or agreement) unless an authorised admin explicitly allows a negative margin, with a reason. **[Confirmed: must be validated; Confirm G-07 whether ever allowed]**

### 6.7 Example 6: payout returned (refund of a payout) **[Confirm G-21]**

The ₹90 payout from Example 3 (W-A) is marked SUCCESS, but the beneficiary's bank returns ₹90 to Branch A two days later. Proposed reversal: Partner A **+94.50** (the charge is undone), Branch A **−92.70** (the payment it was credited for is undone), margin **−1.80**. The transaction moves to `RETURNED`, and the partner receives a webhook so it can re-credit its customer. Whether commissions are fully reversed or a fee is retained **[Confirm]**.

### 6.8 Example 7: chargeback on a deposit **[partly confirmed v1.3]**

The ₹100 deposit from Example 1 was SUCCESS and possibly already settled. The customer's bank reverses it, and Branch A's account is debited ₹100.

| Option                                         | Branch A                             | Partner A | Platform     | Who bears the loss                                                  |
| ---------------------------------------------- | ------------------------------------ | --------- | ------------ | ------------------------------------------------------------------- |
| ~~CB-A: full reversal~~ **rejected v1.3**      | +97.00                               | −94.00    | −3.00 margin | Would cost the platform its margin; the platform never bears a loss |
| **CB-B: principal reversal, commissions kept** | +100.00                              | −100.00   | 0            | Partner bears the full amount including fees                        |
| **CB-C: branch bears it**                      | 0 (the branch absorbs the ₹100 loss) | 0         | 0            | Branch                                                              |

**v1.3:** the platform never bears a loss, so CB-A is rejected. Whether the **partner** (CB-B) or the **branch** (CB-C) absorbs a chargeback is still open, and may depend on the case (e.g. whether the branch approved a payment that never arrived). The ledger supports both through reversal postings, so this **does not block database design**. Chargeback fees, time limits and evidence are also open **[Confirm G-20]**.

### 6.9 Example 8: adjustment

A matching error overstated Branch A's obligation by ₹500. Admin creates an adjustment: Branch A **+500.00**, platform adjustments account **−500.00**, reason "Duplicate statement line 26-09", reference to the case, approved by a second admin **[Confirm G-44]**. It appears as a separate line in the next settlement. The original entries are never edited.

### 6.10 Summary: who owes whom

| Event                         | Branch                               | Partner                              | Platform             |
| ----------------------------- | ------------------------------------ | ------------------------------------ | -------------------- |
| Deposit ₹X                    | owes X − branch commission           | is owed X − partner commission       | earns the difference |
| Withdrawal ₹X                 | is owed X + branch commission        | owes X + partner commission          | earns the difference |
| Reversal (return, chargeback) | mirror of the original **[Confirm]** | mirror of the original **[Confirm]** | mirror **[Confirm]** |
| Adjustment                    | ± as approved                        | ± as approved                        | opposite side        |
| External settlement           | moves the position toward zero       | moves the position toward zero       | opposite side        |

---

## Phase 7 — State machines (proposed)

Principles **[Proposal]**:

- A single state machine per object; only defined transitions are allowed.
- Every transition records who, when, from, to, reason and request ID.
- Terminal financial states are never "undone"; reversals create a new state (`RETURNED`, `CHARGEBACK`) plus ledger entries.
- **Settlement is not a transaction status.** "Unsettled / settled" is tracked by the settlement module (a transaction is either included in a settlement or not). This removes the mix of statuses in the reference system (C-10).

### 7.1 Pay-in (API deposit)

| State             | Meaning                                                     | Next                                                    | Label                                                 |
| ----------------- | ----------------------------------------------------------- | ------------------------------------------------------- | ----------------------------------------------------- |
| CREATED           | API request accepted; payment URL issued                    | AWAITING_PAYMENT, EXPIRED, CANCELLED                    | Proposal                                              |
| AWAITING_PAYMENT  | Customer opened the page; account allocated and reserved    | PAYMENT_SUBMITTED, PAYMENT_DETECTED, EXPIRED, CANCELLED | Proposal (the reference system calls this PENDING)    |
| PAYMENT_SUBMITTED | Customer submitted UTR/proof                                | UNDER_REVIEW, PAYMENT_DETECTED, REJECTED                | Proposal                                              |
| PAYMENT_DETECTED  | A matching statement entry was found                        | UNDER_REVIEW, SUCCESS                                   | Proposal                                              |
| UNDER_REVIEW      | Waiting for a branch/admin decision, or HOLD                | SUCCESS, REJECTED                                       | Confirmed (HOLD exists); authority **[Confirm G-44]** |
| **SUCCESS**       | Money confirmed; commission and ledger posted; webhook sent | CHARGEBACK, REFUNDED                                    | Confirmed                                             |
| REJECTED          | Not paid / invalid UTR / declined                           | (late match → reopen?) **[Confirm G-25]**               | Confirmed                                             |
| EXPIRED           | No payment before expiry                                    | late payment → SUCCESS? **[Confirm G-25]**              | Confirmed                                             |
| CANCELLED         | Cancelled by partner or admin before payment                | —                                                       | Proposal                                              |
| CHARGEBACK        | Reversed by the customer's bank after success               | —                                                       | Confirmed (exists); effect **[Confirm G-20]**         |
| REFUNDED          | Returned to the customer                                    | —                                                       | **[Confirm G-22]**                                    |

**v1.1:** UNDER_REVIEW → SUCCESS is the **branch operator's approval** (Confirmed). PAYMENT_DETECTED (from statement matching) is a helper signal only.

### 7.2 Pay-out (API withdrawal)

| State       | Meaning                                           | Next                                         | Label                                              |
| ----------- | ------------------------------------------------- | -------------------------------------------- | -------------------------------------------------- |
| CREATED     | Request accepted                                  | VALIDATED, REJECTED                          | Proposal                                           |
| VALIDATED   | Balance/position check passed; amount reserved    | ASSIGNED, REJECTED, CANCELLED                | Proposal; rule **[Confirm G-15]**                  |
| ASSIGNED    | Sent to a branch's queue                          | PROCESSING, REASSIGNED → ASSIGNED, CANCELLED | Proposal; can a branch decline? **[Confirm G-17]** |
| PROCESSING  | Branch accepted and is paying                     | SUCCESS, FAILED                              | Proposal                                           |
| **SUCCESS** | Branch paid; UTR recorded; ledger posted; webhook | RETURNED, CHARGEBACK                         | Confirmed                                          |
| FAILED      | Could not be paid; reservation released           | —                                            | Confirmed                                          |
| REJECTED    | Invalid request, or insufficient balance          | —                                            | Proposal                                           |
| CANCELLED   | Cancelled before processing                       | —                                            | Proposal                                           |
| RETURNED    | Paid, then bounced back (payout refund)           | —                                            | **[Confirm G-21]**                                 |

Partial withdrawals and auto-withdrawal may add states **[Confirm G-19]**.

### 7.3 Manual deposit (states from the reference UI) **[Confirmed names, meanings Confirm G-28]**

CREATED → PENDING → PAYMENT_PENDING / HOLD → APPROVED or DECLINED. **[Proposal]** If a manual deposit is simply a pay-in created outside the API, it should share the §7.1 machine and map APPROVED = SUCCESS and DECLINED = REJECTED.

### 7.4 Manual payout

CREATED → PENDING → PROCESSING → APPROVED (paid) or DECLINED, with HOLD available. **[Proposal; Confirm G-28]** Ideally the same machine as §7.2.

### 7.5 Payment account (bank / UPI)

NEW → VERIFICATION_PENDING → VERIFIED → ACTIVE ⇄ PAUSED → DISABLED; VERIFICATION_PENDING → REJECTED. Changing details → back to VERIFICATION_PENDING. Only **VERIFIED + ACTIVE** is eligible. **[Confirmed except PAUSED and re-verification = Proposal]**

### 7.6 Statement entry / UTR

IMPORTED → MATCHED → RECONCILED; IMPORTED → UNMATCHED → (case) → MATCHED / IGNORED (not a customer payment, e.g. bank charges) / RETURNED; IMPORTED → DUPLICATE. **[Proposal]**

### 7.7 Reconciliation case (unsettled-UTR queue)

OPEN → IN_REVIEW → RESOLVED (resolution: LINKED / REJECTED / REFUNDED / WRITTEN_OFF / ADJUSTED) · REOPENED. The reference system shows UNMATCHED, MATCHED, PENDING_REVIEW, RECONCILED, REJECTED. **[Proposal; resolutions Confirm G-26]**

### 7.8 Settlement **[states from the brief, not final]**

CALCULATED → PENDING_REVIEW → APPROVED → PARTIALLY_SETTLED → SETTLED; any state before SETTLED → DISPUTED → back to PENDING_REVIEW; CALCULATED / PENDING_REVIEW → CANCELLED (recalculated). **[Confirm G-10, G-11, G-12]**

### 7.9 Webhook delivery

PENDING → DELIVERED; PENDING → RETRYING → DELIVERED / FAILED; FAILED → PENDING via manual resend. **[Confirmed concept; Proposal names]**

### 7.10 Partner / branch lifecycle

DRAFT → PENDING_VERIFICATION → ACTIVE ⇄ SUSPENDED → OFFBOARDED; PENDING_VERIFICATION → REJECTED. **[Proposal; Confirm G-34, G-35]**

---

## Phase 8 — Core domain modules

Each module is a folder in one Laravel application (a modular monolith), not a separate service.

| Module                         | Responsible for                                                          | Owns                                      | Key rules                                                                                          |
| ------------------------------ | ------------------------------------------------------------------------ | ----------------------------------------- | -------------------------------------------------------------------------------------------------- |
| **Identity & Access**          | Users, login, 2FA, roles, permissions, sessions                          | User, Role, Permission                    | Server-side checks; tenant scoping                                                                 |
| **Partner Management**         | Partner profile, verification, credentials, URLs, branding, IP whitelist | Partner, API credential, IP rule          | Secret shown once; rotation                                                                        |
| **Branch Management**          | Branch profile, verification, limits, users                              | Branch                                    | Deposit/withdraw flags; limits                                                                     |
| **Network (Mapping)**          | Partner↔branch mappings                                                  | Mapping                                   | Status, priority, limits, permissions, effective dates                                             |
| **Commercial Agreements**      | Commission rates and versions                                            | Agreement, rate plan                      | Separate partner and branch rates × deposit and withdrawal; effective dates; negative-spread guard |
| **Payment Accounts**           | Bank/UPI accounts, verification, capacity                                | Payment account, daily usage              | Only VERIFIED + ACTIVE are eligible                                                                |
| **Payment (Pay-in / Pay-out)** | Transactions, sessions, payment page, state machines                     | Transaction, session, proof               | Idempotency; state machine                                                                         |
| **Allocation**                 | Choosing the account (pay-in) or branch (pay-out)                        | Strategy config                           | Pluggable strategies; row-locked reservation                                                       |
| **Providers**                  | External provider adapters (FFPay, …)                                    | Provider, credentials                     | Provider-agnostic interface **[Confirm G-37]**                                                     |
| **Reconciliation**             | Statement import, entries, matching, cases                               | Statement import, entry, match, case      | Entry ≠ transaction; exception queue                                                               |
| **Commission**                 | Calculating commission lines                                             | Commission line                           | Rates snapshotted per transaction                                                                  |
| **Ledger**                     | Double-entry postings and balances                                       | Ledger account, entry, balance projection | Append-only; balanced; corrections by reversal                                                     |
| **Settlement**                 | Period calculation, approval, external records                           | Settlement, settlement payment            | Calculation + reconciliation + recording, no money movement                                        |
| **Reporting**                  | Reports, dashboards, exports                                             | Aggregates, export jobs                   | Scoped; background generation for large reports                                                    |
| **Webhooks**                   | Outbox, delivery, retries, logs                                          | Webhook event, delivery attempt           | Signed; never lost                                                                                 |
| **Notifications**              | Alerts to users                                                          | Notification                              | Events and channels **[Confirm G-47]**                                                             |
| **Audit**                      | Audit, security and activity logs                                        | Audit log, security log                   | Append-only (already built)                                                                        |
| **Security**                   | Signing, encryption, rate limits, WAF rules                              | Keys, policies                            | Cross-cutting                                                                                      |
| **Platform Settings & CMS**    | Global settings, pages                                                   | Setting, page                             | **[Confirm G-48]**                                                                                 |

---

## Phase 9 — Requirements gaps

Nothing below has been assumed. **Blocks** shows what must wait for the answer: **DB** = database design · **Phase** = only the feature's own build phase.
Old question IDs from Features.md §4 are shown in brackets so nothing is lost.

### 9.1 Money, commission and accounting

| ID   | Question                                                                                                                                                                                                                                    | Why it matters                      | Options                                  | Recommended approach                                                                                                             | Blocks |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------- | ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ------ |
| G-01 | ✅ **Answered v1.1:** the platform is **always** the central counterparty. Commission is collected from both parties outside the platform.                                                                                                  | —                                   | —                                        | Ledger has partner, branch and platform accounts; see G-60 for how income is calculated                                          | —      |
| G-02 | ✅ **Answered v1.2:** option **W-A**. The partner pays amount + its withdrawal commission; the platform owes the branch amount + its withdrawal commission; the margin is the difference.                                                   | —                                   | —                                        | As §6.4                                                                                                                          | —      |
| G-03 | Is commission calculated on the **requested** amount or the **actually received** amount (e.g. the customer paid ₹4,990 on a ₹5,000 request)?                                                                                               | Partial and over-payments           | Requested / received / reject mismatches | Use the received amount, and send mismatches to a reconciliation case                                                            | DB     |
| G-04 | **Rounding**: to the paisa, per transaction; half-up or banker's rounding; who absorbs the fraction?                                                                                                                                        | Totals must match to the paisa      | Per transaction half-up / per period     | Per transaction, half-up, with the rounding difference booked to the platform margin                                             | DB     |
| G-05 | ✅ **Answered v1.3:** commission is counted when the transaction reaches **SUCCESS**.                                                                                                                                                       | —                                   | —                                        | Commission lines and ledger postings are created in the same database transaction as the SUCCESS change                          | —      |
| G-06 | ✅ **Answered v1.1:** rates are negotiated offline (by phone). Admin enters the final **deposit** and **withdrawal** commission for each partner and each branch. Our reading: no separate rate per partner↔branch combination (check G-59) | —                                   | —                                        | Commission settings on the partner and branch records with change history (audited); the rate is snapshotted on each transaction | —      |
| G-07 | ✅ **Answered v1.4:** Admin is responsible; a negative margin shows a clear **warning** but is allowed (audited).                                                                                                                           | —                                   | —                                        | Warning + audit log entry                                                                                                        | —      |
| G-08 | Are there **other charges**: fixed fees, GST on commission, TDS, chargeback fees? Do we issue invoices?                                                                                                                                     | Ledger accounts, reports, invoicing | None / fees / GST + invoicing            | Keep a generic "charges" line type in the ledger; confirm tax needs with an accountant                                           | DB     |
| G-52 | INR only, or multiple currencies?                                                                                                                                                                                                           | Amount model                        | INR only / multi                         | INR only in v1, with a currency field kept for later                                                                             | DB     |

### 9.2 Settlement

| ID   | Question                                                                                                                                                      | Why it matters                      | Options                                             | Recommended approach                                                     | Blocks |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------- | --------------------------------------------------- | ------------------------------------------------------------------------ | ------ |
| G-09 | ✅ **Answered v1.3:** settlement runs **daily**, plus **on demand**. Still open: the daily cut-off time (e.g. 00:00 IST)?                                     | Period boundaries                   | —                                                   | Cut-off 00:00 Asia/Kolkata unless told otherwise                         | Phase  |
| G-10 | 🟡 **Partly answered v1.1:** settling = Admin **ticks the amount as settled**. Still open: is a second person's approval ever needed, e.g. for large amounts? | Controls                            | One admin / two admins                              | One admin ticks; optional second approval above a configurable threshold | Phase  |
| G-11 | **Partial settlement and carry-forward**: allowed? Does an unpaid remainder roll into the next period?                                                        | Settlement states                   | Yes / no                                            | Yes; the remainder stays in the party's ledger position                  | DB     |
| G-12 | **Dispute process** for settlements and transactions: who can raise one, SLA, evidence, outcomes?                                                             | Case management                     | Simple notes / formal cases                         | Formal case with status, evidence, resolution and resulting adjustment   | Phase  |
| G-13 | 🟡 **Partly answered v1.1:** a tick is enough; proof is not required.                                                                                         | Audit                               | —                                                   | Optional reference, note and file on the tick; always audited            | Phase  |
| G-14 | In the reference system, what do "**Unsettled / Settled**" on transactions mean?                                                                              | Avoid mixing status with settlement | Included in a settlement or not / reconciled or not | Track it through the settlement module, not the transaction status       | DB     |

### 9.3 Pay-out and balances

| ID   | Question                                                                                                                                                                                                                                                                                                                                                   | Why it matters        | Options              | Recommended approach                                                                                           | Blocks |
| ---- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------- | -------------------- | -------------------------------------------------------------------------------------------------------------- | ------ |
| G-15 | ✅ **Answered v1.4:** a payout needs enough **partner balance with the associated branch**; otherwise the API returns "balance is low". Balance = the partner's ledger position for that partner↔branch pair (deposits net of commission − payouts incl. commission ± settlements/adjustments), which is what the reference "Payout Balance Report" shows. | —                     | —                    | Pair-level ledger sub-accounts; payout checks and reserves the pair balance under a row lock (see Database.md) | —      |
| G-16 | ✅ **Answered v1.4:** capacity is tracked **per partner↔branch pair** (see G-15). Branch and account deposit/withdrawal limits are set by Admin (branch) and the branch (accounts, within the branch limit). "Assign top-up balance" = Admin adding payout balance for a pair, recorded as an adjustment of type _top-up_ **[Proposal]**.                  | —                     | —                    | As G-15                                                                                                        | —      |
| G-17 | **Payout routing**: how is the branch chosen (same strategies as pay-in)? Can a branch decline or time out, and is the payout then reassigned? Can Admin assign manually? [Q-OUT-4]                                                                                                                                                                        | Payout flow, states   | Auto / manual / both | Auto with admin override; timeout → reassign                                                                   | Phase  |
| G-18 | ✅ **Answered v1.1:** the branch's approval with the UTR marks a payout SUCCESS (same as pay-in).                                                                                                                                                                                                                                                          | —                     | —                    | Statement debit reconciliation as an optional later control                                                    | —      |
| G-19 | Meaning of payout **group**, **limit type**, **auto withdrawal**, **partial withdrawal**, **withdraw URL**. [Q-OUT-2]                                                                                                                                                                                                                                      | Partner config fields | —                    | Needs client explanation                                                                                       | Phase  |

### 9.4 Refunds and chargebacks

| ID   | Question                                                                                                                                                                                                                 | Why it matters                             | Options                       | Recommended approach                                                                     | Blocks |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------ | ----------------------------- | ---------------------------------------------------------------------------------------- | ------ |
| G-20 | 🟡 **Partly answered v1.3:** the **platform never bears a loss** (CB-A rejected). Still open: does the partner (CB-B) or the branch (CB-C) absorb a chargeback, and can it differ per case? Fees, time limits, evidence? | Loss allocation between partner and branch | CB-B / CB-C / per case        | Admin picks the responsible party per case; the ledger posts the matching reversal       | Phase  |
| G-21 | **Payout refund / return**: what is it exactly (a bounced payout, or refunding the partner for a failed payout)? Commission treatment? [Q-RCB-1]                                                                         | Reversal logic                             | §6.7 full reversal / keep fee | Full reversal unless the client says otherwise                                           | Phase  |
| G-22 | Do **pay-in refunds** exist (overpaid, duplicate, no order)? Who initiates, and who returns the money?                                                                                                                   | Flows and states                           | Yes / no                      | If yes: the branch returns it physically, the platform records it, and reverses postings | Phase  |

### 9.5 Pay-in confirmation and reconciliation

| ID   | Question                                                                                                                                                                                                                                                           | Why it matters              | Options                      | Recommended approach                                                                                                           | Blocks |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------- | ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------ | ------ |
| G-23 | ✅ **Answered v1.1:** SUCCESS = the **branch operator approves** after seeing the money in their bank. Customer UTR/photo is the hint; statement matching is a supporting control. Still optional: auto-approve on an exact statement match later? [C-04 resolved] | —                           | —                            | Branch approval queue is the core pay-in screen; auto-approve can be switched on per branch later                              | —      |
| G-24 | **Matching tolerances**: date window, amount differences, partial/over-payments, UTR format variations. [Q-REC-1]                                                                                                                                                  | Match rate, fraud           | Exact only / tolerances      | Exact UTR + amount; anything else becomes a case                                                                               | Phase  |
| G-25 | **Late payments** (money arrives after expiry or after rejection): reopen and succeed, or refund?                                                                                                                                                                  | States, customer experience | Late-confirm / refund / case | Case → late-confirm with a flag, which notifies the partner                                                                    | Phase  |
| G-26 | **Unmatched bank credits** and case resolutions: attach to a transaction, refund to the customer, write off? Who decides?                                                                                                                                          | Reconciliation outcomes     | —                            | Resolution types as in §7.7; approval for write-offs                                                                           | Phase  |
| G-27 | **Statement sources and formats**: which banks, CSV/XLS/PDF layouts? What is "Auto A/C statement entry": a bank API, email, SMS or scraping? [Q-STM-1/2]                                                                                                           | Import design, security     | —                            | Start with CSV/XLS templates per bank; decide auto-import separately (a security review is needed if it uses bank credentials) | Phase  |
| G-28 | ✅ **Answered v1.4:** manual deposit / manual payout = the standard pay-in / payout with **manual branch confirmation**; one transaction model with an `origin` field. "Manual link" is out of v1 (OOS-13).                                                        | —                           | —                            | —                                                                                                                              | —      |

### 9.6 Allocation and mapping

| ID   | Question                                                                                                                                                        | Why it matters           | Options  | Recommended approach                                      | Blocks |
| ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------ | -------- | --------------------------------------------------------- | ------ |
| G-29 | ✅ **Answered v1.1:** default strategy is **round robin** over the eligible accounts of the partner's mapped branches. Still open: set globally or per partner? | —                        | —        | Round robin by default, configurable per partner          | Phase  |
| G-30 | ✅ **Answered v1.4:** allocation happens when the customer opens our payment page / picks a method. [C-06 resolved]                                             | —                        | —        | Row-locked reservation at that moment                     | —      |
| G-31 | Which **mapping attributes** are needed in v1: priority, limits, deposit/withdraw permission, effective dates? [Q-MAP-3]                                        | Mapping model            | —        | Include all in the model; expose them in the UI as needed | DB     |
| G-32 | Can a single **account be excluded** for one partner while its branch stays mapped? [Q-MAP-1]                                                                   | Mapping granularity      | Yes / no | Optional exclusion list on the mapping                    | DB     |
| G-33 | Cap on **open sessions per account**, and does the same customer get the same account next time ("stickiness")?                                                 | Matching accuracy, fraud | —        | A cap is recommended; stickiness is optional              | Phase  |
| G-50 | What is **"Branch Map"**: a geographic map or the mapping screen? [Q-MAP-2]                                                                                     | Scope                    | —        | Needs client explanation                                  | Phase  |

### 9.7 Trust and verification

| ID   | Question                                                                                                                                        | Why it matters               | Options | Recommended approach                                | Blocks |
| ---- | ----------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------- | ------- | --------------------------------------------------- | ------ |
| G-34 | **Partner verification**: which documents, KYC, business checks, approval steps?                                                                | Onboarding flow, stored data | —       | Configurable document checklist + admin approval    | DB     |
| G-35 | **Branch and account verification**: branch KYC and entity documents; how bank/UPI accounts are verified (penny drop, test deposit, documents)? | Trust, the core value        | —       | Checklist + account verification record per account | DB     |
| G-36 | **Re-verification and risk**: periodic reviews, risk scores, automatic suspension triggers (e.g. many rejected deposits)?                       | Risk controls                | —       | Phase 2+: store risk flags now, automate later      | Phase  |

### 9.8 Providers

| ID   | Question                                                                                                     | Why it matters | Options              | Recommended approach                                                                        | Blocks |
| ---- | ------------------------------------------------------------------------------------------------------------ | -------------- | -------------------- | ------------------------------------------------------------------------------------------- | ------ |
| G-37 | ✅ **Decided v1.3:** the client doesn't know FFPay's role, so **external providers are out of v1** (OOS-11). | —              | —                    | Keep the ledger's counterparty model open so a provider can be added later without redesign | —      |
| G-38 | **Dynamic QR**: generated by us (a UPI QR string for the allocated account) or by a provider? [Q-PMT-1]      | Payment page   | Our QR / provider QR | Generate our own UPI QR for the allocated account                                           | Phase  |

### 9.9 API and integration

| ID   | Question                                                                                                                                                                                                        | Why it matters        | Options               | Recommended approach                                                          | Blocks |
| ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------- | --------------------- | ----------------------------------------------------------------------------- | ------ |
| G-39 | ✅ **Answered v1.3:** the legacy API is a **reference only**; we design our own API and reuse useful ideas. [Q-API-1, C-09]                                                                                     | —                     | —                     | New versioned API (see Architecture.md §10)                                   | —      |
| G-40 | ✅ **Resolved:** partners get a **secret key** (client confirmed v1.3). We use it for HMAC request and webhook signing, so it is stored **encrypted** (retrievable), shown once, and rotatable. [C-01 resolved] | —                     | —                     | Encrypted key + IP whitelist + signed requests                                | —      |
| G-41 | Per-request **return/callback URLs** allowed? Must they match the partner's domain? What is "**H2H**"? Callback vs webhook? [Q-API-2/3/4]                                                                       | Security, integration | —                     | Allow only URLs on the partner's registered domains; H2H needs an explanation | Phase  |
| G-42 | **Idempotency conflicts** (same partner transaction ID, different amount) and **gateway ID format**. [Q-API-5, C-07, C-08]                                                                                      | API behaviour         | 409 / return original | Return 409 on a different body; IDs = date + random suffix                    | Phase  |

### 9.10 Customers, access, approvals

| ID   | Question                                                                                                                                                             | Why it matters                     | Options                                | Recommended approach                                                                                               | Blocks |
| ---- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ------ |
| G-43 | What **customer data** do we keep (ID, name, mobile, email) and for how long? Do we need customer-level history or blocking? [Q-CUS-1]                               | Privacy law (DPDP), fraud controls | Per-transaction only / customer record | A minimal customer record keyed by (partner, customer ID); retention policy set with legal                         | DB     |
| G-44 | **Approval authority matrix**: who may approve or decline deposits, payouts, adjustments, settlements, negative margins; amount thresholds; maker-checker? [Q-MAN-3] | Controls, roles                    | —                                      | A matrix with thresholds; second approval above the threshold                                                      | Phase  |
| G-45 | Are **roles editable** by Admin (the reference UI suggests yes)? Per-user permission overrides? Can partner/branch owners manage their own users? [Q-ROLE-2, C-03]   | Changes Phase 1                    | —                                      | Editable roles in the database, permission catalogue in code, no per-user overrides, owners manage their own users | Phase  |
| G-46 | **2FA policy** (mandatory for admin/branch as built, or optional)? IP restriction for **panel** logins too? [Q-AUTH-1, Q-IP-1, C-02]                                 | Security                           | —                                      | Keep mandatory; optional IP allow-list for the admin panel                                                         | Phase  |

### 9.11 Operations, reporting, legal, technical

| ID   | Question                                                                                                                                                         | Why it matters                                   | Options                           | Recommended approach                                                                            | Blocks  |
| ---- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------ | --------------------------------- | ----------------------------------------------------------------------------------------------- | ------- |
| G-47 | **Alerts**: which events, which recipients, which channels (panel, email, SMS, Telegram)? [Q-NTF-1]                                                              | Notification module                              | —                                 | In-panel + email first                                                                          | Phase   |
| G-48 | **Pages/CMS** scope and the list of **global settings**. [Q-CMS-1, Q-CFG-1]                                                                                      | Scope                                            | —                                 | List to be provided by the client                                                               | Phase   |
| G-49 | **Reports**: exact list, export formats (CSV/Excel/PDF), metric definitions (e.g. "unsettled amount", "net balance"), data retention. [Q-UX-1, Q-DSH-1, Q-SET-1] | Reporting design                                 | —                                 | CSV + Excel first; definitions written into a metrics glossary                                  | Phase   |
| G-51 | **Legal/regulatory review** of the operating model (RBI payment aggregator / Payment and Settlement Systems Act, KYC/AML, record retention, DPDP)                | Can change what we must store, report and retain | —                                 | Review with a compliance advisor **before go-live**; the design already keeps full audit trails | Go-live |
| G-53 | Tech alignment: the brief lists **Nginx**; the current setup uses **FrankenPHP** (built-in Caddy web server, official Octane driver)                             | Deployment only                                  | FrankenPHP alone / Nginx in front | Keep FrankenPHP; add Nginx in front only if the hosting standard requires it                    | —       |

### 9.12 New questions from the client's answers and the legacy API (v1.1)

| ID   | Question                                                                                                                                                                                                 | Why it matters                                | Options                | Recommended approach                                                                                                                      | Blocks |
| ---- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- | ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| G-54 | ✅ **Answered v1.3:** no compatibility needed; existing partners will integrate with our new API.                                                                                                        | —                                             | —                      | Publish clear API docs and a sandbox for partners to migrate                                                                              | —      |
| G-55 | ⚪ **Not needed v1.3** (legacy API is only a reference). Our API will have explicit fields: partner customer ID, username, email, mobile.                                                                | —                                             | —                      | —                                                                                                                                         | —      |
| G-56 | ⚪ **Not needed v1.3** (legacy only). Our status list is §7.                                                                                                                                             | —                                             | —                      | —                                                                                                                                         | —      |
| G-57 | ⚪ **Not needed v1.3** (legacy only). Our payout balance is always in paise and comes from the ledger (G-15).                                                                                            | —                                             | —                      | —                                                                                                                                         | —      |
| G-58 | Payouts are bank account + IFSC only in the legacy API. Are **UPI payouts** needed?                                                                                                                      | Beneficiary model                             | Bank only / bank + UPI | Model both; enable UPI when confirmed                                                                                                     | DB     |
| G-59 | ✅ **Answered v1.4:** partners and branches each have deposit and withdrawal rates; Admin may assign any combination.                                                                                    | —                                             | —                      | Rates on partner and branch, plus an optional override on the partner↔branch mapping, all effective-dated and snapshotted per transaction | —      |
| G-60 | ✅ **Answered v1.2:** income = **spread**. Partners always pay commission (pay-in and pay-out); branches never pay, and the platform pays the branch its commission. The "both pay" reading is rejected. | —                                             | —                      | As §6                                                                                                                                     | —      |
| G-61 | The customer can **add UTR or upload a photo**: is one of them enough, or is UTR mandatory? Can a photo-only request be approved?                                                                        | Duplicate-UTR protection works only with UTRs | UTR required / either  | Either is accepted; the branch must enter the UTR when approving a photo-only request (keeps duplicate-UTR checks)                        | Phase  |

**Before database design:** ✅ all Priority-1 items answered (v1.4). Remaining open items are Priority 2 and are decided in the phase that builds the feature.

---

## Phase 10 — Final requirement baseline

### 10.1 Confirmed requirements

**Business model**

1. The platform is a trusted network between partners and branches, not a generic payment gateway.
2. Customer money moves only between the customer and a branch; our company's bank account is never in the payment flow.
3. The platform records transactions, commissions, obligations (ledger), reconciliation and settlement.
4. The platform earns the spread between partner and branch commission rates.
5. Commission is configured separately for partner deposit, partner withdrawal, branch deposit and branch withdrawal. It must be extensible (fixed, slab, effective dates, versions) and never hard-coded.
6. Commercial configurations are validated so a negative margin can't happen by accident.
7. Settlement = net position per party per period → settled outside the platform → recorded by Admin with direction, reference, status, date and proof.
8. Transaction, commission, ledger, reconciliation and settlement are separate concepts. The ledger is auditable and append-only, and balances are derived from it.

**Parties and relationships**

9. Partner = external merchant website; partner customer = its end user (no login); branch = payment operator; Admin = our staff.
10. The partner owns the customer relationship; the platform owns transactions, sessions, allocation, reconciliation and webhooks; the branch operates payment accounts.
11. Partner ↔ branch is many-to-many through a mapping with its own status (plus priority, limits, permissions, effective dates and audit history as capabilities).
12. A branch has many bank/UPI accounts; only verified and active accounts are used.
13. Trust and verification of both partners and branches is a core value. The exact requirements are still open.

**Pay-in**

14. Partner backend → signed API → validation → idempotency on the partner transaction ID → transaction + session → payment URL → customer pays on our page.
15. The payment page shows partner branding and only the methods allowed by partner, branch and account configuration and status.
16. Allocation uses a configurable strategy and full eligibility checks, and never over-allocates under concurrency.
17. Detection and confirmation go through UTR, statement import and matching (UTR + amount + branch), and an unsettled-UTR exception queue.
18. Manual deposit with states CREATED, PENDING, PAYMENT_PENDING/HOLD, APPROVED, DECLINED; approval is atomic; decline requires a reason.
19. Payment proofs are stored privately, with access only for authorized users.
20. Statement entries are separate from transactions and linked on match; import history is kept.

**Pay-out**

21. Partner requests a payout → position check → eligible branch → branch pays from its own account → branch records UTR and details → result → webhook → accounting.

**Integration and operations**

22. Signed webhooks with retries, delivery history and manual resend; a status API; the return URL is for experience only.
23. Admin has complete visibility. Branch and partner users see only their own organisation's data, enforced on the server.
24. RBAC with Admin-managed roles and granular permissions.
25. Every sensitive action is audited (actor, action, entity, before/after, IP, user agent, time, request ID).
26. Security: signed requests, IP whitelist, rate limits, idempotency, encryption, secret rotation, masking, secure files, 2FA, WAF.
27. Async work (webhooks, notifications, imports, reconciliation, reports, exports, settlement calculation) runs on Redis + Horizon; financial consistency never depends on async jobs.
28. The feature list in §4 (50 items) is the functional baseline.
29. UI: one design system with per-panel accents (Admin indigo, Branch emerald, Partner violet, Customer partner-branded); high density handled by structure, not by removing information.
30. Stack: Laravel 13, PHP 8.5, Octane, PostgreSQL, Redis, Horizon, Cloudflare, React + TypeScript.

**Added in v1.1 (client answers)**

31. The platform is always the central counterparty; commission is collected from both parties outside the platform.
32. Settlement in the platform = calculated figures + Admin **ticks the amount as settled**.
33. Commission rates are negotiated offline; Admin enters the final deposit and withdrawal rate per partner and per branch.
34. Default allocation = **round robin** over the eligible accounts of the partner's mapped branches.
35. Pay-in: the customer adds a UTR **or** uploads a photo → the request appears in the owning branch's portal → the branch operator checks their bank → **Approve** = SUCCESS → shown in the partner panel + webhook.
36. Pay-out: the request appears in the branch portal → the branch pays → the branch approves with the UTR = SUCCESS.
37. The existing partner API ([Legacy-API.md](Legacy-API.md)) uses a static `PrivateKey` header, partner-generated GUID transaction IDs and amounts in paise; our webhooks send the same key. The key is therefore stored encrypted.
38. **Partners always pay commission** (deposit and withdrawal); **branches never pay commission**; the platform pays each branch its commission; platform income = the spread. Withdrawals follow option W-A (§6.4).

**Added in v1.3**

39. Commission is counted when a transaction reaches SUCCESS.
40. Settlement is calculated daily, and on demand.
41. The platform never bears a loss (the Upwork-style marketplace model).
42. Partner onboarding captures website URL, webhook URL and callback URL; the partner receives a secret key.
43. The legacy API is a reference only; we design our own API. External providers (FFPay) are not in v1.

**Added in v1.4**

44. Payout eligibility: the partner must have enough balance **with the associated branch** (pair-level); otherwise "balance is low".
45. Limits at three levels: partner and branch (set by Admin at creation, deposit and withdrawal), and account (set by the branch within the branch limit).
46. Admin can settle manually (tick as settled) and post adjustments.
47. Manual deposit/payout = standard pay-in/payout with manual branch confirmation.
48. Allocation happens when the customer opens our payment page.
49. Rates per partner and per branch, with an optional per-pair override; Admin is responsible (negative margin = warning).

### 10.2 Assumptions (low risk; challenge any of them)

| ID   | Assumption                                                                                                                      |
| ---- | ------------------------------------------------------------------------------------------------------------------------------- |
| A-01 | Currency is INR only in v1 (G-52)                                                                                               |
| A-02 | Amounts are stored as integer paise; times in UTC; the business day follows Asia/Kolkata                                        |
| A-03 | Each portal user belongs to exactly one partner or one branch; admins belong to neither                                         |
| A-04 | Partner customers never log in to our platform                                                                                  |
| A-05 | A transaction is served by exactly one branch and one payment account at any moment (a reassigned payout keeps its history)     |
| A-06 | The commission rates in force when a transaction succeeds are stored on that transaction, and later rate changes never alter it |
| A-07 | Financial records are never deleted or edited; corrections are reversals or adjustments                                         |
| A-08 | Settlement is calculated per party (each partner, each branch) against the platform, not per partner-branch pair                |
| A-09 | One Laravel application (modular monolith) serves all portals, the API and the payment page                                     |
| A-10 | Organisations and users are deactivated rather than deleted, to preserve history                                                |

### 10.3 Needs client confirmation

All open items in §9 (G-01 to G-61; answered ones are marked ✅). **Priority 1:** none left (v1.4). **Priority 2**, before each feature's build phase: all remaining open items. **Before go-live:** G-51.

### 10.4 Out of scope (v1)

| ID     | Item                                                                                                                           |
| ------ | ------------------------------------------------------------------------------------------------------------------------------ |
| OOS-01 | Moving money: the platform does not initiate bank transfers for payouts or settlements, and does not hold funds                |
| OOS-02 | Customer wallets or accounts: these belong to the partner's own system                                                         |
| OOS-03 | Card payments, net-banking redirects, or any method not listed in PMT-01                                                       |
| OOS-04 | Multiple currencies (A-01)                                                                                                     |
| OOS-05 | Native mobile apps (the portals and payment page are responsive web)                                                           |
| OOS-06 | Copying the visual design of the reference system                                                                              |
| OOS-07 | Automated KYC vendor integrations, until G-34/G-35 are answered                                                                |
| OOS-08 | Automated bank-statement fetching via bank credentials or scraping, until G-27 is answered and security-reviewed               |
| OOS-09 | Tax invoicing (GST/TDS), until G-08 is answered                                                                                |
| OOS-10 | Microservices, Kubernetes, Kafka (see Architecture.md §20)                                                                     |
| OOS-11 | External payment providers (FFPay, "Payin Self", "Payout Self"): role unknown (G-37). The design leaves room to add them later |
| OOS-12 | Compatibility with the legacy API: it is a reference only (G-54)                                                               |
| OOS-13 | "Manual link" (payment links created without the API) (G-28)                                                                   |

---

## Appendix A — Changes from earlier documents

| Topic                 | Earlier                                             | Now                                                                                                                |
| --------------------- | --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Who holds money       | Architecture §18 #6 asked "who holds/settles money" | **Answered:** branches hold customer money; net positions are settled externally with the platform as counterparty |
| Fees                  | Architecture §9 had one "fee plan"                  | Four separate rates (partner/branch × deposit/withdrawal); platform earns the spread                               |
| Scope of the platform | "Manual payment gateway"                            | **Trusted partner↔branch network** + accounting, reconciliation and external net settlement                        |
| Settlement            | "Admin records a payout"                            | Calculation → approval → external payment → recording, with partial and disputed states                            |
| Transaction statuses  | Three different vocabularies (C-10)                 | One proposed machine per object (Phase 7); settlement tracked separately from status                               |
| Open questions        | 39 questions + 10 contradictions (Features.md §4)   | Consolidated into G-01 to G-53 with options and recommendations                                                    |

## Appendix B — Already built (Phases 0–1) that may change after approval

| Built                                                                      | Possible change                                                  | Gap  |
| -------------------------------------------------------------------------- | ---------------------------------------------------------------- | ---- |
| Fixed roles and permission names defined in code                           | Editable roles in the database; `module.action` permission names | G-45 |
| 2FA mandatory for Admin and Branch                                         | Keep (recommended)                                               | G-46 |
| Users suspended, never deleted; append-only audit/security logs            | Unchanged                                                        | —    |
| Host separation (portals / API / payment page), Docker environment, queues | Unchanged                                                        | —    |
