import type { LucideIcon } from 'lucide-react';
import {
    AlertTriangle,
    ArrowDownToLine,
    ArrowUpFromLine,
    BarChart3,
    BookOpen,
    FileSearch,
    Fingerprint,
    HelpCircle,
    Landmark,
    LayoutList,
    PlugZap,
    Receipt,
    RotateCcw,
    Scale,
    ShieldCheck,
    Store,
    Tags,
    Users,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    CaseMock,
    CheckoutMock,
    IpMock,
    ManualDepositMock,
    PayoutMock,
    ReportMock,
    SettlementMock,
    StatementMock,
} from '@/components/pg/guide-mocks';
import admin from '@/routes/admin';
import adminAccounts from '@/routes/admin/accounts';
import adminBranches from '@/routes/admin/branches';
import adminCases from '@/routes/admin/cases';
import adminDeposits from '@/routes/admin/deposits';
import adminMappings from '@/routes/admin/mappings';
import adminPartners from '@/routes/admin/partners';
import adminPayouts from '@/routes/admin/payouts';
import adminReconciliation from '@/routes/admin/reconciliation';
import adminRoles from '@/routes/admin/roles';
import adminSettlements from '@/routes/admin/settlements';
import adminStatements from '@/routes/admin/statements';
import adminTransactions from '@/routes/admin/transactions';
import adminUsers from '@/routes/admin/users';

/**
 * The Guide's content (Admin › Guide, client request 2026-11-16): the whole
 * system in simple English, point by point, for admins who answer branches'
 * and partners' questions. Text may use **bold** and `code`.
 *
 * Keep it true to the code: when a menu, rule or flow changes, change the
 * matching section here too.
 */

export type GuideLink = { label: string; url: string };

export type GuideBlock =
    | { kind: 'points'; title?: string; items: string[] }
    | { kind: 'steps'; title?: string; items: string[] }
    | { kind: 'table'; title?: string; head: string[]; rows: string[][] }
    | { kind: 'note'; tone?: 'info' | 'warn'; text: string }
    | {
          kind: 'mock';
          title: string;
          node: ReactNode;
          marks: string[];
      };

export type GuideSection = {
    id: string;
    group: string;
    title: string;
    icon: LucideIcon;
    summary: string;
    blocks: GuideBlock[];
    links?: GuideLink[];
};

const link = (label: string, route: { url: string }): GuideLink => ({
    label,
    url: route.url,
});

export const GUIDE_GROUPS = [
    'Basics',
    'Deposits',
    'Withdrawals',
    'Money & reports',
    'Menus',
    'Partners & API',
    'Help',
];

export const GUIDE: GuideSection[] = [
    // ─── Basics ──────────────────────────────────────────────────────────
    {
        id: 'start',
        group: 'Basics',
        title: 'What our system does',
        icon: BookOpen,
        summary:
            'Our system takes money from a partner’s customers into branch bank accounts (deposits), and pays money out to them (withdrawals).',
        blocks: [
            {
                kind: 'points',
                items: [
                    'A **partner** is a website or business (for example a gaming or wallet site). Its customers want to add money or take money out.',
                    'The partner connects to us through our **API**. When its customer wants to pay, the partner asks us, and we give a **payment page**.',
                    'A **branch** is our operating team. It owns real **bank accounts and UPI IDs**. The customer pays into one of these.',
                    'The branch checks its bank. When the money is there, the deposit is **approved** (by itself or by a person).',
                    'We tell the partner by **webhook** (an automatic message to its server). The partner then adds the money to its customer’s wallet.',
                    'For a **withdrawal**, the partner asks us to pay its customer. A branch sends the money from its bank and records the UTR.',
                    '**Admin** (you) sets up partners, branches and accounts, watches everything, fixes problems and records settlements.',
                ],
            },
            {
                kind: 'table',
                title: 'The chain of one deposit',
                head: ['Step', 'Who', 'What happens'],
                rows: [
                    [
                        '1',
                        'Customer',
                        'Clicks “Add ₹5,000” on the partner’s website',
                    ],
                    ['2', 'Partner', 'Calls our API with its **Order ID**'],
                    [
                        '3',
                        'Our system',
                        'Creates the deposit with our **Transaction ID** and gives a payment link',
                    ],
                    [
                        '4',
                        'Customer',
                        'Opens the page, picks UPI / QR / bank, pays, enters the **UTR**',
                    ],
                    [
                        '5',
                        'Branch',
                        'Sees the money in its bank and enters the same UTR and amount',
                    ],
                    [
                        '6',
                        'Our system',
                        'Same UTR + same amount + same account → **Success**',
                    ],
                    ['7', 'Our system', 'Sends the webhook to the partner'],
                    ['8', 'Partner', 'Adds ₹5,000 to the customer’s wallet'],
                ],
            },
        ],
        links: [link('Dashboard', admin.dashboard())],
    },
    {
        id: 'people',
        group: 'Basics',
        title: 'Who is who',
        icon: Users,
        summary:
            'Five kinds of people and things. Use these words when you explain.',
        blocks: [
            {
                kind: 'table',
                head: ['Word', 'Meaning', 'Has a login?'],
                rows: [
                    [
                        'Admin',
                        'Our own team. Sees all partners and all branches.',
                        'Yes: Admin portal',
                    ],
                    [
                        'Partner',
                        'A website or business that uses our API. Also called merchant.',
                        'Yes: Partner portal',
                    ],
                    [
                        'Partner customer',
                        'The person who actually pays or gets paid. Belongs to the partner.',
                        'No',
                    ],
                    [
                        'Branch',
                        'Our operating team that owns bank accounts, approves deposits and pays payouts.',
                        'Yes: Branch portal',
                    ],
                    [
                        'Bank & UPI account',
                        'A real bank account or UPI ID of a branch where customers pay.',
                        'No (it belongs to a branch)',
                    ],
                ],
            },
            {
                kind: 'points',
                title: 'Important to remember',
                items: [
                    'A partner **never** sees branch names, branch accounts or branch commission.',
                    'A branch **never** sees partner fees or other branches.',
                    'One partner can work with **many** branches, and one branch with **many** partners. This link is called a **mapping**.',
                    'A customer has no login. They only see the payment page.',
                ],
            },
        ],
        links: [
            link('Partners', adminPartners.index()),
            link('Branches', adminBranches.index()),
            link('Branch mapping', adminMappings.index()),
        ],
    },
    {
        id: 'ids',
        group: 'Basics',
        title: 'IDs and numbers (Transaction ID, Order ID, UTR…)',
        icon: Fingerprint,
        summary:
            'Every payment has more than one number. This is the most asked question, so learn this table.',
        blocks: [
            {
                kind: 'table',
                head: ['Name', 'Who makes it', 'Looks like', 'Use it for'],
                rows: [
                    [
                        '**Transaction ID**',
                        'Our system, automatically',
                        '`PI261116K7M2X9QA` (deposit) · `PO261116K7M2X9QA` (payout)',
                        'Our official number. Use it with support, in statements and chargebacks.',
                    ],
                    [
                        '**Order ID**',
                        'The partner, in its own system',
                        'Anything, e.g. `ORDER-10045`',
                        'For the partner to match our payment with its own order or user.',
                    ],
                    [
                        '**UTR**',
                        'The bank',
                        'UPI / IMPS / QR: **numbers only**, usually 12 digits, e.g. `626812820491`. Bank transfer (NEFT / RTGS): also the bank’s reference, 4-letter bank code + digits, e.g. `HDFCN52026092812`. Spaces and dashes are removed; any other text is refused.',
                        'Proof of one bank transfer. Used to match the bank statement with the deposit.',
                    ],
                    [
                        '**Customer UTR**',
                        'The customer types it on the payment page',
                        'Same as UTR',
                        'What the customer says they paid with.',
                    ],
                    [
                        '**Bank UTR**',
                        'The branch types it (or it comes from the statement)',
                        'Same as UTR',
                        'What the branch really sees in its bank. This is the one that counts.',
                    ],
                    [
                        '**Case reference**',
                        'Our system',
                        '`RC261116…`',
                        'One unsettled bank line that needs a person.',
                    ],
                    [
                        '**Chargeback reference**',
                        'Our system',
                        '`CB261116…`',
                        'One chargeback request.',
                    ],
                    [
                        '**Payout return reference**',
                        'Our system',
                        '`RT261116…`',
                        'One payout return (money came back).',
                    ],
                    [
                        '**Settlement reference**',
                        'Our system',
                        '`ST261116…`',
                        'One settlement for a partner or branch.',
                    ],
                    [
                        '**Adjustment reference**',
                        'Our system',
                        '`AJ261116…`',
                        'One manual correction of a balance.',
                    ],
                ],
            },
            {
                kind: 'points',
                title: 'How to read our Transaction ID',
                items: [
                    '`PI` = Pay-In (deposit). `PO` = Pay-Out (withdrawal).',
                    'Next 6 digits = the date it was created: `261116` = 2026-11-16 (year, month, day).',
                    'Last 8 characters = random, so every ID is different.',
                ],
            },
            {
                kind: 'points',
                title: 'Simple rules',
                items: [
                    'Transaction ID and Order ID point to the **same payment**, seen from two sides.',
                    'The Order ID must be **unique per partner** (one for deposits, one for payouts). The same Order ID sent again with the same details returns the same payment. With different details it is refused.',
                    '**One UTR = one bank transfer.** The same UTR can never be used for two successful payments.',
                    'In every search box you can type a Transaction ID, an Order ID **or** a UTR.',
                ],
            },
        ],
        links: [
            link('All deposits', adminTransactions.index()),
            link('All withdrawals', adminTransactions.payouts()),
        ],
    },
    {
        id: 'statuses',
        group: 'Basics',
        title: 'Status words and what they mean',
        icon: Tags,
        summary: 'The coloured labels you see in every list.',
        blocks: [
            {
                kind: 'table',
                title: 'Deposit (pay-in) status',
                head: ['Label', 'Meaning', 'What to do'],
                rows: [
                    [
                        'Awaiting payment',
                        'Payment page made, customer has not paid yet',
                        'Nothing. It expires by itself if not paid in time.',
                    ],
                    [
                        'Pending',
                        'Customer says “I paid” and gave a UTR',
                        'Branch checks its bank, then approves or declines.',
                    ],
                    [
                        'Payment hold',
                        'Someone put it on hold to check more',
                        'Decide later: approve or decline.',
                    ],
                    [
                        'Success',
                        'Money confirmed. Partner is told.',
                        'Nothing.',
                    ],
                    [
                        'Declined',
                        'Not paid, wrong UTR, wrong amount, fake proof…',
                        'Nothing. The UTR becomes free again.',
                    ],
                    [
                        'Expired',
                        'Customer did not pay in time',
                        'If money arrives later → “Late payment” case.',
                    ],
                    [
                        'Cancelled',
                        'Partner cancelled it before payment',
                        'Nothing.',
                    ],
                ],
            },
            {
                kind: 'table',
                title: 'Withdrawal (payout) status',
                head: ['Label', 'Meaning'],
                rows: [
                    [
                        'Assigned',
                        'Given to a branch. Waiting for the branch to start.',
                    ],
                    [
                        'Processing',
                        'Branch is paying. It may be paid in parts.',
                    ],
                    ['Success / Paid', 'All money sent. Partner is told.'],
                    [
                        'Failed',
                        'Branch could not pay. The held money goes back to the partner’s balance.',
                    ],
                    [
                        'Cancelled',
                        'Partner cancelled before the branch started.',
                    ],
                ],
            },
            {
                kind: 'table',
                title: 'Bank statement line status',
                head: ['Label', 'Meaning'],
                rows: [
                    [
                        'Approved',
                        'The line matched a deposit and that deposit is Success.',
                    ],
                    [
                        'Pending',
                        'The line matched a deposit that still waits for approval.',
                    ],
                    [
                        'Payment hold',
                        'The line matched a deposit that is on hold.',
                    ],
                    [
                        'Unsettled',
                        'The line did not match cleanly. A case is open. Use **Match**.',
                    ],
                    [
                        'Closed',
                        'A person closed it, e.g. bank charges, not a customer payment.',
                    ],
                ],
            },
        ],
    },

    // ─── Deposits ────────────────────────────────────────────────────────
    {
        id: 'deposit',
        group: 'Deposits',
        title: 'Deposit flow (how a customer pays)',
        icon: ArrowDownToLine,
        summary: 'From the partner’s request to Success, step by step.',
        blocks: [
            {
                kind: 'steps',
                items: [
                    'The partner calls our API with the amount, its Order ID and its customer’s ID.',
                    'We create the deposit (Transaction ID `PI…`) and send back a **payment link**. The status is “Awaiting payment”.',
                    'The customer opens the link and picks **UPI**, **QR code** or **Bank transfer**.',
                    'Our system picks a bank or UPI account of a branch mapped to that partner. Branches take turns (round robin), and only accounts within their limits are used.',
                    'The customer pays from their bank app.',
                    'The customer enters the **UTR** and uploads a **payment screenshot** (both required). The status becomes **Pending**.',
                    'The branch sees the money in its bank and enters the bank line (UTR + amount). If everything matches, the deposit becomes **Success** by itself (see next section).',
                    'We send the webhook to the partner and the customer is sent back to the partner’s website.',
                ],
            },
            {
                kind: 'mock',
                title: 'What the customer sees (payment page)',
                node: <CheckoutMock />,
                marks: [
                    'The amount to pay. The customer must pay exactly this.',
                    'Choose how to pay: UPI, QR code or Bank transfer. Only methods the partner allows are shown.',
                    'The account to pay into. “Having trouble paying? Get different details” gives another account for the same payment.',
                    'The UTR from the bank app, and a screenshot. Both are required.',
                ],
            },
            {
                kind: 'points',
                title: 'Good to know',
                items: [
                    'If the customer refreshes the page, they get the same account again.',
                    'If the same customer clicks twice for the same amount within about 2 minutes, only one deposit is made.',
                    'The payment page has a time limit (set per partner). After that the deposit becomes **Expired**.',
                    'The page warns customers not to pay with the Yono SBI app on the UPI and QR tabs.',
                ],
            },
        ],
        links: [link('All deposits', adminTransactions.index())],
    },
    {
        id: 'approve',
        group: 'Deposits',
        title: 'Approving deposits (automatic and by hand)',
        icon: ShieldCheck,
        summary:
            'A deposit is approved when the customer’s UTR and the branch’s bank line say the same thing.',
        blocks: [
            {
                kind: 'points',
                title: 'Automatic approval',
                items: [
                    'Switched on or off in **Global Settings** (Auto-approve deposits).',
                    'The customer enters the UTR. The branch enters the bank line (UTR + amount) in **Manual Entry**, or imports a statement file.',
                    'When **same UTR + same amount + same bank account** → the deposit becomes Success by itself.',
                    'It does not matter which comes first. Whichever comes second completes the match.',
                    'If anything is unclear (two deposits, other amount, other account), it is **not** approved by itself. A person decides.',
                ],
            },
            {
                kind: 'mock',
                title: 'Approving by hand (Payments › Deposit › Manual Deposit)',
                node: <ManualDepositMock />,
                marks: [
                    'The UTR the customer typed. Check it in the bank.',
                    'The customer’s screenshot. Click to see it.',
                    '**Approve**: the money is in the bank. Enter the bank UTR. The amount is the order amount and can’t be changed.',
                    '**Hold**: needs a closer look. Decide later.',
                    '**Decline**: not paid, wrong amount or fake. Pick a reason. The partner is told.',
                ],
            },
            {
                kind: 'points',
                title: 'Rules when approving',
                items: [
                    'If the bank UTR is **different** from the customer’s UTR, a reason is required and Admin is alerted.',
                    'If a different amount arrived, it can’t be approved: **Decline** it. The partner’s site has the order amount, and we can’t change it there. If the branch typed the bank line wrong (₹100 for ₹1,000), **Admin** corrects the line (**Edit**) and it matches.',
                    'If a deposit was approved by hand but no bank line shows it after some hours (setting), Admin is alerted.',
                    'Every decision is saved in the deposit’s timeline and in Audit Logs, with who did it.',
                ],
            },
        ],
        links: [
            link('Manual Deposit', adminDeposits.index()),
            link('Global Settings', admin.settings.index()),
        ],
    },
    {
        id: 'utr-rules',
        group: 'Deposits',
        title: 'UTR safety rules',
        icon: AlertTriangle,
        summary:
            'These rules stop double payments and fraud. Branches and partners often ask about them.',
        blocks: [
            {
                kind: 'steps',
                items: [
                    '**One UTR = one payment.** While a UTR is on a pending or successful deposit (or a payout), nobody can enter it again.',
                    'The customer is told why: “already submitted for a payment that is pending” or “already used for a successful payment”.',
                    'A UTR becomes free again **only** when its deposit is declined, expired, cancelled or failed.',
                    'Every refused UTR **alerts Admin**. It is a double entry or someone trying fraud.',
                    'Only the branch whose account got the money can make the matching bank line.',
                    'A deposit moves to another branch by itself **only** if that same customer was shown that branch’s account in the last day.',
                    'The same UTR typed on a second bank line is refused. Only Admin can correct a wrong line.',
                ],
            },
            {
                kind: 'table',
                title: 'Common cases',
                head: ['What happened', 'What the system does', 'Who acts'],
                rows: [
                    [
                        'Fake UTR → no money → declined. Later the real customer uses that UTR',
                        'Accepted (UTR was free). Approved normally.',
                        'Nobody',
                    ],
                    [
                        'A fraudster types a real customer’s UTR first',
                        'Real customer is refused (“pending”). Admin alerted.',
                        '**Admin declines the fake** deposit. Then the real customer enters the UTR again.',
                    ],
                    [
                        'Someone reuses the UTR of a successful deposit',
                        'Refused. Admin alerted.',
                        'Nobody',
                    ],
                    [
                        'Branch typed a wrong UTR or amount on a bank line',
                        'Line stays unsettled',
                        '**Admin** clicks Edit on the line and corrects it (reason required)',
                    ],
                ],
            },
        ],
    },
    {
        id: 'statement',
        group: 'Deposits',
        title: 'Manual Entry (bank statement lines) and UTR Reconciliation',
        icon: Landmark,
        summary:
            'The branch puts what its bank shows into our system. The system then matches each line to a deposit or payout.',
        blocks: [
            {
                kind: 'mock',
                title: 'Payments › Deposit › Manual Entry',
                node: <StatementMock />,
                marks: [
                    'Type one bank line: bank account, date, UTR and credit amount → **Add line**. For many lines use **Import statement** (bank file).',
                    '**Unsettled** = the line did not match cleanly. Move the mouse over it to see why.',
                    'Admin: **Match** opens the line’s case, where you link it to the right deposit (next section).',
                    'Branch login: **Amount mismatch** → only **Decline** (Admin: **Decline** or **Edit** the line if it was typed wrong). After a decline the line shows **Deposit declined** under Amount mismatch. **Wrong bank account** → **Match** only changes the line’s bank account. **Wrong branch** → only Admin can move the deposit (the line shows “Admin will move it”). Any other unsettled line shows **Details**.',
                ],
            },
            {
                kind: 'points',
                title: 'How matching works',
                items: [
                    'A line matches only when **all 3** are the same: **UTR**, **amount** (to the paisa) and **bank account**.',
                    'Credit lines (money in) match deposits. Debit lines (money out) match payout transfers.',
                    'If the deposit is still waiting → line shows **Pending** and the deposit is approved (automatically, if switched on).',
                    'If the deposit is already Success → line becomes **Reconciled** (everything agrees).',
                    'If the bank line comes first and the customer enters the UTR later, it matches at that moment.',
                    'Anything else opens an **Unsettled case**.',
                ],
            },
            {
                kind: 'points',
                title: 'UTR Reconciliation tab',
                items: [
                    'Shows the other side: every **approved deposit** and **paid payout**, and whether a bank line confirms it.',
                    '“Not in a statement” = approved but no bank line yet. Either the statement is not entered yet, or the approval needs a second look.',
                    'Branch users see their own lines only. Admin sees all branches.',
                ],
            },
        ],
        links: [
            link('Manual Entry', adminStatements.index()),
            link(
                'UTR Reconciliation (deposits)',
                adminReconciliation.index({ query: { direction: 'payin' } }),
            ),
            link(
                'UTR Reconciliation (withdrawals)',
                adminReconciliation.index({ query: { direction: 'payout' } }),
            ),
        ],
    },
    {
        id: 'unsettled',
        group: 'Deposits',
        title: 'Unsettled UTR and the Match button',
        icon: FileSearch,
        summary:
            'When a bank line does not match by itself, it becomes a case. A person links it or closes it.',
        blocks: [
            {
                kind: 'mock',
                title: 'After clicking Match (Unsettled UTR case)',
                node: <CaseMock />,
                marks: [
                    'The case number and status (Open / Resolved).',
                    'Why it did not match, in one line.',
                    'A bank line with a UTR offers only the deposit with that same UTR. A line without a UTR (e.g. cash deposit) offers deposits of the same amount on that account. Search finds any deposit, but only the same UTR can be linked.',
                    '**Link**: shown only when the amount is exactly the same. The line is linked to that deposit.',
                    'Different amount: it cannot be linked or approved. Decline the deposit; if the bank line was typed wrong, Admin corrects it.',
                    '**Admin only**: close the line when it is not a customer payment (bank charges, own transfer, a line typed twice) or the money was returned to the customer. A reason is required. Branch logins do not see these buttons or the Resolve button.',
                ],
            },
            {
                kind: 'table',
                title: 'Case types and what to do',
                head: ['Case type', 'Meaning', 'What to do'],
                rows: [
                    [
                        'UTR not found',
                        'No deposit has this UTR yet',
                        'Wait: it links itself when the customer enters the UTR. Or find the deposit and **Link**.',
                    ],
                    [
                        'Amount mismatch',
                        'UTR matches but the amount is different',
                        'Only **Decline** (the line waits for a deposit of the right amount). Typed wrong? Admin **Edit**s the line.',
                    ],
                    [
                        'Wrong bank account',
                        'The deposit is for another bank account of the same branch',
                        'Branch: **Match** → choose the right bank account (the line was entered under the wrong one). Admin can also **Move deposit** to this line’s account.',
                    ],
                    [
                        'Wrong branch',
                        'The deposit belongs to another branch',
                        'The branch cannot fix it: the line shows “Admin will move it”. Admin: **Move deposit** to this line’s branch and account.',
                    ],
                    [
                        'Duplicate UTR',
                        'The UTR is already on another line or deposit',
                        'Check both. One is a mistake or fraud. Close the wrong one.',
                    ],
                    [
                        'Late payment',
                        'The deposit had expired or was declined, but money came',
                        '**Admin only**: Approve late.',
                    ],
                    [
                        'Manual review',
                        'Anything else, e.g. a line without UTR',
                        'Find the deposit and Link, or close the line with a reason.',
                    ],
                ],
            },
            {
                kind: 'note',
                tone: 'info',
                text: 'Only exact amounts can be linked. If a branch needs a money correction that is not a deposit, use **Correct with an adjustment** in the case (Finance).',
            },
        ],
        links: [link('Unsettled UTR', adminCases.index())],
    },

    // ─── Withdrawals ─────────────────────────────────────────────────────
    {
        id: 'withdrawal',
        group: 'Withdrawals',
        title: 'Withdrawal (payout) flow',
        icon: ArrowUpFromLine,
        summary: 'How we pay a partner’s customer.',
        blocks: [
            {
                kind: 'steps',
                items: [
                    'The partner calls our API: amount, its Order ID and the customer’s bank details.',
                    'We check the partner’s **balance**. It must cover the amount **plus our fee**. If not: “Balance is low”, and nothing is made.',
                    'The money is **held** and the payout goes to a branch mapped for withdrawals (branches take turns). Status: **Assigned**.',
                    'A branch operator clicks **Start** (status **Processing**) and sends the money from the branch’s bank.',
                    'The operator clicks **Pay** and enters the amount sent, UTR, date and proof. A payout can be paid **in parts** (for example two transfers).',
                    'When the parts add up to the full amount → **Success**. The partner is told by webhook.',
                    'If the branch cannot pay → **Fail**. The held money goes back to the partner’s balance. The partner sends a new request to try again.',
                ],
            },
            {
                kind: 'mock',
                title: 'Payments › Withdrawal › Manual Payout',
                node: <PayoutMock />,
                marks: [
                    '**Start**: the branch takes the payout and begins paying.',
                    '**Pay**: record one bank transfer.',
                    '**Fail**: the branch could not pay (not possible after a part is paid; pay the rest instead).',
                    'Each transfer: amount, UTR, date and proof.',
                ],
            },
            {
                kind: 'points',
                title: 'Good to know',
                items: [
                    'The partner can **cancel** a payout (through the API) only before the branch starts it.',
                    'Admin can **reassign** a payout to another branch before any part is paid.',
                    'Each branch has a minimum and maximum per payout, and a daily limit. If no branch fits: “No branch available”.',
                    'Each transfer is booked on the day it is recorded, so the daily settlement matches the branch’s bank.',
                ],
            },
        ],
        links: [
            link('All withdrawals', adminTransactions.payouts()),
            link('Manual Payout', adminPayouts.index()),
        ],
    },
    {
        id: 'reversals',
        group: 'Withdrawals',
        title: 'Chargebacks and Payout returns',
        icon: RotateCcw,
        summary: 'When money goes back after a payment was already successful.',
        blocks: [
            {
                kind: 'table',
                head: ['', 'Chargeback', 'Payout return'],
                rows: [
                    [
                        'For',
                        'A successful **deposit**',
                        'A successful **payout**',
                    ],
                    [
                        'What happened',
                        'The customer’s bank took the money back',
                        'The payout (or part) came back to the branch’s bank, e.g. it bounced',
                    ],
                    [
                        'Who raises it',
                        'Branch (or Admin), with the bank’s notice',
                        'Partner (“customer did not receive it”), or Admin',
                    ],
                    [
                        'Who confirms',
                        'Admin approves (not the same person who raised it)',
                        'Branch confirms the amount and date (or Admin)',
                    ],
                    [
                        'Money effect',
                        'Partner −amount, branch +amount',
                        'Branch −amount, partner +amount',
                    ],
                    [
                        'Fees',
                        'Fees already earned stay earned',
                        'Fees already earned stay earned',
                    ],
                    [
                        'Booked on',
                        'The day Admin approves',
                        'The day the branch confirms',
                    ],
                    [
                        'Partner told',
                        'Webhook `payin.chargeback`',
                        'Webhook `payout.returned`',
                    ],
                ],
            },
            {
                kind: 'points',
                items: [
                    'The deposit or payout keeps status **Success**. A separate chargeback / return status shows where the request is.',
                    'No UTR is asked for chargebacks and payout returns. The bank’s notice or proof is the evidence.',
                    'If a request is rejected, nothing is booked and it can be raised again.',
                    'Deposit refunds are not used: a deposit is never refunded from PayGate.',
                    'Only one open payout return per payout at a time.',
                ],
            },
        ],
        links: [
            link('Chargebacks', admin.chargebacks.index()),
            link('Payout returns', admin.payoutReturns.index()),
        ],
    },

    // ─── Money & reports ─────────────────────────────────────────────────
    {
        id: 'commission',
        group: 'Money & reports',
        title: 'Commission, fees and margin',
        icon: Receipt,
        summary: 'Who earns what on each payment.',
        blocks: [
            {
                kind: 'points',
                items: [
                    '**Partner fee**: what the partner pays us, a % of each deposit and each withdrawal.',
                    '**Branch commission**: what we pay the branch, a % of each deposit and each withdrawal it handles.',
                    '**Margin**: what stays with us = partner fee − branch commission.',
                    'Rates are set by Admin in **Commissions**, separately for deposits and withdrawals. A new rate counts from now. Old payments keep their old rate.',
                    'For withdrawals, the partner pays amount **+** fee. The branch earns its commission on top.',
                    'Example: deposit ₹10,000, partner fee 6%, branch commission 4% → partner fee ₹600, branch ₹400, margin ₹200.',
                ],
            },
        ],
        links: [link('Commissions', admin.commissions.index())],
    },
    {
        id: 'settlement',
        group: 'Money & reports',
        title: 'Balance, settlement and dashboards',
        icon: Scale,
        summary: 'Who owes whom, and how it is paid.',
        blocks: [
            {
                kind: 'points',
                title: 'Balance (position)',
                items: [
                    '**+ (plus)** = we owe the partner or branch.',
                    '**− (minus)** = the partner or branch owes us.',
                    'A branch receives the customers’ money in its own bank, so it usually owes us (minus) for deposits.',
                    'The partner’s balance grows with deposits and goes down with payouts and fees.',
                ],
            },
            {
                kind: 'mock',
                title: 'Finance › Settlement Dashboard',
                node: <SettlementMock />,
                marks: [],
            },
            {
                kind: 'points',
                title: 'How the dashboard adds up',
                items: [
                    'Deposits − commission, payouts + commission, chargebacks, payout returns and adjustments = **Current Balance**.',
                    'Current Balance + settlement payments recorded = **Net Balance** (what is still open).',
                    'Admin has three views: Admin, Branch and Partner. A branch sees only its own. A partner sees only its own totals (no branches).',
                    '**Settlement** (Finance): Finance calculates a settlement for a period and records the payment (who paid whom, how much, reference).',
                    'The day ends at the settlement cut-off time in Global Settings.',
                ],
            },
        ],
        links: [
            link('Settlement', adminSettlements.index()),
            link(
                'Admin Settlement Dashboard',
                admin.settlementDashboard('admin'),
            ),
            link(
                'Branch Settlement Dashboard',
                admin.settlementDashboard('branch'),
            ),
            link(
                'Partner Settlement Dashboard',
                admin.settlementDashboard('partner'),
            ),
        ],
    },
    {
        id: 'reports',
        group: 'Money & reports',
        title: 'Reports: which report, who can see it',
        icon: BarChart3,
        summary:
            'Reports are in the Admin portal only. Branches and partners do not have a Reports menu.',
        blocks: [
            {
                kind: 'mock',
                title: 'A report page',
                node: <ReportMock />,
                marks: [
                    'Pick the period (Today, Yesterday, This week, This month or Custom), then export as CSV or Excel. Exports are ready in a few moments and are deleted after 7 days.',
                ],
            },
            {
                kind: 'table',
                head: ['Report', 'What it shows'],
                rows: [
                    [
                        'Branch Deposit Report',
                        'Per branch: total successful deposits, branch commission rate and commission',
                    ],
                    [
                        'Partner Deposit Report',
                        'Per partner: total successful deposits, partner fee rate and fees',
                    ],
                    [
                        'Branch Withdrawal Report',
                        'Per branch: total paid withdrawals, rate and commission',
                    ],
                    [
                        'Partner Withdrawal Report',
                        'Per partner: total paid withdrawals, fee rate and fees',
                    ],
                    [
                        'Pay-ins Report',
                        'Every deposit in the period: customer, amount, method, UTRs, status, dates, commission',
                    ],
                    [
                        'Payouts Report',
                        'Every withdrawal in the period: beneficiary, amount, UTR, status, dates, fee',
                    ],
                    [
                        'Partner-wise Report',
                        'Per partner: deposits, withdrawals, fees, margin and balance now',
                    ],
                    [
                        'Branch-wise Report',
                        'Per branch: deposits received, payouts paid, commission and position now',
                    ],
                    [
                        'Partner ↔ Branch Report',
                        'Per partner-branch pair: volumes, commissions and both positions',
                    ],
                    [
                        'Commission Report',
                        'Per day: volume, fees, commission and margin',
                    ],
                    [
                        'Balances Report',
                        'Where everyone stands right now (+ we owe, − they owe)',
                    ],
                    [
                        'Settlements Report',
                        'Settlements that ended in the period, amount to settle and paid',
                    ],
                ],
            },
            {
                kind: 'table',
                title: 'What branches and partners can see instead',
                head: ['Who', 'Where to look'],
                rows: [
                    [
                        'Branch',
                        'Dashboard, Pay-in History, Pay-out History and **Branch Settlement Dashboard** (its own figures only)',
                    ],
                    [
                        'Partner',
                        'Dashboard, All deposits, All withdrawals and **Partner Settlement Dashboard** (its own totals only)',
                    ],
                ],
            },
            {
                kind: 'note',
                tone: 'info',
                text: 'If a branch or partner asks for a report, export it from Admin and send them only their own rows. Never send one partner another partner’s data, and never send a partner branch details.',
            },
        ],
        links: [
            link(
                'Branch Deposit Report',
                admin.reports.show({ report: 'branch_deposits' }),
            ),
            link('Pay-ins Report', admin.reports.show({ report: 'payins' })),
            link('Balances Report', admin.reports.show({ report: 'balances' })),
        ],
    },

    // ─── Menus ───────────────────────────────────────────────────────────
    {
        id: 'admin-menus',
        group: 'Menus',
        title: 'Admin portal: every menu',
        icon: LayoutList,
        summary:
            'What each menu in your left sidebar is for. You see only the menus your role allows.',
        blocks: [
            {
                kind: 'table',
                head: ['Menu', 'What it is for'],
                rows: [
                    [
                        'Overview › Dashboard',
                        'Live figures: deposits, withdrawals, success and failed counts for the period.',
                    ],
                    [
                        'Bank & UPI Accounts',
                        'All branch accounts. Verify new accounts, set limits, switch on/off, see the account log.',
                    ],
                    [
                        'Network › Partners',
                        'Add and edit partners: basic details, API & security (keys, allowed IPs, URLs), payment methods, commission, withdrawal limits, branch mapping.',
                    ],
                    [
                        'Network › Branches',
                        'Add and edit branches: deposit / withdrawal on or off, limits, commission.',
                    ],
                    [
                        'Network › Partner → branches / Branch → partners / Branch mapping',
                        'Which partner works with which branch, for deposits and withdrawals. Switch a mapping off without deleting it.',
                    ],
                    [
                        'Payments › Deposit',
                        'Tabs: All deposits · Manual Deposit · Manual Entry · Unsettled UTR · UTR Reconciliation.',
                    ],
                    [
                        'Payments › Withdrawal',
                        'Tabs: All withdrawals · Manual Payout · UTR Reconciliation.',
                    ],
                    [
                        'Payments › Chargebacks / Payout returns',
                        'Money that goes back after a successful payment.',
                    ],
                    [
                        'Account › Auto A/C Statement',
                        'Coming later (automatic bank statements).',
                    ],
                    [
                        'Finance › Settlement',
                        'Calculate and record settlements with partners and branches.',
                    ],
                    [
                        'Finance › Settlement Dashboards',
                        'Admin, Branch and Partner view of who owes whom.',
                    ],
                    [
                        'Finance › Commissions',
                        'Set partner fees and branch commission rates.',
                    ],
                    ['Reports', 'All reports (see Reports section).'],
                    [
                        'System › Users',
                        'Admin, branch and partner users: add, block, reset.',
                    ],
                    [
                        'System › Roles & Permissions',
                        'What each role can see and do.',
                    ],
                    [
                        'System › Global Settings',
                        'Auto-approve deposits, alert times, settlement cut-off, checkout support email / phone.',
                    ],
                    [
                        'System › IP Management',
                        'Every partner’s allowed IPs, and which IPs called the API in the last 7 days (and how many were refused).',
                    ],
                    [
                        'System › Audit Logs',
                        'Who did what and when, for everything important.',
                    ],
                    ['Guide', 'This page.'],
                ],
            },
        ],
        links: [
            link('Bank & UPI Accounts', adminAccounts.index()),
            link('Partners', adminPartners.index()),
            link('Branches', adminBranches.index()),
            link('Partner → branches', adminMappings.byPartner()),
            link('Users', adminUsers.index()),
            link('Roles & Permissions', adminRoles.index()),
            link('Audit Logs', admin.auditLogs.index()),
        ],
    },
    {
        id: 'branch-portal',
        group: 'Menus',
        title: 'Branch portal: what a branch can do',
        icon: Landmark,
        summary:
            'Use this to explain the branch portal on a call. Branch pages open only with a branch login, so the addresses are shown as text.',
        blocks: [
            {
                kind: 'table',
                head: ['Menu', 'What the branch does there', 'Address'],
                rows: [
                    ['Dashboard', 'Its own figures', '`/branch`'],
                    [
                        'Bank & UPI Accounts',
                        'Add its accounts (Admin verifies them), see limits and the account log',
                        '`/branch/accounts`',
                    ],
                    [
                        'Payments › Deposit › Pay-in History',
                        'All deposits paid into its accounts',
                        '`/branch/payins`',
                    ],
                    [
                        'Payments › Deposit › Manual Deposit',
                        'Approve, hold or decline waiting deposits',
                        '`/branch/deposits`',
                    ],
                    [
                        'Payments › Deposit › Manual Entry',
                        'Type or import its bank statement lines',
                        '`/branch/statements`',
                    ],
                    [
                        'Payments › Deposit › Unsettled UTR',
                        'Its unsettled lines: link or close them',
                        '`/branch/unsettled`',
                    ],
                    [
                        'Payments › Withdrawal › Pay-out History',
                        'All its payouts',
                        '`/branch/payout-history`',
                    ],
                    [
                        'Payments › Withdrawal › Manual Payout',
                        'Start, pay (in parts) or fail payouts',
                        '`/branch/payouts`',
                    ],
                    [
                        'Payments › Chargebacks',
                        'Raise a chargeback with the bank’s notice',
                        '`/branch/chargebacks`',
                    ],
                    [
                        'Payments › Payout returns',
                        'Confirm money that came back',
                        '`/branch/payout-returns`',
                    ],
                    [
                        'Account › Statement History',
                        'Its imported statement files',
                        '`/branch/statements/imports`',
                    ],
                    [
                        'Finance › Branch Settlement Dashboard',
                        'What it owes or is owed',
                        '`/branch/settlement-dashboard`',
                    ],
                    [
                        'System › Users / Audit Logs',
                        'Its own users and history',
                        '`/branch/users` · `/branch/audit-logs`',
                    ],
                ],
            },
            {
                kind: 'points',
                title: 'Branch rules',
                items: [
                    'A branch sees only **its own** accounts, deposits, payouts and lines.',
                    'A branch never sees partner fees or other branches.',
                    'A branch **cannot edit** a bank line. Only Admin can correct a line’s UTR or amount.',
                    'A branch cannot approve a late payment. Only Admin can.',
                    'Roles: **Branch admin** (everything, including users) and **Operator** (approves deposits, processes payouts, imports statements). Operators cannot resolve unsettled cases.',
                ],
            },
        ],
    },
    {
        id: 'partner-portal',
        group: 'Menus',
        title: 'Partner portal: what a partner can do',
        icon: Store,
        summary:
            'Use this to explain the partner portal. Partner pages open only with a partner login, so the addresses are shown as text.',
        blocks: [
            {
                kind: 'table',
                head: ['Menu', 'What the partner does there', 'Address'],
                rows: [
                    ['Dashboard', 'Its own figures', '`/partner`'],
                    [
                        'Bank & UPI Accounts',
                        'The active bank accounts and UPI IDs given to it (no limits)',
                        '`/partner/accounts`',
                    ],
                    [
                        'Payments › Deposit',
                        'All its deposits, with Order ID and status',
                        '`/partner/payins`',
                    ],
                    [
                        'Payments › Withdrawal',
                        'All its payouts, with Order ID and status',
                        '`/partner/payouts`',
                    ],
                    [
                        'Payments › Chargebacks',
                        'Chargebacks on its deposits',
                        '`/partner/chargebacks`',
                    ],
                    [
                        'Payments › Payout returns',
                        'Raise “customer did not receive it” on a successful payout',
                        '`/partner/payout-returns`',
                    ],
                    [
                        'Finance › Partner Settlement Dashboard',
                        'Its totals and balance (no branches)',
                        '`/partner/settlement-dashboard`',
                    ],
                    [
                        'Developers › API & Webhooks',
                        'API keys, webhook URLs, **Allowed IPs**',
                        '`/partner/developers`',
                    ],
                    [
                        'Developers › API documentation',
                        'How to connect to our API',
                        '`/partner/api-docs`',
                    ],
                    [
                        'Developers › API Logs',
                        'Every API call it made and our answer',
                        '`/partner/api-logs`',
                    ],
                    ['System › Users', 'Its own users', '`/partner/users`'],
                    [
                        'Account › Business profile',
                        'Its company details',
                        '`/partner/profile`',
                    ],
                ],
            },
            {
                kind: 'points',
                title: 'Partner rules',
                items: [
                    'A partner sees only **its own** deposits, payouts and balance.',
                    'A partner **never** sees branches, branch accounts or branch commission.',
                    'Roles: **Owner** (everything, including users), **Developer** (API integration), **Viewer** (look only, no export).',
                ],
            },
        ],
    },

    // ─── Partners & API ──────────────────────────────────────────────────
    {
        id: 'partner-api',
        group: 'Partners & API',
        title: 'Partner API not working? (IP whitelist and errors)',
        icon: PlugZap,
        summary:
            'Most “API not working” problems are an IP that is not whitelisted, a wrong key, or a wrong server clock.',
        blocks: [
            {
                kind: 'steps',
                title: 'Check in this order',
                items: [
                    'Open **System › IP Management**. Find the partner. If it says “No allowed IPs: the API is closed”, nothing will work until an IP is added.',
                    'Look at “Called in the last 7 days”. An IP in red with **refused** calls = the partner’s server is calling from an IP that is not on the list.',
                    'Ask the partner for its **server’s public IP** (not their office or mobile IP). Then go to **Network › Partners › Edit › API & security › Allowed IPs**, add it and Save. The partner can also add it under **Developers › API & Webhooks › Allowed IPs**.',
                    'One IP per line, or a range like `10.0.0.0/24`.',
                    'Ask the partner to try again. Check their **API Logs** with them for the error code.',
                    'Check the partner is **Active** and that deposits / withdrawals are switched on for it.',
                ],
            },
            {
                kind: 'mock',
                title: 'IP Management and Allowed IPs',
                node: <IpMock />,
                marks: [
                    'IP Management: the partner has no allowed IPs, and IP 203.0.113.25 was refused 12 times.',
                    'Add that IP in the partner’s Allowed IPs and Save.',
                ],
            },
            {
                kind: 'table',
                title: 'API error codes: what to tell the partner',
                head: ['Error code', 'Meaning', 'What to tell them'],
                rows: [
                    [
                        '`ip_not_allowed`',
                        'Request came from an IP not on the list',
                        'Send us your server IP; we add it to Allowed IPs.',
                    ],
                    [
                        '`unauthenticated`',
                        'Missing headers, or unknown / revoked API key',
                        'Use the correct active key and send all 4 headers (X-Key-Id, X-Timestamp, X-Nonce, X-Signature).',
                    ],
                    [
                        '`invalid_signature`',
                        'The signature does not match',
                        'Sign exactly as in the API documentation, with the right secret.',
                    ],
                    [
                        '`timestamp_out_of_range`',
                        'Server clock is wrong (more than 5 minutes)',
                        'Fix your server time.',
                    ],
                    [
                        '`nonce_reused`',
                        'Same X-Nonce sent twice',
                        'Send a new random nonce with every request.',
                    ],
                    [
                        '`rate_limited`',
                        'Too many requests per minute',
                        'Slow down and retry after the given seconds.',
                    ],
                    [
                        '`partner_inactive`',
                        'The partner is switched off',
                        'Admin must activate the partner.',
                    ],
                    [
                        '`payin_disabled` / `payout_disabled`',
                        'Deposits or withdrawals are off for the partner',
                        'Admin switches them on (Partner edit).',
                    ],
                    [
                        '`duplicate_order_id`',
                        'Same Order ID with different details',
                        'Use a new Order ID for a new payment.',
                    ],
                    [
                        '`duplicate_request`',
                        'Same customer, same amount, unpaid, a few seconds ago',
                        'Send the customer to the first payment link.',
                    ],
                    [
                        '`no_branch_available`',
                        'No branch can take this payout now',
                        'Try later or a different amount; Admin checks branch limits and mappings.',
                    ],
                    [
                        '`amount_out_of_range`',
                        'Amount outside the partner’s min / max',
                        'Use an amount within the limits.',
                    ],
                    [
                        '`return_url_not_allowed`',
                        'Return URL is not on the partner’s website',
                        'Use a return URL on your registered domain.',
                    ],
                    [
                        '`customer_blocked`',
                        'This customer is blocked',
                        'Contact Admin.',
                    ],
                ],
            },
            {
                kind: 'points',
                title: 'Webhooks (our messages to the partner)',
                items: [
                    'We send a webhook when a deposit or payout changes (success, declined, chargeback, returned…).',
                    'If the partner’s server does not answer, we try again after 1, 5, 15, 60 minutes, 6 hours and 24 hours.',
                    'Admin can see each webhook on the transaction and **resend** it.',
                    'The partner’s webhook URLs are set under API & security (Admin) or API & Webhooks (partner).',
                ],
            },
        ],
        links: [
            link('IP Management', admin.ipManagement.index()),
            link('Partners', adminPartners.index()),
        ],
    },
    {
        id: 'roles',
        group: 'Partners & API',
        title: 'Roles and what each can do',
        icon: Users,
        summary:
            'Each user has one role. The role decides which menus and buttons they see.',
        blocks: [
            {
                kind: 'table',
                head: ['Portal', 'Role', 'Can do'],
                rows: [
                    ['Admin', 'Admin', 'Full access to the admin portal'],
                    [
                        'Admin',
                        'Operations',
                        'Verifies accounts, maps branches, oversees transactions and reconciliation',
                    ],
                    [
                        'Admin',
                        'Finance',
                        'Calculates and records settlements and adjustments',
                    ],
                    ['Admin', 'Viewer', 'Look only'],
                    [
                        'Branch',
                        'Branch admin',
                        'Full access to the branch portal, including its users',
                    ],
                    [
                        'Branch',
                        'Operator',
                        'Approves deposits, processes payouts, imports statements',
                    ],
                    [
                        'Partner',
                        'Owner',
                        'Full access to the partner portal, including its users',
                    ],
                    ['Partner', 'Developer', 'Manages the API integration'],
                    [
                        'Partner',
                        'Viewer',
                        'Views transactions, balance and reports (no export)',
                    ],
                ],
            },
            {
                kind: 'points',
                items: [
                    'Every user needs two-factor login (an authenticator app code).',
                    'Some actions need a second person, e.g. a chargeback is approved by someone other than the person who raised it.',
                ],
            },
        ],
        links: [
            link('Users', adminUsers.index()),
            link('Roles & Permissions', adminRoles.index()),
        ],
    },

    // ─── Help ────────────────────────────────────────────────────────────
    {
        id: 'faq',
        group: 'Help',
        title: 'Common questions from branches and partners',
        icon: HelpCircle,
        summary:
            'Quick answers. Search this page for a word if you do not find it here.',
        blocks: [
            {
                kind: 'table',
                head: ['Question', 'Answer'],
                rows: [
                    [
                        '“Customer paid but the deposit is still Pending.”',
                        'The branch has not entered the bank line yet, or the UTR / amount / account does not match. Check Manual Entry and Unsettled UTR.',
                    ],
                    [
                        '“Customer paid but the deposit shows Expired.”',
                        'The money came late. The bank line becomes a **Late payment** case. Admin approves late from Unsettled UTR.',
                    ],
                    [
                        '“Customer paid a different amount.”',
                        'The line becomes **Amount mismatch**. It can’t be approved: decline it so the customer pays the right amount on a new deposit.',
                    ],
                    [
                        '“The UTR is refused.”',
                        'That UTR is already on a pending or successful payment. One UTR = one payment. Admin checks the other payment.',
                    ],
                    [
                        '“The money came to the wrong branch.”',
                        '**Wrong branch** case. Admin moves the deposit to the account that got the money.',
                    ],
                    [
                        '“The customer paid ₹500 at Branch 1 but entered the UTR on a new ₹1,000 request at Branch 2.”',
                        'When Branch 1 enters the bank line (UTR and ₹500), the request moves to Branch 1 by itself if this customer was shown Branch 1’s account in the last day. It is **not** approved by itself: Branch 1 sees **Amount mismatch** and can only **Decline** it (a different amount is never approved). Otherwise it stays a **Wrong branch** case for Admin.',
                    ],
                    [
                        '“Why was the deposit not approved automatically?”',
                        'Only exact matches are automatic: same UTR, same amount, same account, one deposit. Also check that auto-approve is on in Global Settings.',
                    ],
                    [
                        '“Partner says payout not received.”',
                        'Check the payout’s UTR and proof. If the money came back to the branch, the partner raises a **Payout return** and the branch confirms it.',
                    ],
                    [
                        '“Partner API gives an error.”',
                        'See the Partner API section. Most often: IP not in Allowed IPs.',
                    ],
                    [
                        '“Partner did not get the webhook.”',
                        'Open the transaction, check the webhook status and click resend. Check the partner’s webhook URL.',
                    ],
                    [
                        '“Payout says Balance is low.”',
                        'The partner’s balance must cover amount + fee. Settle or wait for more deposits.',
                    ],
                    [
                        '“Why can’t the branch edit a bank line?”',
                        'Only Admin can correct a line’s UTR or amount, with a reason, to stop mistakes and fraud.',
                    ],
                    [
                        '“Where is the Transaction ID / Order ID?”',
                        'In every deposit and payout list and detail. You can search by either one, or by UTR.',
                    ],
                    [
                        '“Can a partner see which branch got the money?”',
                        'No. Partners never see branch details.',
                    ],
                ],
            },
        ],
    },
];
