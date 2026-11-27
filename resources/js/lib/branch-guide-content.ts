import type { LucideIcon } from 'lucide-react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Bell,
    FileSearch,
    FileSpreadsheet,
    HelpCircle,
    LayoutDashboard,
    ListChecks,
    LogIn,
    Network,
    RotateCcw,
    Scale,
    ShieldCheck,
    UserCog,
    Users,
} from 'lucide-react';

/**
 * The branch Guide (Branch › Guide, client request 2026-11-26): how to use
 * the branch portal, step by step in simple English, with real screenshots
 * (public/guide/branch/*.jpg, taken from demo data on the testbox). Text may
 * use **bold** and `code`.
 *
 * Keep it true to the code: when a branch screen, button or rule changes,
 * change the step here and retake the screenshot.
 */

export type BranchGuideBlock =
    | { kind: 'steps'; title?: string; items: string[] }
    | { kind: 'points'; title?: string; items: string[] }
    | { kind: 'table'; title?: string; head: string[]; rows: string[][] }
    | { kind: 'note'; tone?: 'info' | 'warn'; text: string }
    | { kind: 'shot'; src: string; caption: string; narrow?: boolean };

export type BranchGuideSection = {
    id: string;
    group: string;
    title: string;
    icon: LucideIcon;
    /** Who sees the menu: everyone in the branch, or the Branch admin only. */
    who: 'everyone' | 'admin';
    summary: string;
    blocks: BranchGuideBlock[];
};

const shot = (
    file: string,
    caption: string,
    narrow = false,
): BranchGuideBlock => ({
    kind: 'shot',
    src: `/guide/branch/${file}.jpg`,
    caption,
    narrow,
});

export const BRANCH_GUIDE_GROUPS = [
    'Start here',
    'Bank & UPI accounts',
    'Deposits',
    'Withdrawals',
    'Money back',
    'Money & reports',
    'Team',
    'Help',
];

export const BRANCH_GUIDE: BranchGuideSection[] = [
    // ─── Start here ──────────────────────────────────────────────────────
    {
        id: 'basics',
        group: 'Start here',
        title: 'What a branch does (in 1 minute)',
        icon: ListChecks,
        who: 'everyone',
        summary:
            'Your branch receives customers’ deposits into its own bank / UPI accounts and pays customers’ withdrawals from its bank. This portal is where you confirm both.',
        blocks: [
            {
                kind: 'points',
                items: [
                    '**Deposit (pay-in)**: a customer pays money into **your** bank or UPI account. You check your bank and **Approve** it.',
                    '**Withdrawal (payout)**: a customer must be paid. You send the money from **your** bank and **Record the transfer**.',
                    '**Bank lines**: you type (or import) what your bank statement shows, so the system can match it with deposits and payouts.',
                    'You earn a **commission** on every deposit and withdrawal you handle.',
                    'You only ever see **your own** branch: your accounts, your deposits, your payouts.',
                ],
            },
            {
                kind: 'table',
                title: 'Two kinds of branch login',
                head: ['Role', 'What they can do'],
                rows: [
                    [
                        'Branch admin',
                        'Everything: accounts, deposits, payouts, bank lines, settlement, users and audit logs.',
                    ],
                    [
                        'Operator',
                        'Daily work only: approve deposits, pay payouts, enter / import bank lines, raise chargebacks. Sees accounts but can’t add or change them. No Users, Audit Logs or Settlement.',
                    ],
                ],
            },
            {
                kind: 'note',
                text: 'Sections of this guide marked **Branch admin only** are menus an Operator does not see.',
            },
        ],
    },
    {
        id: 'login',
        group: 'Start here',
        title: 'Log in',
        icon: LogIn,
        who: 'everyone',
        summary: 'Your login is a username and a password. There is no email.',
        blocks: [
            shot('01-login', 'The login page.'),
            {
                kind: 'steps',
                items: [
                    'Open the branch portal link you were given.',
                    'Type your **Username** and **Password** (your Branch admin gives you both).',
                    'Click **Log in**. You land on the **Dashboard**.',
                    'If a **6-digit code** is asked, open your authenticator app (Google Authenticator, Microsoft Authenticator…) and type the code shown for this portal.',
                ],
            },
            {
                kind: 'points',
                title: 'Good to know',
                items: [
                    '**Forgot your password?** There is no reset link. Ask your **Branch admin** to set a new one for you (Users › View details › Set password). A Branch admin who forgot theirs asks PayGate.',
                    'After 5 wrong tries the login waits a minute. Wait and try again.',
                    'An inactive (deactivated) user can’t log in. Ask your Branch admin.',
                    '**One browser at a time.** If you log in on another browser or computer with the same username, the older one is logged out with a message.',
                ],
            },
        ],
    },
    {
        id: 'two-factor',
        group: 'Start here',
        title: 'First login: set up two-factor (2FA) and change your password',
        icon: ShieldCheck,
        who: 'everyone',
        summary:
            'On the live server every branch user must turn on 2FA before using the portal. It protects the branch’s money if a password leaks. The testing and sandbox servers don’t use 2FA.',
        blocks: [
            shot(
                '35-security',
                'Profile & settings › Security: change your password and turn on 2FA.',
            ),
            {
                kind: 'steps',
                title: 'Turn on 2FA',
                items: [
                    'If the portal says **“Set up two-factor authentication to continue”**, you are already on the right page. Otherwise open **Profile & settings › Security** (it asks your password once).',
                    'Install an authenticator app on your phone (Google Authenticator or Microsoft Authenticator).',
                    'Click **Enable 2FA**. A QR code appears.',
                    'In the app tap **+** › **Scan a QR code**, and scan it.',
                    'Type the 6-digit code from the app and confirm.',
                    'Save the **recovery codes** somewhere safe (on paper). Each one works once if you lose your phone.',
                ],
            },
            {
                kind: 'steps',
                title: 'Change your password',
                items: [
                    'On the same page, type your **Current password**.',
                    'Type the **New password** twice (at least 6 characters).',
                    'Click **Save**.',
                ],
            },
            {
                kind: 'note',
                tone: 'warn',
                text: 'Lost your phone and your recovery codes? Ask **PayGate (Admin)** to **Reset 2FA** for you, then set it up again at your next login. Only Admin can reset 2FA, so even a Branch admin who lost their phone can get back in.',
            },
        ],
    },
    {
        id: 'screen',
        group: 'Start here',
        title: 'The screen: menu, top bar and Dashboard',
        icon: LayoutDashboard,
        who: 'everyone',
        summary:
            'The menu is on the left, quick buttons on the top right, and the Dashboard shows your day at a glance.',
        blocks: [
            shot('02-dashboard', 'The Dashboard with the menu on the left.'),
            {
                kind: 'table',
                title: 'Left menu',
                head: ['Menu', 'Use it to'],
                rows: [
                    ['Dashboard', 'See today’s numbers.'],
                    [
                        'Bank & UPI Accounts',
                        'See (and, as Branch admin, add) the accounts customers pay into.',
                    ],
                    [
                        'Payments › Deposit',
                        'All deposits, **Manual Deposit** (approve), **Manual Entry** (bank lines), **Unsettled UTR**, **UTR Reconciliation**.',
                    ],
                    [
                        'Payments › Withdrawal',
                        'All withdrawals, **Manual Payout** (pay customers), UTR Reconciliation.',
                    ],
                    [
                        'Payments › Chargebacks',
                        'Deposits the customer’s bank took back.',
                    ],
                    [
                        'Payments › Payout returns',
                        'Payouts that came back or that the partner says were not received.',
                    ],
                    [
                        'Account › Statement History',
                        'Every bank file you imported.',
                    ],
                    [
                        'Branch Settlement Dashboard',
                        'What you owe PayGate or PayGate owes you (Branch admin).',
                    ],
                    [
                        'System › Users / Audit Logs',
                        'Your team and what they did (Branch admin).',
                    ],
                    [
                        'Profile & settings',
                        'Your name, password, 2FA, light / dark look, alerts.',
                    ],
                    ['Guide', 'This page.'],
                ],
            },
            {
                kind: 'points',
                title: 'Top bar (right side)',
                items: [
                    '**Server badge**: “Testbox” or “Sandbox” = test server, **no real money**. “Live” = **real money**. Always check it before you approve or pay.',
                    '**Rows button**: compact or comfortable rows in lists.',
                    '**Moon / sun**: dark or light look.',
                    '**Speaker**: a sound plays when a new deposit or withdrawal arrives. Click to turn it on or off.',
                    '**Bell**: your alerts (see “Alerts and sound”).',
                ],
            },
            {
                kind: 'points',
                title: 'Dashboard',
                items: [
                    'Pick a period at the top: **1h, 24h, 7 Days, 30 Days, Today, Yesterday, This Month, Previous Month**, or choose dates and click **Submit**.',
                    '**Pay-in / Pay-out** switches the three big cards between deposits and withdrawals: total, successful, failed.',
                    '**Pending manual deposits** and **Unsettled UTR**: work waiting for you. Keep these near zero.',
                    '**Current position**: **−** means you owe PayGate, **+** means PayGate owes you.',
                    '**Commission earned**: what you earned in the period.',
                    '**Account usage · today**: how much each account received against its daily limit.',
                ],
            },
        ],
    },
    {
        id: 'alerts',
        group: 'Start here',
        title: 'Alerts (bell) and sound',
        icon: Bell,
        who: 'everyone',
        summary:
            'The bell tells you when something needs you: a deposit waiting too long, a payout to pay, an account verified, a chargeback…',
        blocks: [
            shot('31-bell', 'Click the bell to see your alerts.'),
            {
                kind: 'steps',
                items: [
                    'A red number on the bell = unread alerts.',
                    'Click the bell. Click an alert to open the page where you act on it.',
                    '**Mark all read** clears the number.',
                    '**Notification settings** (bottom of the list) lets you switch off alerts you don’t need.',
                ],
            },
            shot(
                '33-notifications',
                'Profile & settings › Notifications: turn each alert on or off, then Save.',
            ),
            {
                kind: 'note',
                text: 'Alerts only appear in the bell. Nothing is sent by email or SMS, so keep the portal open during working hours with the **sound on**.',
            },
        ],
    },

    // ─── Bank & UPI accounts ─────────────────────────────────────────────
    {
        id: 'accounts',
        group: 'Bank & UPI accounts',
        title: 'Your bank & UPI accounts',
        icon: Network,
        who: 'everyone',
        summary:
            'Customers pay into these accounts. Only accounts that are **Verified** and **Active** get customers.',
        blocks: [
            shot('03-accounts', 'Bank & UPI Accounts.'),
            {
                kind: 'points',
                items: [
                    '**Deposit limits**: minimum and maximum per payment, per day, and the number of payments per day. When an account reaches a limit, customers are sent to another account. Limits reset every day at 00:00.',
                    '**Bank/UPI details**: what customers see and pay to.',
                    '**Verification**: PayGate checks every new account. **Waiting for PayGate** → **Verified** (or Unverified).',
                    '**Status**: **Active** gets customers, **Inactive** gets none.',
                    '**Received today**: money paid in today across your accounts.',
                ],
            },
        ],
    },
    {
        id: 'account-add',
        group: 'Bank & UPI accounts',
        title: 'Add, change or pause an account',
        icon: Network,
        who: 'admin',
        summary:
            'Branch admin only. A new account gets customers after PayGate verifies it and you activate it.',
        blocks: [
            shot(
                '04-account-add',
                'Add account: bank details, UPI and limits.',
                true,
            ),
            {
                kind: 'steps',
                title: 'Add a new account',
                items: [
                    'Open **Bank & UPI Accounts** and click **Add account**.',
                    '**Label**: your own name for it, e.g. “SBI current 2”.',
                    '**Bank transfer** on: type the **Account holder name** exactly as in the bank, the **Bank name**, **IFSC** and **Account number**.',
                    '**UPI** on (optional): type the **UPI ID** and name. Customers can then pay by UPI and by QR code (the QR code comes with UPI automatically and carries the exact amount).',
                    '**Limits**: set minimum, maximum, daily amount and daily count. **−1 means unlimited**.',
                    'Click **Add account**. It shows **Waiting for PayGate**.',
                    'When PayGate verifies it you get a bell alert “… verified”. Click **Activate** on the account. It now gets customers.',
                ],
            },
            {
                kind: 'points',
                title: 'Change or pause',
                items: [
                    '**Edit**: changing bank or UPI details sends the account for **verification again**. Changing only the limits does not.',
                    '**Turn off**: stops new customers at once (e.g. the bank froze the account). **Activate** to start again.',
                    'The same account number or UPI ID can’t be added twice, even by another branch.',
                    '**Log** (or **Account log** at the top) shows every change: who, when and what.',
                ],
            },
            shot('34-account-log', 'Account log: the history of every change.'),
        ],
    },

    // ─── Deposits ────────────────────────────────────────────────────────
    {
        id: 'deposit-flow',
        group: 'Deposits',
        title: 'How a deposit reaches you',
        icon: ArrowDownToLine,
        who: 'everyone',
        summary: 'From the customer’s click to Success.',
        blocks: [
            {
                kind: 'steps',
                items: [
                    'A customer on the partner’s website asks to deposit money.',
                    'Our system picks **one of your active accounts** and shows it to the customer (branches and accounts take turns, within limits).',
                    'The customer pays from their bank app by UPI, QR code or bank transfer.',
                    'The customer types the **UTR** (bank reference) and uploads a **payment screenshot**. The deposit is now **Pending** and appears in your **Manual Deposit** list with a sound.',
                    'You check your bank. If the money is there → **Approve**. The partner is told at once and the customer sees “Success”.',
                    'If you enter the bank line first (Manual Entry or import) with the **same UTR + same amount + same account**, the deposit can be approved **automatically**.',
                ],
            },
            {
                kind: 'table',
                title: 'Deposit status words',
                head: ['Status', 'Meaning', 'What you do'],
                rows: [
                    [
                        'Not paid yet',
                        'Payment page made, customer has not paid',
                        'Nothing. It expires by itself.',
                    ],
                    [
                        'Pending',
                        'Customer says “I paid” and gave a UTR',
                        'Check your bank → Approve or Decline.',
                    ],
                    [
                        'Payment hold',
                        'Put on hold to check more',
                        'Decide later: Approve or Decline.',
                    ],
                    ['Success', 'Approved. Partner is told.', 'Nothing.'],
                    [
                        'Declined',
                        'Not paid, wrong amount, fake…',
                        'Nothing. The UTR is free again.',
                    ],
                    [
                        'Expired',
                        'Customer didn’t pay in time',
                        'If money arrives later, PayGate decides (late payment).',
                    ],
                ],
            },
        ],
    },
    {
        id: 'approve',
        group: 'Deposits',
        title: 'Manual Deposit: check and approve a deposit',
        icon: ArrowDownToLine,
        who: 'everyone',
        summary:
            'Your most important daily job. Approve only when you see the money in **your** bank.',
        blocks: [
            shot(
                '05-manual-deposit',
                'Payments › Deposit › Manual Deposit. The cards at the top count what is waiting.',
            ),
            shot(
                '06-deposit-cards',
                'Each waiting deposit is a card with the customer’s UTR, proof and the buttons.',
            ),
            {
                kind: 'steps',
                title: 'Approve',
                items: [
                    'Open **Payments › Deposit** and click the **Manual Deposit** tab. The number on the tab = deposits waiting.',
                    'Take the first card. Note the **amount**, the **Account** it was paid into and the **Customer UTR**.',
                    'Click **View** next to Payment proof to see the customer’s screenshot.',
                    'Open your **bank app / net banking** for that account and find a credit with the **same UTR** and the **same amount**.',
                    'Found it? Click **Approve**.',
                    'Type the **Bank UTR** exactly as your bank statement shows it (not copied from the customer). The amount is fixed to the order amount.',
                    'Click **Approve**. The deposit becomes **Success**, the partner is credited and the card disappears. **This can’t be undone.**',
                ],
            },
            shot('07-approve', 'The Approve window.', true),
            {
                kind: 'points',
                title: 'Rules',
                items: [
                    'If the bank UTR is **different** from the customer’s, the window turns red and asks why. Approve only if your bank entry really is this deposit. PayGate is told.',
                    'A **different amount** arrived? Don’t approve. **Decline** it with reason “wrong amount”.',
                    '**One UTR = one payment.** A UTR already used can’t be used again.',
                    'Switch **Cards / Dense list** (top right) to see more deposits at once.',
                    'The period buttons work like the Dashboard. A yellow line “**N older deposits are still waiting**” → click **Show them** so nothing old is forgotten.',
                ],
            },
        ],
    },
    {
        id: 'decline',
        group: 'Deposits',
        title: 'Decline, Hold or “Not in our bank”',
        icon: ArrowDownToLine,
        who: 'everyone',
        summary: 'When the money is not there, or you are not sure yet.',
        blocks: [
            {
                kind: 'table',
                head: ['Button', 'Use it when', 'What happens'],
                rows: [
                    [
                        '✕ (Decline)',
                        'No money came, wrong amount, fake or reused screenshot.',
                        'Pick a reason. The partner is told it failed; the account’s limit is freed.',
                    ],
                    [
                        'Hold',
                        'You need time (bank is slow, you asked the customer).',
                        'Moves to **Payment hold** with your reason. Approve or Decline later.',
                    ],
                    [
                        'Not in our bank',
                        'The UTR is not in **your** account at all: the customer probably paid another branch.',
                        'Goes on hold and PayGate is asked to move it to the right branch.',
                    ],
                ],
            },
            shot('08-decline', 'Decline: pick a reason, add details.', true),
            shot('09-hold', 'Hold: write why.', true),
            shot(
                '10-not-in-bank',
                'Not in our bank: PayGate will move it.',
                true,
            ),
        ],
    },
    {
        id: 'find-deposit',
        group: 'Deposits',
        title: 'Find any deposit and see its details',
        icon: FileSearch,
        who: 'everyone',
        summary:
            '**All deposits** lists every deposit paid into your accounts. Click one to see everything about it.',
        blocks: [
            shot(
                '12-all-deposits',
                'Payments › Deposit › All deposits, with status tabs and search.',
            ),
            {
                kind: 'steps',
                items: [
                    'Open **Payments › Deposit** (it opens on **All deposits**).',
                    'Use the tabs: **Pending, On hold, Success, Declined, Not paid yet, Expired, Chargeback**.',
                    'Search by **Transaction ID** (starts with `PI`), **Order ID**, **UTR**, customer ID or name.',
                    'Click a row (or **View details**) to open the side panel.',
                ],
            },
            shot(
                '11-deposit-details',
                'Deposit details: amount, your commission and the tabs.',
            ),
            {
                kind: 'points',
                title: 'Tabs in the side panel',
                items: [
                    '**Overview**: customer, account paid into, customer UTR and verified bank UTR, your commission.',
                    '**Timeline**: every step with time and who did it.',
                    '**Proof**: the customer’s screenshot.',
                    '**Bank**: the bank line matched to it.',
                ],
            },
        ],
    },
    {
        id: 'manual-entry',
        group: 'Deposits',
        title: 'Manual Entry: put your bank lines in the system',
        icon: FileSpreadsheet,
        who: 'everyone',
        summary:
            'Type what your bank statement shows. The system matches each line with a deposit (same UTR + amount + account) and can approve it by itself.',
        blocks: [
            shot(
                '13-manual-entry',
                'Payments › Deposit › Manual Entry: the New statement line form.',
            ),
            {
                kind: 'steps',
                title: 'Add one line',
                items: [
                    'Open **Payments › Deposit › Manual Entry**.',
                    'Choose the **Bank account** (only active accounts are listed).',
                    'Pick the **Date** from the statement.',
                    'Type the **UTR / reference** and the **Credit ₹** amount exactly as in the bank.',
                    '**Description** is optional (copy it from the statement).',
                    'Click **Add line**. The line appears below with its status.',
                ],
            },
            shot(
                '14-manual-entry-lines',
                'Your lines with their status and the deposit they matched.',
            ),
            {
                kind: 'table',
                title: 'Line status',
                head: ['Status', 'Meaning'],
                rows: [
                    [
                        'Approved',
                        'Matched a deposit, and the deposit is Success.',
                    ],
                    [
                        'Pending',
                        'Matched a deposit that still waits for approval.',
                    ],
                    ['Payment hold', 'Matched a deposit that is on hold.'],
                    [
                        'Unsettled',
                        'Did not match cleanly. It is in **Unsettled UTR**.',
                    ],
                    [
                        'Closed',
                        'PayGate closed it (e.g. bank charges, not a customer payment).',
                    ],
                ],
            },
            {
                kind: 'note',
                tone: 'warn',
                text: 'You can’t edit or delete a line. Typed it wrong? Ask PayGate to correct it. Never type the same UTR twice.',
            },
        ],
    },
    {
        id: 'import',
        group: 'Deposits',
        title: 'Import a bank statement file (many lines at once)',
        icon: FileSpreadsheet,
        who: 'everyone',
        summary:
            'Upload the Excel or CSV file from your bank and tick the lines that belong to PayGate.',
        blocks: [
            shot('15-import', 'Import statement: account and file.', true),
            {
                kind: 'steps',
                items: [
                    'In **Manual Entry** click **Import statement**.',
                    'Choose the **Account** and the **file**: **.csv, .xls or .xlsx** only (up to 5 MB, 5,000 lines). PDF can’t be read: download Excel / CSV from net banking.',
                    'Click **Continue**. **Check the columns**: the system guesses which column is the date, UTR, description and amount. Fix any that are wrong, then **Show lines**.',
                    'Every line of the file is listed **unticked**. Tick **only** customer payments. Lines you leave unticked (salary, own transfers…) are never stored.',
                    'The **Check** column tells you before you import: ✓ will match a deposit, or ⚠ will go to Unsettled UTR (and why).',
                    'Click **Import N lines**. A message says how many matched and how many went to Unsettled UTR.',
                ],
            },
            {
                kind: 'points',
                title: 'Good to know',
                items: [
                    'You can upload the same file again later: lines already in the system are skipped, so overlapping dates are fine.',
                    'Imported on the wrong account? Ask PayGate to **Undo import**, then import again on the right account.',
                    '**Account › Statement History** lists all your imports.',
                ],
            },
            shot(
                '26-statement-history',
                'Account › Statement History: every file you imported.',
            ),
        ],
    },
    {
        id: 'unsettled',
        group: 'Deposits',
        title: 'Unsettled UTR: bank lines that didn’t match',
        icon: FileSearch,
        who: 'everyone',
        summary:
            'A bank line that has no matching deposit (same UTR, amount and account) opens a case here.',
        blocks: [
            shot(
                '16-unsettled',
                'Payments › Deposit › Unsettled UTR. “Why” says what is wrong.',
            ),
            {
                kind: 'table',
                head: ['Case', 'Meaning', 'What you do'],
                rows: [
                    [
                        'UTR not found / No deposit yet',
                        'No deposit has this UTR yet.',
                        'Wait: it links itself when the customer submits the UTR.',
                    ],
                    [
                        'Amount mismatch',
                        'Same UTR, different amount.',
                        '**Decline** the deposit. If you typed the line wrong, ask PayGate to correct it.',
                    ],
                    [
                        'Wrong bank account',
                        'The deposit was for your other account.',
                        'Click **Match** and choose the right bank account.',
                    ],
                    [
                        'Wrong branch',
                        'The deposit belongs to another branch.',
                        'Nothing: “Admin will move it”.',
                    ],
                    [
                        'Duplicate UTR / Late payment / Manual review',
                        'Needs a closer look.',
                        'Open **View details**. PayGate decides.',
                    ],
                ],
            },
            {
                kind: 'points',
                items: [
                    'Open cases from earlier days show in a yellow line: **Show them**.',
                    'Only PayGate can close a line that isn’t a customer payment, or approve a late payment.',
                ],
            },
        ],
    },
    {
        id: 'reconciliation',
        group: 'Deposits',
        title: 'UTR Reconciliation: is every approval backed by the bank?',
        icon: FileSearch,
        who: 'everyone',
        summary:
            'The other side of Manual Entry: every approved deposit and paid payout, and whether a bank line confirms it.',
        blocks: [
            shot('17-recon', 'Payments › Deposit › UTR Reconciliation.'),
            {
                kind: 'points',
                items: [
                    '**Not in a statement** = approved, but no bank line yet. Enter the bank line (Manual Entry or import).',
                    'At the end of each day this list should show every approval as confirmed.',
                    'If you approved by hand and no bank line comes after some hours, PayGate is alerted.',
                ],
            },
        ],
    },

    // ─── Withdrawals ─────────────────────────────────────────────────────
    {
        id: 'payout',
        group: 'Withdrawals',
        title: 'Manual Payout: pay a customer',
        icon: ArrowUpFromLine,
        who: 'everyone',
        summary:
            'A partner’s customer must be paid. You send the money from your bank, then record the transfer here.',
        blocks: [
            shot(
                '18-manual-payout',
                'Payments › Withdrawal › Manual Payout. To pay / Being paid / Paid today / Failed today.',
            ),
            shot(
                '19-payout-cards',
                'Each payout card shows who to pay and where, with copy buttons.',
            ),
            {
                kind: 'steps',
                items: [
                    'Open **Payments › Withdrawal › Manual Payout**. New payouts come with a sound and a bell alert.',
                    'Click **Start** on a payout (status **Assigned** → **Processing**). It tells everyone you are paying it.',
                    'Use the **copy** buttons to copy the name, account number, IFSC or UPI ID and the amount.',
                    'Send the money from **your** bank app.',
                    'Back here, click **Record transfer**.',
                    'Enter the **Amount sent**, the **Transfer UTR** from your bank, **When it was sent**, and attach the **Proof** (screenshot or PDF).',
                    'Click **Record transfer**. When the transfers add up to the full amount the payout becomes **Success** and the partner is told.',
                ],
            },
            shot('20-record-transfer', 'Record the transfer.', true),
            {
                kind: 'points',
                title: 'Good to know',
                items: [
                    '**Paying in parts**: lower the amount and record each transfer; the button then says **Record next transfer**.',
                    'Record a transfer **only after** the money left your bank.',
                    'The partner sees your proof, so crop out anything that isn’t the transfer.',
                    'A UTR already used can’t be used again; a future date is refused.',
                    'A payout only partly paid for over a day sends you a reminder alert.',
                ],
            },
        ],
    },
    {
        id: 'cant-pay',
        group: 'Withdrawals',
        title: 'Can’t pay a payout',
        icon: ArrowUpFromLine,
        who: 'everyone',
        summary:
            'Wrong details, bank down, over your limit? Mark it failed so the partner can try again.',
        blocks: [
            shot('21-cant-pay', 'Can’t pay: pick a reason.', true),
            {
                kind: 'steps',
                items: [
                    'On the payout card click **Can’t pay**.',
                    'Pick a **Reason** (invalid beneficiary, bank failure, limit exceeded…) and add details.',
                    'Click **Mark failed**. The partner’s money is released and they are told.',
                ],
            },
            {
                kind: 'note',
                tone: 'warn',
                text: 'Once part of a payout is paid, **Can’t pay** is gone: pay the rest instead.',
            },
        ],
    },
    {
        id: 'payout-history',
        group: 'Withdrawals',
        title: 'All withdrawals',
        icon: ArrowUpFromLine,
        who: 'everyone',
        summary: 'Every payout your branch was given, with its status.',
        blocks: [
            shot(
                '22-payout-history',
                'Payments › Withdrawal › All withdrawals.',
            ),
            {
                kind: 'table',
                head: ['Status', 'Meaning'],
                rows: [
                    ['Assigned', 'Given to you. Waiting for you to Start.'],
                    ['Processing', 'You are paying it (maybe in parts).'],
                    ['Success / Paid', 'All money sent. Partner told.'],
                    ['Failed', 'You couldn’t pay. Partner may send it again.'],
                    [
                        'Cancelled',
                        'The partner cancelled it before you started.',
                    ],
                ],
            },
            {
                kind: 'points',
                items: [
                    'Search by **Payout ID** (starts with `PO`), Order ID, UTR or customer.',
                    'Click a payout to see its transfers and proofs.',
                ],
            },
        ],
    },

    // ─── Money back ──────────────────────────────────────────────────────
    {
        id: 'chargeback',
        group: 'Money back',
        title: 'Chargeback: the customer’s bank took a deposit back',
        icon: RotateCcw,
        who: 'everyone',
        summary:
            'If your bank reverses a deposit that was already approved, raise a chargeback with the bank’s notice.',
        blocks: [
            {
                kind: 'steps',
                items: [
                    'Open **Payments › Deposit › All deposits** and find the deposit (tab **Success**, or search the UTR).',
                    'In the **Chargeback** column click **Chargeback**.',
                    'Enter the **amount** taken back (usually the full deposit) and the **date** on the bank’s notice.',
                    'Attach the **bank’s notice** (image or PDF). Add a note if useful.',
                    'Click **Send for approval**. PayGate checks it and approves it. On that day the partner is debited and your branch owes that much less.',
                ],
            },
            shot('23-raise-chargeback', 'Raise a chargeback.', true),
            shot(
                '24-chargebacks',
                'Payments › Chargebacks: what you raised and what PayGate decided.',
            ),
        ],
    },
    {
        id: 'payout-return',
        group: 'Money back',
        title: 'Payout returns: a payout came back',
        icon: RotateCcw,
        who: 'everyone',
        summary:
            'A payout you paid bounced back to your bank, or the partner says the customer did not receive it.',
        blocks: [
            shot('25-payout-returns', 'Payments › Payout returns.'),
            {
                kind: 'steps',
                title: 'The partner says it wasn’t received',
                items: [
                    'You get a bell alert. Open **Payments › Payout returns** (tab **Waiting for branch**).',
                    'Check your bank for the returned money.',
                    'It came back → **Confirm**: choose which transfer came back, enter the amount and the date, attach the bank proof, then **Confirm and book**. Your branch owes that much more; the partner gets it back.',
                    'It did not come back (the customer was paid) → **Reject** with a note, and keep your proof of payment.',
                ],
            },
            {
                kind: 'steps',
                title: 'You see a payout bounce back yourself',
                items: [
                    'Open the paid payout and click **Record return**.',
                    'Enter the amount, date and proof, then send it.',
                ],
            },
        ],
    },

    // ─── Money & reports ─────────────────────────────────────────────────
    {
        id: 'settlement',
        group: 'Money & reports',
        title: 'Branch Settlement Dashboard: who owes whom',
        icon: Scale,
        who: 'admin',
        summary:
            'Branch admin only. Shows your deposits, withdrawals, commission and the balance between you and PayGate.',
        blocks: [
            shot('27-settlement', 'Finance › Branch Settlement Dashboard.'),
            {
                kind: 'points',
                items: [
                    '**Deposit** and **Withdraw**: the totals in the period and your commission rate on each.',
                    '**Commission (yours)**: what you earned.',
                    '**Settlement**: payments already made between you and PayGate in the period.',
                    '**Chargeback** and **Payout return**: money that went back.',
                    '**Net Balance**: “You owe PayGate” or “PayGate owes you”. **−** = you owe, **+** = you are owed.',
                    'Settlements are calculated every day at the cut-off time; you get a bell alert with the amount. Payment is done outside the portal and then recorded by PayGate.',
                ],
            },
        ],
    },

    // ─── Team ────────────────────────────────────────────────────────────
    {
        id: 'users',
        group: 'Team',
        title: 'Users: add your operators',
        icon: Users,
        who: 'admin',
        summary:
            'Branch admin only. Give each person their own login, never share one.',
        blocks: [
            shot('28-users', 'System › Users.'),
            {
                kind: 'steps',
                title: 'Add a user',
                items: [
                    'Open **System › Users** and click **Add user**.',
                    '**Username**: 3–50 characters, lowercase letters, numbers, dot, underscore or hyphen (e.g. `ravi.k`).',
                    '**Role**: **Operator** for daily work, **Branch admin** for full access.',
                    'Type a **Password** (at least 6 characters) twice.',
                    'Click **Add user**. Give them the username and password in person or by phone. They can log in at once and should change the password.',
                ],
            },
            shot('29-add-user', 'Add user.', true),
            {
                kind: 'points',
                title: 'Manage a user (click View details)',
                items: [
                    '**Set password**: when someone forgot theirs. Their old password stops working.',
                    '**Change role or username**.',
                    '**Deactivate** (with a reason) when someone leaves. They are logged out and can’t log in. **Activate** brings them back.',
                    '**Lost phone (2FA)?** Only **PayGate (Admin)** can reset 2FA. Ask them; the user sets it up again at the next login.',
                ],
            },
        ],
    },
    {
        id: 'audit',
        group: 'Team',
        title: 'Audit Logs: who did what',
        icon: UserCog,
        who: 'admin',
        summary:
            'Branch admin only. Every approval, decline, payout, account change and login of your users, with time.',
        blocks: [
            shot('30-audit', 'System › Audit Logs.'),
            {
                kind: 'points',
                items: [
                    'Search by action, person or request ID; pick a period.',
                    'Logs can’t be changed or deleted by anyone.',
                ],
            },
        ],
    },
    {
        id: 'profile',
        group: 'Team',
        title: 'Your profile and look',
        icon: UserCog,
        who: 'everyone',
        summary: 'Profile & settings in the menu.',
        blocks: [
            shot('32-profile', 'Profile & settings › Profile.'),
            {
                kind: 'points',
                items: [
                    '**Profile**: change your display name. Your username can only be changed by your Branch admin.',
                    '**Security**: password, 2FA, passkeys.',
                    '**Appearance**: Light, Dark or System (follows your computer).',
                    '**Notifications**: which alerts show in the bell.',
                ],
            },
        ],
    },

    // ─── Help ────────────────────────────────────────────────────────────
    {
        id: 'rules',
        group: 'Help',
        title: 'Golden rules',
        icon: ShieldCheck,
        who: 'everyone',
        summary: 'Follow these and you will never lose money.',
        blocks: [
            {
                kind: 'steps',
                items: [
                    'Approve a deposit **only** when you see the money in **your** bank with the **same UTR and amount**.',
                    'Type the bank UTR **from your bank**, never copied from the customer.',
                    'Record a payout transfer **only after** the money left your bank.',
                    'Never approve a different amount. **Decline** it.',
                    'Enter or import your bank lines **every day**.',
                    'Check the server badge: **Live = real money**.',
                    'Never share your login. Turn on 2FA. Log out on shared computers.',
                    'Keep **Manual Deposit**, **Manual Payout** and **Unsettled UTR** near zero before you leave for the day.',
                ],
            },
        ],
    },
    {
        id: 'faq',
        group: 'Help',
        title: 'Quick answers',
        icon: HelpCircle,
        who: 'everyone',
        summary: 'Common questions from branch staff.',
        blocks: [
            {
                kind: 'table',
                head: ['Question', 'Answer'],
                rows: [
                    [
                        'I forgot my password.',
                        'Ask your Branch admin to **Set password** for you. There is no email reset.',
                    ],
                    [
                        'I lost my phone (2FA).',
                        'Use a recovery code, or ask **PayGate (Admin)** to **Reset 2FA**. A Branch admin can’t reset it.',
                    ],
                    [
                        'I approved by mistake.',
                        'An approval can’t be undone. Tell PayGate at once with the Transaction ID.',
                    ],
                    [
                        '“UTR already used”.',
                        'That UTR is on another deposit or payout. Check for a double entry or fraud; tell PayGate.',
                    ],
                    [
                        'The customer paid a different amount.',
                        '**Decline** with reason “wrong amount”. The partner sorts it out with the customer.',
                    ],
                    [
                        'The UTR isn’t in my account.',
                        'Click **Not in our bank**. PayGate moves it to the right branch.',
                    ],
                    [
                        'My account gets no customers.',
                        'Check it is **Verified** and **Active**, and that it hasn’t reached a daily limit.',
                    ],
                    [
                        'A deposit disappeared from my list.',
                        'Change the period (e.g. 7 Days) or click **Show them** on the yellow line; search by UTR in All deposits.',
                    ],
                    [
                        'I typed a wrong bank line.',
                        'You can’t edit it. Ask PayGate to correct it.',
                    ],
                    [
                        'I can’t pay a payout.',
                        '**Can’t pay** with a reason (only before any part is paid).',
                    ],
                    [
                        'I don’t hear the sound.',
                        'Click the speaker in the top bar, and keep the portal tab open.',
                    ],
                ],
            },
        ],
    },
];
