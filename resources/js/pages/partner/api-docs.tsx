import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Panel } from '@/components/pg/data-table';
import { PageHeader } from '@/components/pg/page-header';
import { formatLimit } from '@/lib/money';

type Props = {
    base_url: string;
    key_id: string | null;
    clock_skew: number;
    rate_limit: number;
    overlap_hours: number;
    session_ttl: number;
    limits: { min: number | null; max: number | null };
};

const STATUSES: [string, string][] = [
    ['created', 'Pay-in created. Send the customer to payment_url.'],
    [
        'awaiting_payment',
        'The customer chose a method and sees the account to pay.',
    ],
    [
        'payment_submitted',
        'The customer says they paid (UTR or screenshot). The receiving branch is checking.',
    ],
    ['under_review', 'Being checked manually.'],
    ['success', 'Money received and confirmed. Credit your customer now.'],
    ['rejected', 'The payment could not be confirmed.'],
    ['expired', 'Not paid in time. The payment link no longer works.'],
    ['cancelled', 'You cancelled it before payment.'],
];

const ERRORS: [string, number, string][] = [
    [
        'unauthenticated',
        401,
        'Missing signature headers, or unknown / revoked key.',
    ],
    ['invalid_signature', 401, 'The signature doesn’t match the request.'],
    ['timestamp_out_of_range', 401, 'X-Timestamp is too far from our clock.'],
    ['nonce_reused', 401, 'The X-Nonce was already used.'],
    [
        'ip_not_allowed',
        403,
        'The request came from an address not on your allowed list.',
    ],
    ['partner_inactive', 403, 'Your account is not active.'],
    ['payin_disabled', 403, 'Pay-ins are not enabled for your account.'],
    ['payout_disabled', 403, 'Payouts are not enabled for your account.'],
    [
        'insufficient_balance',
        422,
        'Balance is low: no branch holds enough of your balance for amount + fee.',
    ],
    [
        'daily_limit_reached',
        422,
        'The payout would exceed your daily withdrawal limit.',
    ],
    ['customer_blocked', 403, 'This customer is blocked.'],
    [
        'validation_failed',
        422,
        'Fields are missing or invalid; see error.fields.',
    ],
    [
        'amount_out_of_range',
        422,
        'Outside your minimum / maximum; see error.min_amount / max_amount.',
    ],
    [
        'return_url_not_allowed',
        422,
        'return_url is not on your registered domain.',
    ],
    [
        'duplicate_order_id',
        409,
        'This order_id exists with different details; see error.id.',
    ],
    ['not_cancellable', 409, 'The pay-in has already moved on.'],
    ['not_found', 404, 'No such pay-in for your account.'],
    ['rate_limited', 429, 'Too many requests; wait and retry.'],
    [
        'server_error',
        500,
        'Our side failed. Retry later; quote the request_id if it persists.',
    ],
];

/**
 * Partner API documentation (v1), written for the partner's developer.
 */
export default function ApiDocs(props: Props) {
    const { base_url, key_id } = props;

    return (
        <>
            <Head title="API documentation" />
            <PageHeader
                title="API documentation"
                description="Create pay-ins from your server, send your customer to the payment page, and check the result. Version v1."
            />

            <Doc title="1. How it works">
                <ol className="list-decimal space-y-1.5 pl-5">
                    <li>
                        Your server creates a pay-in: <Code>POST /payins</Code>{' '}
                        with your order id and the amount. You get a{' '}
                        <Code>payment_url</Code>.
                    </li>
                    <li>
                        Redirect the customer to it. They choose UPI, bank
                        transfer or QR, pay, and enter the UTR (or upload a
                        screenshot).
                    </li>
                    <li>
                        The receiving branch confirms the money arrived; the
                        status becomes <Code>success</Code>.
                    </li>
                    <li>
                        We send a signed webhook when the status changes, and
                        you can always check with{' '}
                        <Code>GET /payins/{'{id}'}</Code>. Only credit your
                        customer on <Code>success</Code> — never because the
                        customer came back to your return URL.
                    </li>
                </ol>
            </Doc>

            <Doc title="2. Base URL and credentials">
                <Table
                    rows={[
                        ['Base URL', <Code key="b">{base_url}</Code>],
                        [
                            'Your key id',
                            key_id ? (
                                <Code key="k">{key_id}</Code>
                            ) : (
                                'Generate one under API & Webhooks'
                            ),
                        ],
                        [
                            'Secret',
                            'Shown once when generated. Keep it on your server only — never in a browser or app.',
                        ],
                        [
                            'Allowed IPs',
                            'Calls are only accepted from the addresses listed under API & Webhooks › Allowed IPs. An empty list blocks all calls.',
                        ],
                        [
                            'Rate limit',
                            `${props.rate_limit} requests per minute.`,
                        ],
                        [
                            'Rotation',
                            `Rotating the secret keeps the old one valid for ${props.overlap_hours} hours.`,
                        ],
                    ]}
                />
            </Doc>

            <Doc title="3. Signing every request">
                <p>
                    Send four headers with every call (JSON bodies with
                    Content-Type: application/json):
                </p>
                <Table
                    rows={[
                        [<Code key="1">X-Key-Id</Code>, 'Your key id.'],
                        [
                            <Code key="2">X-Timestamp</Code>,
                            `Current unix time in seconds (must be within ±${props.clock_skew / 60} minutes of ours).`,
                        ],
                        [
                            <Code key="3">X-Nonce</Code>,
                            'A new random string for every request, 16–64 characters (letters, digits, - or _).',
                        ],
                        [
                            <Code key="4">X-Signature</Code>,
                            'Lower-case hex HMAC-SHA256 of the string below, keyed with your secret.',
                        ],
                    ]}
                />
                <p>
                    The string to sign is five lines joined with a newline (\n):
                </p>
                <Pre>{`timestamp
nonce
METHOD            (e.g. POST)
path with query   (e.g. /v1/payins or /v1/payins?order_id=ORD-1)
sha256 hex of the exact body bytes (empty body: sha256 of "")`}</Pre>
                <p>PHP example:</p>
                <Pre>{`$body = json_encode($payload);
$timestamp = (string) time();
$nonce = bin2hex(random_bytes(16));
$toSign = implode("\\n", [$timestamp, $nonce, 'POST', '/v1/payins', hash('sha256', $body)]);
$signature = hash_hmac('sha256', $toSign, $secret);
// headers: X-Key-Id, X-Timestamp: $timestamp, X-Nonce: $nonce, X-Signature: $signature`}</Pre>
                <p>Node.js example:</p>
                <Pre>{`const crypto = require('crypto');
const body = JSON.stringify(payload);
const timestamp = Math.floor(Date.now() / 1000).toString();
const nonce = crypto.randomBytes(16).toString('hex');
const toSign = [timestamp, nonce, 'POST', '/v1/payins',
  crypto.createHash('sha256').update(body).digest('hex')].join('\\n');
const signature = crypto.createHmac('sha256', secret).update(toSign).digest('hex');`}</Pre>
                <p>
                    Sign the exact bytes you send: serialize the JSON once and
                    send that same string.
                </p>
            </Doc>

            <Doc title="4. Create a pay-in">
                <p>
                    <Code>POST /v1/payins</Code>. <b>amount is in paise</b>{' '}
                    (₹12,534.00 = 1253400). Your limits:{' '}
                    {formatLimit(props.limits.min)} to{' '}
                    {formatLimit(props.limits.max)}. The payment page is valid
                    for {props.session_ttl} minutes.
                </p>
                <Pre>{`{
  "order_id": "ORD-918231457782",        // your id, unique per pay-in (max 100: letters, digits . _ : / -)
  "amount": 1253400,                      // paise
  "customer": {
    "id": "user-5521",                   // required: your customer id
    "name": "Ravi Kumar",                 // optional
    "mobile": "9876543210",              // optional
    "email": "ravi@example.com",         // optional
    "username": "ravi_k"                  // optional
  },
  "return_url": "https://shop.example.com/deposit/done",  // optional, must be on your domain
  "metadata": { "cart": "42" }            // optional, up to 20 string values, returned as-is
}`}</Pre>
                <p>Response 201:</p>
                <Pre>{`{
  "data": {
    "id": "PI260927K7QX4MZD",
    "order_id": "ORD-918231457782",
    "status": "created",
    "amount": 1253400,
    "currency": "INR",
    "method": null,
    "customer_id": "user-5521",
    "utr": null,
    "reason": null,                       // decline reason code when rejected
    "late": false,                        // true: approved after it had expired / been declined
    "payment_url": "https://pay.…/p/…",
    "expires_at": "2026-09-27T12:15:00+00:00",
    "submitted_at": null,
    "completed_at": null,
    "created_at": "2026-09-27T12:00:00+00:00",
    "metadata": { "cart": "42" }
  }
}`}</Pre>
                <p>
                    <b>Safe retries:</b> sending the same <Code>order_id</Code>{' '}
                    again with the same amount, customer id and return_url
                    returns the original pay-in (200) with the same link — so
                    retry freely after a timeout. With different details you get{' '}
                    <Code>409 duplicate_order_id</Code>.
                </p>
                <p>
                    The customer returns to <Code>return_url</Code> (or your
                    saved return URL) with <Code>?order_id=…&status=…</Code>.
                    Treat that only as a hint and confirm with the API.
                </p>
            </Doc>

            <Doc title="5. Check status">
                <Table
                    rows={[
                        [
                            <Code key="1">GET /v1/payins/{'{id}'}</Code>,
                            'By our id.',
                        ],
                        [
                            <Code key="2">GET /v1/payins?order_id=…</Code>,
                            'By your order id.',
                        ],
                        [
                            <Code key="3">POST /v1/payins/status</Code>,
                            <span key="3b">
                                Up to 100 at once:{' '}
                                <Code>
                                    {'{"ids": [...], "order_ids": [...]}'}
                                </Code>
                                . Unknown ones are listed in{' '}
                                <Code>not_found</Code>.
                            </span>,
                        ],
                        [
                            <Code key="4">
                                POST /v1/payins/{'{id}'}/cancel
                            </Code>,
                            'Cancel before the customer pays (409 not_cancellable after that).',
                        ],
                    ]}
                />
                <p>Statuses:</p>
                <Table
                    rows={STATUSES.map(([status, meaning]) => [
                        <Code key={status}>{status}</Code>,
                        meaning,
                    ])}
                />
            </Doc>

            <Doc title="6. Payouts (withdrawals) and balance">
                <p>
                    <Code>POST /v1/payouts</Code> asks us to pay your customer.
                    The <b>fee is charged on top</b>: the amount plus your
                    withdrawal fee comes out of your balance. A payout is paid
                    by one branch, so it must fit the balance held at one
                    branch; otherwise you get{' '}
                    <Code>422 insufficient_balance</Code> (“balance is low”) and
                    nothing is created.
                </p>
                <Pre>{`{
  "order_id": "WD-55120",
  "amount": 900000,                         // paise (₹9,000)
  "customer": { "id": "user-5521" },
  "beneficiary": {
    "type": "bank",                         // or "upi" with "upi_id": "name@bank"
    "name": "Ravi Kumar",
    "account_number": "50100482716640",
    "ifsc": "HDFC0001203",
    "bank_name": "HDFC Bank"                // optional
  },
  "metadata": { "wallet": "main" }          // optional
}`}</Pre>
                <p>
                    Statuses: <Code>assigned</Code> (a branch will pay it),{' '}
                    <Code>processing</Code> (being paid), <Code>success</Code>{' '}
                    (paid; the response carries the transfer UTR),{' '}
                    <Code>failed</Code> (couldn’t be paid; the held amount is
                    back in your balance — send a new request to retry),{' '}
                    <Code>cancelled</Code>. Cancel with{' '}
                    <Code>POST /v1/payouts/{'{id}'}/cancel</Code> while it is
                    still assigned. Look up with{' '}
                    <Code>GET /v1/payouts/{'{id}'}</Code>,{' '}
                    <Code>GET /v1/payouts?order_id=…</Code> or{' '}
                    <Code>POST /v1/payouts/status</Code>. Repeating the same
                    order_id is safe, as for pay-ins. Webhooks:{' '}
                    <Code>payout.success</Code>, <Code>payout.failed</Code> to
                    your payout webhook URL.
                </p>
                <p>
                    <Code>GET /v1/balance</Code> returns <Code>balance</Code>,{' '}
                    <Code>reserved</Code> (held by payouts in progress),{' '}
                    <Code>available</Code> and <Code>max_payout</Code> — the
                    largest single payout possible right now, fee included.
                </p>
            </Doc>

            <Doc title="7. Webhooks">
                <p>
                    We POST to your pay-in webhook URL (API & Webhooks ›
                    Endpoints) when a pay-in changes. Events:{' '}
                    <Code>payin.submitted</Code> (the customer says they paid),{' '}
                    <Code>payin.success</Code>, <Code>payin.rejected</Code>,{' '}
                    <Code>payin.expired</Code>. The body holds the same pay-in
                    object the API returns:
                </p>
                <Pre>{`POST https://your-site/…/webhook
X-PayGate-Event-Id: 01J…            (unique per webhook — ignore ones you already processed)
X-PayGate-Event: payin.success
X-PayGate-Signature: t=1790521203,v1=5f2c…

{ "id": "01J…", "type": "payin.success", "created_at": "…", "data": { "id": "PI260927K7QX4MZD", "status": "success", … } }`}</Pre>
                <p>
                    <b>Late payments:</b> if a customer&apos;s money reaches the
                    bank after the pay-in expired or was declined, we may
                    approve it afterwards. You then receive{' '}
                    <Code>payin.success</Code> for a pay-in you last saw as{' '}
                    <Code>expired</Code> or <Code>rejected</Code>, with{' '}
                    <Code>&quot;late&quot;: true</Code>. Credit the customer
                    once, as for any success.
                </p>
                <p>
                    Verify every webhook before trusting it: the HMAC-SHA256 of{' '}
                    <Code>{'<t>.<raw body>'}</Code> with your API secret must
                    equal v1.
                </p>
                <Pre>{`// PHP
parse_str(str_replace(',', '&', $_SERVER['HTTP_X_PAYGATE_SIGNATURE']), $sig);
$ok = hash_equals(hash_hmac('sha256', $sig['t'] . '.' . $rawBody, $secret), $sig['v1'])
      && abs(time() - (int) $sig['t']) < 300;`}</Pre>
                <p>
                    Answer with any 2xx within 10 seconds. Otherwise we retry
                    after 1 min, 5 min, 15 min, 1 h, 6 h and 24 h; you can also
                    resend from the Webhooks tab of the pay-in. After you rotate
                    your secret, webhooks are signed with the new one.
                </p>
            </Doc>

            <Doc title="8. Errors">
                <p>
                    Errors always look like this; use the code, not the message,
                    in your program:
                </p>
                <Pre>{`{ "error": { "code": "validation_failed", "message": "…", "request_id": "01J…", "fields": { "amount": ["…"] } } }`}</Pre>
                <Table
                    rows={ERRORS.map(([code, status, meaning]) => [
                        <Code key={code}>{code}</Code>,
                        String(status),
                        meaning,
                    ])}
                />
                <p>
                    Every response carries an X-Request-Id header; quote it when
                    you contact support. Your recent calls are listed under API
                    Logs.
                </p>
            </Doc>
        </>
    );
}

function Doc({ title, children }: { title: string; children: ReactNode }) {
    return (
        <Panel className="flex flex-col gap-3 p-5 text-[13px] leading-relaxed text-tx2">
            <h2 className="text-[15px] font-semibold text-tx">{title}</h2>
            {children}
        </Panel>
    );
}

function Code({ children }: { children: ReactNode }) {
    return (
        <code className="rounded bg-sf2 px-1 py-px font-mono text-[12px] text-tx">
            {children}
        </code>
    );
}

function Pre({ children }: { children: string }) {
    return (
        <pre className="overflow-x-auto rounded-lg bg-nav p-3.5 font-mono text-[12px] leading-relaxed text-[#E2E8F0]">
            {children}
        </pre>
    );
}

function Table({ rows }: { rows: ReactNode[][] }) {
    return (
        <div className="overflow-x-auto rounded-lg border border-ln">
            <table className="w-full text-[13px]">
                <tbody>
                    {rows.map((row, index) => (
                        <tr
                            key={index}
                            className="border-b border-ln2 last:border-b-0"
                        >
                            {row.map((cell, cellIndex) => (
                                <td
                                    key={cellIndex}
                                    className="px-3 py-2 align-top text-tx"
                                >
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
