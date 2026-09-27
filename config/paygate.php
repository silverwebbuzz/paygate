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

];
