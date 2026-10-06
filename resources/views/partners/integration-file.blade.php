<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 13mm 12mm 15mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #0f172a; line-height: 1.35; margin: 0; }
        .footer { position: fixed; bottom: -9mm; left: 0; right: 0; border-top: 1px solid #e4e7ec; padding-top: 3px; font-size: 8pt; color: #64748b; }
        .pagenum:before { content: counter(page); }
        .banner { width: 100%; border-collapse: collapse; margin: 0 0 8px; }
        .banner td { background: #3e1264; color: #ffffff; padding: 12px 14px 10px; }
        .eyebrow { font-size: 8pt; color: #f9a8d4; font-weight: bold; }
        h1 { font-size: 16pt; line-height: 1.15; margin: 2px 0; font-weight: normal; color: #ffffff; }
        .banner p { margin: 0; font-size: 8pt; color: #fce7f3; }
        h2 { font-size: 12pt; color: #3e1264; margin: 12px 0 4px; padding-bottom: 2px; border-bottom: 2px solid #d6287f; page-break-after: avoid; }
        h3 { font-size: 10pt; color: #3e1264; margin: 8px 0 3px; page-break-after: avoid; }
        p { margin: 0 0 5px; }
        .small { font-size: 8pt; color: #475569; }
        .break { page-break-before: always; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; text-align: left; }
        .facts td { border: 1px solid #e4e7ec; padding: 4px 7px; }
        .facts td.k { width: 22%; background: #f8fafc; color: #475569; font-size: 8pt; font-weight: bold; }
        .map td { border-bottom: 1px solid #eef0f3; padding: 3px 6px 3px 0; font-size: 8pt; }
        .map td.c { width: 46%; font-family: DejaVu Sans Mono, monospace; color: #3e1264; }
        .box { padding: 7px 9px; margin: 6px 0; page-break-inside: avoid; }
        .secret { background: #fffbeb; border: 1px solid #f59e0b; }
        .secret .label { color: #b45309; font-size: 8pt; font-weight: bold; }
        .warn { background: #fef2f2; border: 1px solid #fecaca; }
        .note { background: #eff6ff; border: 1px solid #bfdbfe; }
        .good { background: #ecfdf5; border: 1px solid #a7f3d0; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 8pt; }
        .keep { page-break-inside: avoid; }
        .code { background: #f8fafc; border: 1px solid #e4e7ec; padding: 6px 8px; font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; line-height: 1.4; white-space: pre-wrap; page-break-inside: avoid; }
        .url { font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; word-wrap: break-word; }
        .lbl { font-size: 8pt; font-weight: bold; color: #3e1264; margin: 5px 0 2px; page-break-after: avoid; }
    </style>
</head>
<body>
    <div class="footer">PayGate integration guide · {{ $partnerCode }} · page <span class="pagenum"></span></div>

    <table class="banner">
        <tr>
            <td bgcolor="#3e1264">
                <div class="eyebrow">PAYGATE</div>
                <h1>Partner integration guide</h1>
                <p>{{ $partnerName }} · {{ $partnerCode }} · API v1 · {{ $generatedAt }}</p>
            </td>
        </tr>
    </table>

    <p>Give this PDF to the developer. Each call below is listed as URL, method, header, request, and response. Keep the secret on your server.</p>

    <div class="box note">
        <strong>Amount.</strong> Cent means paisa. 1 INR = 100 cent (paisa). A deposit or payout of 100 INR is sent as amount 10000. 100 INR =&gt; 10000 cent. The same unit is used on status, webhooks, and balance.
    </div>

    <h2>Credentials</h2>
    <table class="facts">
        <tr><td class="k">Base URL</td><td class="url">{{ $base }}</td></tr>
        <tr><td class="k">Key ID</td><td class="mono">{{ $keyId ?? 'No key yet' }}</td></tr>
        <tr><td class="k">Allowed IPs</td><td>{{ $ips ?? 'None' }}</td></tr>
        <tr><td class="k">Rate limit</td><td>{{ $rateLimit }} requests per minute</td></tr>
        <tr><td class="k">Secret rotation</td><td>The previous key keeps working for {{ $overlapHours }} hours. After that, sign with the new secret, including webhooks.</td></tr>
    </table>

    @if ($secret)
        <div class="box secret">
            <div class="label">Secret — shown only in this download</div>
            <div class="mono">{{ $secret }}</div>
            <p class="small" style="margin: 4px 0 0;">Store it now. A later download from the partner list does not include the secret.</p>
        </div>
    @elseif ($last4)
        <div class="box warn">
            <strong>Secret not included.</strong>
            The secret is shown only once, when the key is generated. It ends with {{ $last4 }}.
        </div>
    @else
        <div class="box warn">
            <strong>No key yet.</strong>
            Generate one, then download from that screen. That copy is the only file that contains the secret.
        </div>
    @endif

    @if ($ips === null)
        <div class="box warn"><strong>No allowed IPs.</strong> An empty list blocks every API call. Add your server addresses before you go live.</div>
    @else
        <p class="small">Calls are accepted only from the allowed addresses above.</p>
    @endif

    <h2>Your URLs</h2>
    <table class="facts">
        <tr>
            <td class="k">Return URL</td>
            <td><div class="url">{{ $returnUrl }}</div><div class="small">The customer’s browser opens this after paying. Do not credit them for this visit.</div></td>
        </tr>
        <tr>
            <td class="k">Pay-in callback URL</td>
            <td><div class="url">{{ $callbackUrl }}</div><div class="small">Saved on the partner record. PayGate does not POST pay-in events here.</div></td>
        </tr>
        <tr>
            <td class="k">Pay-in webhook URL</td>
            <td><div class="url">{{ $payinWebhookUrl }}</div><div class="small">PayGate POSTs pay-in updates here.</div></td>
        </tr>
        <tr>
            <td class="k">Payout webhook URL</td>
            <td><div class="url">{{ $payoutWebhookUrl }}</div><div class="small">PayGate POSTs payout updates here.</div></td>
        </tr>
    </table>
    <p class="small">Pay-in limits: {{ $depositMin }} to {{ $depositMax }}. The payment page stays open for {{ $sessionMinutes }} minutes.</p>

    <div class="box good">
        <strong>Credit the customer only when pay-in status is success.</strong>
        Confirm with the webhook or with a status call. The return URL is only a hint.
    </div>

    <h2>Header on every call you send</h2>
    <p>Sign POST, GET, and the status calls. Content-Type is application/json when you send a body.</p>
    <table class="facts">
        <tr><td class="k">X-Key-Id</td><td>The key id.</td></tr>
        <tr><td class="k">X-Timestamp</td><td>Unix time in seconds, within {{ $clockMinutes }} minutes of PayGate.</td></tr>
        <tr><td class="k">X-Nonce</td><td>A new random value every request. 16–64 characters: letters, digits, hyphen, or underscore.</td></tr>
        <tr><td class="k">X-Signature</td><td>Lower-case hex HMAC-SHA256 of the five lines below, using the secret as the key.</td></tr>
    </table>
    <div class="lbl">String to sign</div>
    <div class="code">timestamp
nonce
METHOD
/v1/payins
sha256 hex of the exact body</div>
    <p class="small">Five lines, joined with one newline, and no extra blank line at the end. METHOD is POST or GET. The path starts at /v1 and includes the query string when there is one, for example /v1/payins?order_id=ORD-1. Leave the host out. If the base URL has a prefix such as /api before /v1, leave that prefix out of the signed path. Hash the exact bytes you send. An empty body is the sha256 of an empty string.</p>

    <h2>1. Pay-in request</h2>
    <table class="facts">
        <tr><td class="k">URL</td><td class="url">{{ $base }}/payins</td></tr>
        <tr><td class="k">Method</td><td>POST</td></tr>
        <tr><td class="k">Header</td><td>X-Key-Id, X-Timestamp, X-Nonce, X-Signature</td></tr>
    </table>
    <div class="lbl">Request</div>
    <div class="code">{
  "order_id": "00000000-0000-0000-0000-000000000001",
  "amount": 10000,
  "customer": {
    "id": "username",
    "name": "Ravi Kumar",
    "email": "ravi@example.com",
    "mobile": "9876543210"
  },
  "return_url": "{{ $sampleReturn }}",
  "metadata": { "udf1": "", "udf2": "" }
}</div>
    <p class="small">order_id is yours. A GUID is allowed. amount is cent (paisa): 10000 means 100 INR. customer.id is required. return_url is optional and must be on your website or return URL domain. metadata is optional extra data, returned with the pay-in. Sending the same order_id again with the same amount, customer id, and return_url returns the original pay-in.</p>
    <div class="lbl">Response success — HTTP 201</div>
    <div class="code">{
  "data": {
    "id": "PI260406AB23CD45",
    "order_id": "00000000-0000-0000-0000-000000000001",
    "status": "created",
    "amount": 10000,
    "currency": "INR",
    "payment_url": "https://pay.example/p/...",
    "expires_at": "2026-10-06T16:00:00+05:30"
  }
}</div>
    <p class="small">Send the customer to payment_url. The same order sent again returns HTTP 200 with the original pay-in. id is PayGate’s reference. It is not a GUID. Use order_id when you want your own transaction id.</p>
    <div class="lbl">Response failed</div>
    <div class="code">{
  "error": {
    "code": "validation_failed",
    "message": "Some fields are missing or invalid.",
    "request_id": "...",
    "fields": {}
  }
}</div>
    <p class="small">Use error.code, not the message. Every response also has an X-Request-Id header. A duplicate order_id with different details is HTTP 409, code duplicate_order_id.</p>

    <h2>2. Pay-in webhook</h2>
    <table class="facts">
        <tr><td class="k">URL</td><td class="url">{{ $payinWebhookUrl }}</td></tr>
        <tr><td class="k">Method</td><td>POST</td></tr>
        <tr><td class="k">Header</td><td>X-PayGate-Event-Id, X-PayGate-Event, X-PayGate-Signature</td></tr>
    </table>
    <p class="small">Events: payin.submitted, payin.success, payin.rejected, payin.expired, payin.chargeback, payin.refunded. The signature is t=&lt;unix&gt;,v1=&lt;hex&gt;. HMAC-SHA256 of &lt;t&gt;.&lt;raw body&gt; with your secret must equal v1, and t must be within 5 minutes. Ignore an event id you have already handled.</p>
    <div class="lbl">Request we send</div>
    <div class="code">{
  "id": "...",
  "type": "payin.success",
  "created_at": "...",
  "data": {
    "id": "PI260406AB23CD45",
    "order_id": "00000000-0000-0000-0000-000000000001",
    "status": "success",
    "amount": 10000,
    "utr": "123456789012"
  }
}</div>
    <div class="lbl">Response success</div>
    <p>Any HTTP 2xx within 10 seconds. The body is ignored.</p>
    <div class="lbl">Response failed</div>
    <p>Anything else. PayGate retries after 1 minute, 5 minutes, 15 minutes, 1 hour, 6 hours, and 24 hours.</p>

    <h2>3. Pay-in status</h2>
    <table class="facts">
        <tr><td class="k">URL</td><td class="url">{{ $base }}/payins/{id}<br>{{ $base }}/payins?order_id=YOUR-ID</td></tr>
        <tr><td class="k">Method</td><td>GET</td></tr>
        <tr><td class="k">Header</td><td>X-Key-Id, X-Timestamp, X-Nonce, X-Signature</td></tr>
    </table>
    <div class="lbl">Response success — HTTP 200</div>
    <div class="code">{
  "data": {
    "id": "PI260406AB23CD45",
    "order_id": "00000000-0000-0000-0000-000000000001",
    "status": "success",
    "amount": 10000,
    "utr": "123456789012",
    "late": false
  }
}</div>
    <p class="small">status is a word: created, awaiting_payment, payment_submitted, under_review, success, rejected, expired, cancelled, chargeback, refunded. Credit only on success. A late payment can become success after expired or rejected, with late true. Credit that once.</p>
    <div class="lbl">Response failed — HTTP 404</div>
    <div class="code">{ "error": { "code": "not_found", "message": "No pay-in with this id for your account.", "request_id": "..." } }</div>
    <p>POST {{ $base }}/payins/{id}/cancel before the customer pays. Header is the same four signing headers.</p>

    <h2>4. Many pay-in statuses</h2>
    <table class="facts">
        <tr><td class="k">URL</td><td class="url">{{ $base }}/payins/status</td></tr>
        <tr><td class="k">Method</td><td>POST</td></tr>
        <tr><td class="k">Header</td><td>X-Key-Id, X-Timestamp, X-Nonce, X-Signature</td></tr>
    </table>
    <div class="lbl">Request</div>
    <div class="code">{
  "ids": ["PI260406AB23CD45"],
  "order_ids": ["00000000-0000-0000-0000-000000000001"]
}</div>
    <div class="lbl">Response success — HTTP 200</div>
    <div class="code">{
  "data": [ { "id": "PI260406AB23CD45", "order_id": "...", "status": "success", "amount": 10000 } ],
  "not_found": []
}</div>
    <p class="small">Up to 100 ids and 100 order ids. Unknown ones are listed in not_found. Response failed uses the same error object as the other calls.</p>

    <h2>5. Payout request</h2>
    <table class="facts">
        <tr><td class="k">URL</td><td class="url">{{ $base }}/payouts</td></tr>
        <tr><td class="k">Method</td><td>POST</td></tr>
        <tr><td class="k">Header</td><td>X-Key-Id, X-Timestamp, X-Nonce, X-Signature</td></tr>
    </table>
    <div class="lbl">Request</div>
    <div class="code">{
  "order_id": "67d55db6-4c9c-4112-ba4c-5b52a2719441",
  "amount": 10000,
  "customer": { "id": "user-1" },
  "beneficiary": {
    "type": "bank",
    "name": "Ravi Kumar",
    "account_number": "10127000000000",
    "ifsc": "IDFB0000001",
    "bank_name": "State Bank Of India",
    "email": "ravi@example.com",
    "phone": "9916580000"
  },
  "metadata": { "udf1": "" }
}</div>
    <p class="small">amount is cent (paisa). For UPI set type to upi and send upi_id instead of the bank fields. The fee is charged on top of amount and comes out of your balance.</p>
    <div class="lbl">Response success — HTTP 201</div>
    <div class="code">{
  "data": {
    "id": "PO260406AB23CD45",
    "order_id": "67d55db6-4c9c-4112-ba4c-5b52a2719441",
    "status": "assigned",
    "amount": 10000,
    "fee": 200,
    "currency": "INR",
    "utr": null
  }
}</div>
    <p class="small">fee in this example is 200 paisa, charged on top of amount. The live fee follows this partner’s rate.</p>
    <div class="lbl">Response failed</div>
    <p class="small">Same error object. insufficient_balance means the amount plus the fee does not fit. duplicate_order_id is HTTP 409 when this order_id already exists with different details.</p>
    <p class="small">Payout status words: assigned, processing, success (utr is set), failed (the held amount is back in your balance), cancelled, returned. POST {{ $base }}/payouts/{id}/cancel while it is still assigned.</p>

    <h2>6. Payout webhook</h2>
    <table class="facts">
        <tr><td class="k">URL</td><td class="url">{{ $payoutWebhookUrl }}</td></tr>
        <tr><td class="k">Method</td><td>POST</td></tr>
        <tr><td class="k">Header</td><td>X-PayGate-Event-Id, X-PayGate-Event, X-PayGate-Signature</td></tr>
    </table>
    <p class="small">Events: payout.success, payout.failed, payout.returned. Check the signature the same way as a pay-in webhook. Reply with any HTTP 2xx within 10 seconds.</p>
    <div class="lbl">Request we send</div>
    <div class="code">{
  "id": "...",
  "type": "payout.success",
  "created_at": "...",
  "data": {
    "id": "PO260406AB23CD45",
    "order_id": "67d55db6-4c9c-4112-ba4c-5b52a2719441",
    "status": "success",
    "amount": 10000,
    "utr": "987987465"
  }
}</div>

    <h2>7. Payout status</h2>
    <table class="facts">
        <tr><td class="k">URL</td><td class="url">{{ $base }}/payouts/{id}<br>{{ $base }}/payouts?order_id=YOUR-ID</td></tr>
        <tr><td class="k">Method</td><td>GET</td></tr>
        <tr><td class="k">Header</td><td>X-Key-Id, X-Timestamp, X-Nonce, X-Signature</td></tr>
    </table>
    <div class="lbl">Response success — HTTP 200</div>
    <p class="small">The same data object as the payout request response, including status, amount, fee, and utr.</p>
    <div class="lbl">Response failed — HTTP 404</div>
    <p class="small">error.code is not_found when that id is not on this account.</p>

    <h2>8. Many payout statuses</h2>
    <table class="facts">
        <tr><td class="k">URL</td><td class="url">{{ $base }}/payouts/status</td></tr>
        <tr><td class="k">Method</td><td>POST</td></tr>
        <tr><td class="k">Header</td><td>X-Key-Id, X-Timestamp, X-Nonce, X-Signature</td></tr>
    </table>
    <div class="lbl">Request</div>
    <div class="code">{ "ids": ["PO260406AB23CD45"], "order_ids": ["67d55db6-4c9c-4112-ba4c-5b52a2719441"] }</div>
    <div class="lbl">Response success — HTTP 200</div>
    <p class="small">data is the list of payouts. not_found lists the ids PayGate does not have. Up to 100 of each.</p>

    <h2>9. Balance</h2>
    <table class="facts">
        <tr><td class="k">URL</td><td class="url">{{ $base }}/balance</td></tr>
        <tr><td class="k">Method</td><td>GET</td></tr>
        <tr><td class="k">Header</td><td>X-Key-Id, X-Timestamp, X-Nonce, X-Signature</td></tr>
    </table>
    <div class="keep">
    <div class="lbl">Response success — HTTP 200</div>
    <div class="code">{
  "data": {
    "balance": 11275000,
    "reserved": 0,
    "available": 11275000,
    "max_payout": 11275000,
    "currency": "INR"
  }
}</div>
    </div>
    <p class="small">These numbers are cent (paisa), same as amount. 11275000 means Rs 1,12,750.00. reserved is held by open payouts. max_payout is the largest single payout that fits right now.</p>

    <div>
        <h2>Notes</h2>
        <div class="box note">
            <strong>Transaction id.</strong> Your order_id may be a GUID, for example 00000000-0000-0000-0000-000000000001. PayGate’s own id is separate and is not a GUID. A pay-in id looks like PI260406AB23CD45. A payout id looks like PO260406AB23CD45.
        </div>
        <div class="box note">
            <strong>Cent means paisa.</strong> 1 INR = 100 cent (paisa). If the user deposits or withdraws 100 INR, the amount in the request is 100 x 100. 100 INR =&gt; 10000 cent.
        </div>
        <p>Success is HTTP 2xx and a data object. A failure is HTTP 4xx or 5xx and error.code. Program against error.code.</p>
        <table class="map">
            <tr><td class="c">unauthenticated</td><td>Missing headers, unknown key, or a bad nonce.</td></tr>
            <tr><td class="c">invalid_signature</td><td>The signature string does not match.</td></tr>
            <tr><td class="c">timestamp_out_of_range</td><td>Clock skew is more than {{ $clockMinutes }} minutes.</td></tr>
            <tr><td class="c">nonce_reused</td><td>This nonce was already used.</td></tr>
            <tr><td class="c">ip_not_allowed</td><td>The caller is not on the allowed IP list.</td></tr>
            <tr><td class="c">rate_limited</td><td>More than {{ $rateLimit }} requests in a minute.</td></tr>
            <tr><td class="c">validation_failed</td><td>A field is missing or invalid. See fields.</td></tr>
            <tr><td class="c">duplicate_order_id</td><td>This order_id already exists with different details.</td></tr>
            <tr><td class="c">amount_out_of_range</td><td>Outside this partner’s minimum or maximum.</td></tr>
            <tr><td class="c">return_url_not_allowed</td><td>return_url is not on a registered domain.</td></tr>
            <tr><td class="c">insufficient_balance</td><td>Not enough balance for the payout plus the fee.</td></tr>
            <tr><td class="c">not_found</td><td>No pay-in or payout with that id on this account.</td></tr>
            <tr><td class="c">not_cancellable</td><td>It can no longer be cancelled.</td></tr>
        </table>
    </div>
</body>
</html>
