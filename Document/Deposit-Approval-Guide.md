# Deposit Approval & UTR Rules — Client Guide

_Agreed with the client on 21–22 Oct 2026. This replaces the old rule "the branch always approves by hand" (G-23)._

## 1. The idea in one line

**A deposit is approved when the customer's UTR and the branch's bank entry say the same thing: same UTR, same amount, same bank account.** When something is unclear, the system stops and a person decides. It never guesses.

## 2. How auto-approval works

1. **Customer:** pays, then enters the **UTR** on the payment page.
2. **Branch:** enters the **UTR + amount** it sees in its own bank account.
3. **System:** if both match exactly, the deposit becomes **Success** on its own. The commission and the ledger are booked, and the partner is told, exactly as with a manual approval.

It doesn't matter which happens first. Whichever comes second completes the match.

### Where to enter the UTR and amount

| Who    | Click                                               | Link                 |
| ------ | --------------------------------------------------- | -------------------- |
| Branch | Left menu **Statements → A/C Statement Entry**      | `/branch/statements` |
| Admin  | Left menu **Reconciliation → Manual A/C Statement** | `/admin/statements`  |

In the **"Credit received in the bank"** form at the top, fill in:

- **Branch** (Admin only)
- **Bank account** (where the money came in)
- **Date**
- **UTR / reference**
- **Credit ₹**

Then click **Add line**. For many lines at once, use **Import statement** on the same page.

After **Add line**, a message tells you what happened: _"approved automatically"_, _"matched — approve it when you're ready"_, or _"in the unsettled queue (reason)"_.

### Example A: the normal case

| Time  | Who      | What they do                                                             | Result                               |
| ----- | -------- | ------------------------------------------------------------------------ | ------------------------------------ |
| 10:00 | Customer | Pays ₹5,000 to Branch A, enters UTR `626812820491`                       | PI-001 is Pending                    |
| 10:05 | Branch A | A/C Statement Entry → its account, UTR `626812820491`, ₹5,000 → Add line | **PI-001 is Success, automatically** |

### Example B: the browser closed and the money is at another branch

| Time  | Who      | What they do                                                                       | Result                                                                                      |
| ----- | -------- | ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| 10:00 | Customer | Gets Branch B's details, pays ₹5,000. The browser closes before they enter the UTR | PI-001 expires                                                                              |
| 10:30 | Customer | Tries again. The new deposit PI-002 goes to Branch A. Enters UTR `626812820491`    | PI-002 is Pending at Branch A                                                               |
| 10:40 | Branch B | Enters UTR `626812820491`, ₹5,000 on its own account                               | **PI-002 moves to Branch B and is approved automatically.** Admin and Branch A get an alert |

The automatic move happens only because this same customer **was shown Branch B's account** (on PI-001). If a customer was never shown that account, the system doesn't move the deposit by itself. Admin decides, because the UTR could be someone else's.

## 3. The safety rules

1. **One UTR = one payment.** While a UTR is on a **pending** or **successful** deposit (or a payout), nobody else can enter it. The customer is told why: _"already submitted for a payment that is pending"_ or _"already used for a successful payment"_. The database itself enforces this.
2. **A UTR is released only** when its deposit is **declined, expired, cancelled or failed**. Then it can be used again, with a note showing the earlier use.
3. **Every refused UTR alerts Admin** (once per hour per UTR). It is either a double entry or someone trying fraud.
4. **The money can be in only one bank account.** Only the branch whose account received it can produce the matching entry.
5. **Only exact matches are automatic:** the same UTR, the same amount, one deposit and one bank entry. Anything else goes to a person.
6. **A deposit moves to another branch automatically** only if that customer was shown that branch's account in the last day.
7. **A double click makes one deposit.** If the same customer asks for the same amount twice within 2 minutes while the first is still unpaid, the second request is refused and the partner gets the first payment link.
8. **Approving with a UTR different from the customer's** needs a written reason, and Admin is told.
9. **An approval that no bank entry confirms** within 24 hours (changeable) is reported to Admin.
10. **Everything has a name on it.** Every approval shows who did it and how. A branch that enters a false bank line is liable for that money in its own account.

## 4. All scenarios

### Normal

| #   | What happens                                           | What the system does                                                                                | Who acts       |
| --- | ------------------------------------------------------ | --------------------------------------------------------------------------------------------------- | -------------- |
| 1   | Customer UTR + branch entry: same account, same amount | Approves automatically                                                                              | Nobody         |
| 2   | Branch enters first, the customer enters the UTR later | Approves as soon as the UTR arrives                                                                 | Nobody         |
| 3   | Customer sent only a screenshot (no UTR)               | Nothing to match. The branch approves with the bank UTR                                             | Branch         |
| 4   | Customer paid a different amount                       | No auto-approval. "Amount mismatch" case. Approve the amount received (reason required, Admin told) | Branch / Admin |
| 5   | Auto-approval switched off in Global Settings          | The entry is only linked. The branch clicks Approve                                                 | Branch         |
| 6   | Deposit deliberately put on hold                       | Never approved automatically                                                                        | Branch / Admin |

### Wrong branch

| #   | What happens                                                                              | What the system does                                   | Who acts |
| --- | ----------------------------------------------------------------------------------------- | ------------------------------------------------------ | -------- |
| 7   | Waiting at Branch A, money in Branch B, the customer **was shown** B's account earlier    | Moves it to B and approves it. Admin and A are alerted | Nobody   |
| 8   | Branch A said "Not in our bank", then Branch B enters it (customer was shown B's account) | Moves it to B and approves it                          | Nobody   |
| 9   | Waiting at Branch A, Branch B enters it, but the customer was **never shown** B's account | No move. "Wrong branch" case                           | Admin    |
| 10  | Branch B is not linked to that partner for deposits                                       | No move. "Wrong branch" case                           | Admin    |

### Duplicates and double clicks

| #   | What happens                                                                               | What the system does                                                                                               | Who acts                        |
| --- | ------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------ | ------------------------------- |
| 11  | Double click on the partner's site → two deposit requests                                  | The second is refused (`duplicate_request`) with the first payment link                                            | Nobody                          |
| 12  | Customer double-clicks "I've made the payment"                                             | The second submit is ignored                                                                                       | Nobody                          |
| 13  | A second deposit (same or another branch) gets the **same UTR** while the first is pending | **Refused:** "already submitted for a payment that is pending". Admin alerted. The second stays unpaid and expires | Nobody (Admin checks if needed) |
| 14  | Same UTR entered after the first deposit is **successful**                                 | **Refused:** "already used for a successful payment". Admin alerted                                                | Nobody                          |
| 15  | A branch enters the exact same bank line twice                                             | The second is refused: "already in the statement"                                                                  | Nobody                          |
| 16  | **Two branches** enter the same UTR in their bank entries                                  | Nothing approved. "Duplicate UTR" case. Admin alerted at once                                                      | Admin investigates              |

### Fraud attempts

| #   | What happens                                                                        | What the system does                                                                                                             | Who acts                                                                 |
| --- | ----------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| 17  | Fake UTR entered → no money → declined. Later the real customer enters the same UTR | Accepted (the UTR was released), with a note "also used on PI-xxx (declined)". Approved normally                                 | Nobody                                                                   |
| 18  | A fraudster types a real customer's UTR **first** (still pending)                   | The real customer is refused ("pending") and Admin is alerted. The bank entry won't auto-move the fake deposit to another branch | **Admin declines the fake**; the real customer then enters the UTR again |
| 19  | Someone reuses the UTR of an approved deposit (to be paid twice)                    | Refused, Admin alerted                                                                                                           | Nobody                                                                   |
| 20  | Someone uses a payout's or a settlement's UTR on a deposit                          | Refused                                                                                                                          | Nobody                                                                   |
| 21  | A branch approves with a bank UTR **different** from the customer's                 | Reason required. Admin alerted                                                                                                   | Admin reviews                                                            |
| 22  | A branch enters a **false** bank line to approve a deposit                          | Possible, but the branch's name is on it and it owes that money in its ledger                                                    | Admin                                                                    |
| 23  | A deposit approved by hand never appears in any bank statement                      | Admin is alerted after 24 hours (setting)                                                                                        | Admin asks the branch                                                    |

### Late and other

| #   | What happens                                                  | What the system does                                                       | Who acts            |
| --- | ------------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------- |
| 24  | Deposit expired or was declined, but the money arrived anyway | "Late payment" case                                                        | Admin approves late |
| 25  | A bank entry that no customer claims                          | "UTR not found" case. It links itself if the customer enters the UTR later | Branch / Admin      |
| 26  | A wrong approval is found later                               | Normal Reversal / Chargeback                                               | Admin               |

## 5. UTR rules at a glance

| The UTR is already on a transaction that is… | Can the UTR be entered again?                                       |
| -------------------------------------------- | ------------------------------------------------------------------- |
| **Success / Paid** (even if later refunded)  | ❌ No. Message: _"already used for a successful payment"_           |
| **Pending** (being checked, or on hold)      | ❌ No. Message: _"already submitted for a payment that is pending"_ |
| **Payout in process**                        | ❌ No. Message: _"already in use for a payment that is in process"_ |
| **Declined / Expired / Cancelled / Failed**  | ✅ Yes, with a note showing the earlier use                         |

## 6. Alerts (bell + email)

| Alert                                           | Who gets it            | When                                                                                            |
| ----------------------------------------------- | ---------------------- | ----------------------------------------------------------------------------------------------- |
| **UTR used twice — check for fraud**            | Admin                  | A customer entered a UTR that is pending or successful elsewhere · two bank entries share a UTR |
| **Deposit moved automatically**                 | Admin + the old branch | A deposit was moved to the branch whose bank shows it, and approved                             |
| **Deposit approved with another UTR**           | Admin                  | A branch approved with a bank UTR different from the customer's                                 |
| **Approved deposits not in any bank statement** | Admin                  | A manual approval has no bank entry after 24 hours (setting)                                    |

## 7. What each user sees

- **Customer:** if the UTR is already pending or used, a clear message says so. A double click lands on the same payment page.
- **Partner:** gets `payin.success` as before. A double-click request gets `409 duplicate_request` with the existing `payment_url`.
- **Branch:** enters UTR + amount, and most deposits approve themselves.
- **Admin:** gets alerts only for things that need a person. **Global Settings › Deposit approval** has the auto-approval switch and the "no bank line after X hours" alert (0 = off).

## 8. Is it 100% fraud-proof?

The system can **never approve the same money twice**, a UTR **can't be on two live deposits**, and a customer **can't fake a payment**: the money must show in the branch's own bank. Two risks remain, and both are visible:

- **A fraudster types a real customer's UTR first.** The real customer is blocked until Admin declines the fake (the Admin alert makes this quick).
- **A person approves by hand.** This is always recorded with their name, alerts Admin when unusual, and makes that branch liable for the money.
