<?php

return [

    // Which server this is for partners: "live" (real money), "sandbox"
    // (staging, for partners to integrate) or "testbox" (our own testing).
    // Sent in every API answer and webhook; keys are pk_live_ (live),
    // pk_sb_ (sandbox) or pk_test_ (testbox). Unset: a production install is live, any other
    // testbox, so set it on every server. See App\Support\ApiEnvironment.
    'environment' => env('PAYGATE_ENVIRONMENT'),

    // Hosts of our other servers, comma separated (e.g. on live
    // "a27pay.tech"): partner URLs on them are flagged by the
    // go-live check (test partner sites).
    'other_hosts' => env('PAYGATE_OTHER_HOSTS', ''),

    'auth' => [
        // Require Admin and Branch users to set up 2FA before using their portal.
        // Keep this on everywhere except, optionally, your own local machine.
        'enforce_two_factor' => (bool) env('PAYGATE_ENFORCE_2FA', true),
    ],

    // HMAC key for the *_hash "blind index" columns (bank account numbers,
    // UPI IDs): lets the database enforce uniqueness without storing the
    // numbers in clear. Like APP_KEY: set once per environment, never change.
    'hash_key' => env('PAYGATE_HASH_KEY'),

    'api' => [
        // Refuse Partner API calls from addresses not on the partner's allowed
        // list; a partner without allowed IPs can't call the API (decided
        // 2026-09-27). Off only on your own machine.
        'enforce_ip_allowlist' => (bool) env('PAYGATE_API_ENFORCE_IP', true),

        // Requests per minute per partner.
        'rate_limit' => (int) env('PAYGATE_API_RATE_LIMIT', 300),

        // Refuse a key of another server (e.g. a pk_sb_ key on live) with
        // error `wrong_environment`, so a partner URL left on the wrong
        // server fails at once. Check first
        // with `php artisan paygate:key-environments`.
        'enforce_key_environment' => (bool) env('PAYGATE_API_ENFORCE_KEY_ENVIRONMENT', true),

        // Optional: each server's API base URL, named in that error (e.g.
        // "send sandbox-key requests to https://…/api/v1").
        'urls' => [
            'live' => env('PAYGATE_LIVE_API_URL'),
            'sandbox' => env('PAYGATE_SANDBOX_API_URL'),
            'testbox' => env('PAYGATE_TESTBOX_API_URL'),
        ],
    ],

    'webhooks' => [
        // Allow webhook URLs on private networks / plain http. Only for your
        // own machine; on servers private targets are refused (SSRF).
        'allow_private_targets' => (bool) env('PAYGATE_WEBHOOKS_ALLOW_PRIVATE', false),
    ],

    'payin' => [
        // Photo proof uploads (KB) and accepted types.
        'proof_max_kb' => 5120,
        'proof_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
        // Double-click guard (decided 2026-10-21): a second pay-in for the same
        // customer and amount while the first is still unpaid and younger than
        // this is refused (409 duplicate_request) with the first one's link.
        'duplicate_window_seconds' => (int) env('PAYGATE_PAYIN_DUPLICATE_WINDOW', 120),
    ],

    'security_scan' => [
        // Admin › Testing › Security Report: the light load per page of each
        // scan (requests, how many at a time). Keep it light on production.
        'load_requests' => (int) env('PAYGATE_SCAN_LOAD_REQUESTS', 100),
        'load_concurrency' => (int) env('PAYGATE_SCAN_LOAD_CONCURRENCY', 10),
    ],

    'qa' => [
        // Admin › QA Checklist (super admins only, every environment): every
        // feature with how to test it, manual results, test data and a button
        // that re-runs its automated tests. The re-runs need dev dependencies
        // and never run on production (TestRunner::available).
        'enabled' => (bool) env('PAYGATE_QA_CHECKLIST', true),
    ],

];
