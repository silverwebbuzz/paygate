<?php

return [

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
    ],

    'payin' => [
        // Photo proof uploads (KB) and accepted types.
        'proof_max_kb' => 5120,
        'proof_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
    ],

];
