# PAY GATEWAY — Existing (legacy) partner API

The API partners use with the **existing** platform, captured from the client's documents "Payin APIs _ PG - Z.pdf" and "Payout APIs _ PG - Z.pdf" (received 2026-09-27).
**Role (confirmed 2026-09-27): reference only.** This is what works today; the new platform has **its own** API and reuses ideas from here where useful. No compatibility layer is built (Requirements.md G-54, OOS-12).

> The sample `PrivateKey` value in the source PDFs is **not** copied here. Real keys must never be stored in documents or git.

---

## 1. Common rules

| Topic                       | Pay-in API                                                                             | Pay-out API                                  |
| --------------------------- | -------------------------------------------------------------------------------------- | -------------------------------------------- |
| Base path                   | `{{url}}/api/payment/...`                                                              | `{{url}}/payout/...`                         |
| Authentication              | Header `PrivateKey: <partner key>` (a static shared secret, no signature or timestamp) | Same                                         |
| Our webhooks to the partner | Also carry the header `PrivateKey: <partner key>`                                      | Same                                         |
| Transaction ID              | Partner-generated **GUID** (`TransactionId`)                                           | Partner-generated **GUID** (`transactionID`) |
| Amount                      | Integer **paise** ("cent"): ₹100 = `10000`                                             | Integer **paise**: ₹100 = `10000`            |
| JSON naming                 | PascalCase (`TransactionId`, `ReturnUrl`)                                              | camelCase (`transactionID`, `accountNumber`) |
| Custom fields               | `Udf1`–`Udf4`                                                                          | `udf1`–`udf4`                                |

---

## 2. Pay-in

### 2.1 Create pay-in: `POST {{url}}/api/payment/paymentrequest`

| Field            | Example                                | Notes                                            |
| ---------------- | -------------------------------------- | ------------------------------------------------ |
| `Amount`         | `10000`                                | Paise                                            |
| `TransactionId`  | `00000000-0000-0000-0000-XXXXXXXXXXXX` | Partner's GUID, used for idempotency and lookups |
| `ClientUsername` | `username`                             | The partner's customer username                  |
| `ClientCode`     | `CC`                                   | Meaning to confirm (partner code?) (G-55)        |
| `Description`    | `""`                                   |                                                  |
| `Email`          | `""`                                   | Customer email                                   |
| `MobileNo`       | `""`                                   | Customer mobile                                  |
| `ReturnUrl`      | `""`                                   | Per-request return URL (G-41)                    |
| `Udf1`–`Udf4`    | `""`                                   | Partner-defined pass-through fields              |

**Success response**

```json
{
    "Url": "https://domain_url/dashboard?token=<GUID>",
    "Status": { "returnMessage": "", "code": 0 }
}
```

**Failure response**

```json
{ "Url": "", "Status": { "returnMessage": "Invalid PrivateKey", "code": 1 } }
```

### 2.2 Pay-in webhook (our platform → partner): `POST {{partner_webhook_url}}`

| Field           | Example      | Notes                                                |
| --------------- | ------------ | ---------------------------------------------------- |
| `TransactionId` | `39CD3F9F-…` | Partner's GUID                                       |
| `Description`   | `null`       |                                                      |
| `ResMessage`    | `approved`   |                                                      |
| `GatewayName`   | `""`         |                                                      |
| `Amount`        | `10000`      | Paise                                                |
| `Order_Id`      | `39CD3F9F-…` | Our order ID (same as `TransactionId` in the sample) |
| `ClientCode`    | `CC`         |                                                      |
| `IsSuccess`     | `true`       |                                                      |
| `PaymentMode`   | `UPI`        |                                                      |
| `Status`        | `Success`    | Deposit status name                                  |
| `StatusCode`    | `1`          | Deposit status code (§2.5)                           |
| `Utr`           | `123456`     |                                                      |

The partner replies `{ "returnMessage": "Your recharge was successful", "code": 0 }` or `{ "returnMessage": "Invalid Transaction", "code": 1 }`.

### 2.3 Pay-in status: `GET {{url}}/api/payment/status?TransactionId=<GUID>`

Response: `Result` { `TransactionId`, `Description`, `Amount`, `OrderId`, `ClientCode`, `IsSuccess`, `PaymentMode`, `StatusCode`, `Status`, `Utr` } plus `Status` { `returnMessage`, `code` }.

### 2.4 Multiple pay-in status: `POST {{url}}/api/payment/multipletransactionstatus`

Request `{ "TransactionId": ["<GUID>", "<GUID>"] }`. Response: `Result` is an array of the §2.3 objects, plus `Status`.

### 2.5 Codes

| API response code (`Status.code`) | Meaning |
| --------------------------------- | ------- |
| 0                                 | Success |
| 1                                 | Warning |
| 2                                 | Error   |
| 3                                 | Info    |

| Deposit status code | Name                |
| ------------------- | ------------------- |
| 1                   | Success             |
| 2                   | Failed              |
| 3                   | Cancelled           |
| 4                   | Pending             |
| 11                  | Incomplete          |
| 14                  | Invalid             |
| 15                  | TransactionNotFound |
| 200                 | ProviderCreated     |

---

## 3. Pay-out

### 3.1 Create payout: `POST {{url}}/payout/request`

| Field           | Example               | Notes                                                         |
| --------------- | --------------------- | ------------------------------------------------------------- |
| `accountNumber` | `10127XXXXXXXXX`      | Beneficiary bank account (**bank only**, no UPI field) (G-58) |
| `ifsc`          | `IDFB00XXXXX`         |                                                               |
| `bankName`      | `State Bank Of India` |                                                               |
| `amount`        | `100000`              | Paise                                                         |
| `name`          |                       | Beneficiary name                                              |
| `email`         |                       |                                                               |
| `phone`         |                       |                                                               |
| `transactionID` | GUID                  | Partner's GUID                                                |
| `udf1`–`udf4`   | `""`                  |                                                               |

Response: `{ "transactionStatus": "INITIATED | PENDING | DONE | FAIL | FAILED", "message": "", "orderId": "AbcfWvrfg4589", "code": "SUCCESS | FAILED" }`

### 3.2 Payout webhook (our platform → partner): `POST {{partner_webhook_url}}`

Body: `transactionID`, `transactionStatus`, `amount` (paise), `message`, `utr`, `orderId`. The partner replies `{ "message": "", "code": "SUCCESS | FAILED" }`.

### 3.3 Payout status: `POST {{url}}/payout/status`

Request `{ "transactionID": "<GUID>" }`. Response: `transactionID`, `transactionStatus`, `amount`, `message`, `utr`, `orderId`, `code`.

### 3.4 Multiple payout status: `POST {{url}}/payout/multistatus`

Request `{ "transactionID": ["<GUID>", …] }`. Response: an array of the §3.3 objects.

### 3.5 Payout balance: `GET {{url}}/payout/balance`

Response: `{ "balance": 112750.0, "message": "Balance fetched successfully", "code": "SUCCESS | FAILED" }`. **Unit unclear**: the sample is a decimal, while every other amount is integer paise (G-57).

### 3.6 Payout statuses

INITIATED, PENDING, DONE, FAIL, FAILED, TransactionNotFound. The difference between FAIL and FAILED is not explained (G-56).

---

## 4. Observations for the new design

| #   | Observation                                                                                           | Impact                                                                            | Proposal                                                                                                         |
| --- | ----------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| L-1 | Authentication is a static `PrivateKey` header: no signature, timestamp or replay protection          | A leaked key allows any call; a captured request can be replayed                  | New API: secret key used for HMAC signing (+ timestamp, IP whitelist, rate limits); the key itself is never sent |
| L-2 | Our webhooks send the partner's own `PrivateKey` to the partner's URL                                 | The key is exposed to anything that can read the partner's logs                   | New API: webhooks carry a signature header (`X-PayGate-Signature`) instead of the key                            |
| L-3 | Pay-in and pay-out use different naming styles, paths and response shapes (`code` 0/1 vs `"SUCCESS"`) | Inconsistent for partners                                                         | New API: one consistent naming style and response envelope for pay-in and pay-out                                |
| L-4 | The partner supplies the transaction GUID                                                             | Natural idempotency key (C-07 resolved in favour of the partner's transaction ID) | Unique (partner, transaction ID) in the database                                                                 |
| L-5 | `Order_Id` / `OrderId` / `orderId` is our reference                                                   | Our gateway transaction ID must fit this field                                    | Keep our own reference format (C-08) and return it here                                                          |
| L-6 | Payout beneficiary is bank account + IFSC only                                                        | UPI payouts not supported by the old API                                          | Confirm whether UPI payouts are needed (G-58)                                                                    |
| L-7 | "Payout balance" is exposed to partners via API                                                       | Confirms a partner-level payout balance exists (G-15)                             | Derive it from the ledger; confirm the formula and units (G-15, G-57)                                            |
| L-8 | The pay-in URL is `…/dashboard?token=<GUID>`                                                          | Token as a query parameter                                                        | New URL `pay.<domain>/p/<token>`                                                                                 |
| L-9 | Deposit status 200 "ProviderCreated" and `GatewayName`                                                | Hints that some pay-ins go through an external provider                           | Part of G-37 (providers)                                                                                         |
