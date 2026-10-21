# Deposit Approval & UTR Rules — Client Guide

_Agreed with the client on 21 Oct 2026. This replaces the old rule "the branch always approves by hand" (G-23)._

## 1. The idea in one line

**A deposit is approved when the customer's UTR and the branch's bank entry say the same thing: same UTR, same amount, same bank account.** When something is unclear, the system stops and a person decides. It never guesses.

## 2. How auto-approval works

1. **Customer:** pays, then enters the **UTR** on the payment page.
2. **Branch:** enters the **UTR + amount** it sees in its own bank account.
3. **System:** if both match exactly, the deposit becomes **Success** on its own. The commission and the ledger are booked, and the partner is told, exactly as with a manual approval.

It doesn't matter which happens first. Whichever comes second completes the match.

### Where the branch enters the UTR and amount

| Portal | Menu                                       | What to do                                                                                                                     |
| ------ | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------ |
| Branch | **A/C Statement Entry**                    | Form "Credit received in the bank": choose the **account** the money came into, the date, **UTR**, **Credit ₹** → **Add line** |
| Branch | **A/C Statement Entry › Import statement** | Upload the bank statement file. Every line is checked the same way                                                             |
| Admin  | **Manual A/C Statement**                   | Same form, for any branch's account                                                                                            |

After **Add line**, a message tells the branch what happened: _"approved automatically"_, _"matched — approve it when you're ready"_, or _"in the unsettled queue (reason)"_.

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

## 3. The safety rules

1. **One UTR = one bank transfer.** A UTR used on an approved deposit or a paid payout can never be used again, by anyone. The database itself enforces this.
2. **The money can be in only one bank account.** Only the branch whose account received the money can produce the matching entry.
3. **Only exact matches are automatic:** the same UTR, the same amount, one deposit and one bank entry. Anything else goes to a person.
4. **A double click makes one deposit.** If the same customer asks for the same amount twice within 2 minutes while the first deposit is still unpaid, the second request is refused and the partner gets the first payment link.
5. **A repeated UTR alerts Admin immediately,** and the branch can't pick a "winner" by hand. Only Admin can, or the bank entry decides.
6. **A leftover duplicate is taken off the pending list.** It moves to **Payment hold** marked **"Duplicate UTR"**, with a one-click **"Decline as duplicate"** button.
7. **Approving with a UTR different from the customer's** needs a written reason, and Admin is told.
8. **An approval that no bank entry confirms** within 24 hours (changeable) is reported to Admin.
9. **Everything has a name on it.** Every approval shows who did it and how. A branch that enters a false bank line is liable for that money in its own account.

## 4. All scenarios

### Normal

| #   | What happens                                           | What the system does                                                                                     | Who acts       |
| --- | ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------- | -------------- |
| 1   | Customer UTR + branch entry: same account, same amount | Approves automatically                                                                                   | Nobody         |
| 2   | Branch enters first, the customer enters the UTR later | Approves as soon as the UTR arrives                                                                      | Nobody         |
| 3   | Customer sent only a screenshot (no UTR)               | Nothing to match. The branch approves with the bank UTR                                                  | Branch         |
| 4   | Customer paid a different amount (more or less)        | No auto-approval. "Amount mismatch" case. Approve with the amount received (reason required, Admin told) | Branch / Admin |
| 5   | Auto-approval switched off in Global Settings          | The entry is only linked. The branch clicks Approve                                                      | Branch         |
| 6   | Deposit deliberately put on hold                       | Never approved automatically                                                                             | Branch / Admin |

### Wrong branch

| #   | What happens                                                          | What the system does                                                  | Who acts |
| --- | --------------------------------------------------------------------- | --------------------------------------------------------------------- | -------- |
| 7   | Deposit is waiting at Branch A, the money is in Branch B, B enters it | Moves it to B and approves it. Admin and A are alerted                | Nobody   |
| 8   | Branch A said "Not in our bank", then Branch B enters it              | Moves it to B and approves it                                         | Nobody   |
| 9   | Branch B is not linked to that partner for deposits                   | No move. "Wrong branch" case                                          | Admin    |
| 10  | Customer's browser closed after paying, they retry on a new deposit   | The new deposit is matched to the branch that got the money (as in 7) | Nobody   |

### Duplicates and double clicks

| #   | What happens                                                           | What the system does                                                                                                                     | Who acts                       |
| --- | ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------ |
| 11  | Double click on the partner's site → two deposit requests              | The second is refused (`duplicate_request`) with the first payment link                                                                  | Nobody                         |
| 12  | Customer double-clicks "I've made the payment"                         | The second submit is ignored                                                                                                             | Nobody                         |
| 13  | **Two deposits, same UTR + amount, same account** (one payment)        | Both get a red "Same UTR" badge and Admin is alerted. The bank entry **does not** auto-approve; a case opens                             | **Admin approves one**         |
| 14  | After case 13, Admin approves one                                      | The other moves to Payment hold as "Duplicate UTR". It can never be approved with that UTR                                               | Branch: "Decline as duplicate" |
| 15  | **Two deposits, same UTR + amount, at two different branches**         | The branch whose bank got the money enters it, and that deposit is approved. The other goes on hold as "Duplicate UTR". Admin is alerted | Branch: "Decline as duplicate" |
| 16  | Two deposits share a UTR, and the bank entry is on **neither** account | Nothing automatic                                                                                                                        | Admin                          |
| 17  | A branch enters the exact same bank line twice                         | The second is refused: "already in the statement"                                                                                        | Nobody                         |
| 18  | **Two branches** enter the same UTR in their bank entries              | Nothing approved. "Duplicate UTR" case. Admin alerted at once                                                                            | Admin investigates             |

### Fraud attempts

| #   | What happens                                                                        | What the system does                                                                           | Who acts                 |
| --- | ----------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- | ------------------------ |
| 19  | Fake UTR entered → no money → declined. Later the real customer enters the same UTR | Accepted, with a note "also used on PI-xxx (declined)". Approved normally                      | Nobody                   |
| 20  | A fraudster types a real customer's UTR first (still pending)                       | The real customer is **not** blocked. The bank entry picks the real one, the fake is held      | Branch declines the fake |
| 21  | Someone reuses the UTR of an **approved** deposit (to be paid twice)                | Refused on the payment page                                                                    | Nobody                   |
| 22  | Someone uses a payout's or a settlement's UTR on a deposit                          | Refused                                                                                        | Nobody                   |
| 23  | A branch tries to approve by hand a deposit whose UTR is shared                     | Blocked: "Admin approves"                                                                      | Admin                    |
| 24  | A branch approves with a bank UTR **different** from the customer's                 | Reason required. Admin is alerted                                                              | Admin reviews            |
| 25  | A branch enters a **false** bank line to approve a deposit                          | Possible, but the branch's name is on it and it owes that money in its ledger. Admin can check | Admin                    |
| 26  | A deposit approved by hand never appears in any bank statement                      | Admin is alerted after 24 hours (setting)                                                      | Admin asks the branch    |

### Late and other

| #   | What happens                                                  | What the system does                                                       | Who acts            |
| --- | ------------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------- |
| 27  | Deposit expired or was declined, but the money arrived anyway | "Late payment" case                                                        | Admin approves late |
| 28  | A bank entry that no customer claims                          | "UTR not found" case. It links itself if the customer enters the UTR later | Branch / Admin      |
| 29  | A wrong approval is found later                               | Normal Reversal / Chargeback                                               | Admin               |

## 5. UTR rules at a glance

| The UTR is already on a transaction that is… | Can the UTR be used again?                                                   |
| -------------------------------------------- | ---------------------------------------------------------------------------- |
| **Success / Paid** (even if later refunded)  | ❌ Never                                                                     |
| **Pending**                                  | ✅ Yes, but both are marked red and Admin is alerted. The bank entry decides |
| **Declined / Expired / Cancelled / Failed**  | ✅ Yes, with a note showing the earlier use                                  |

**Why "pending" doesn't lock a UTR:** if a fraudster types a real customer's UTR first, the real customer must not be blocked. The money can only be in one bank account, so the bank entry shows which deposit is real.

## 6. Alerts (bell + email)

| Alert                                           | Who gets it            | When                                                                                                       |
| ----------------------------------------------- | ---------------------- | ---------------------------------------------------------------------------------------------------------- |
| **UTR used twice — check for fraud**            | Admin                  | Two deposits claim one UTR · one was approved while another still claims it · two bank entries share a UTR |
| **Deposit moved automatically**                 | Admin + the old branch | A deposit was moved to the branch whose bank shows it, and approved                                        |
| **Deposit approved with another UTR**           | Admin                  | A branch approved with a bank UTR different from the customer's                                            |
| **Approved deposits not in any bank statement** | Admin                  | A manual approval has no bank entry after 24 hours (setting)                                               |

## 7. What each user sees

- **Customer:** nothing changes. If they double-click, they land on the same payment page.
- **Partner:** gets `payin.success` as before. A double-click request gets `409 duplicate_request` with the existing `payment_url`, so they can send the customer there.
- **Branch:** enters UTR + amount, and most deposits approve themselves. A red **"Same UTR as PI-xxx"** badge means "don't approve, Admin decides". **"Decline as duplicate"** clears leftovers in one click.
- **Admin:** gets alerts only for things that need a person. **Global Settings › Deposit approval** has the auto-approval on/off switch and the "no bank line after X hours" alert (0 = off).

## 8. Is it 100% fraud-proof?

The system can **never approve the same money twice**, and a customer **can't fake a payment**: the money must show in the branch's own bank. The only remaining risk is a person approving by hand. Every such action is recorded with their name, alerts Admin when unusual, and makes that branch liable for the money.
