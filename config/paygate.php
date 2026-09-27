<?php

return [

    'auth' => [
        // Require Admin and Branch users to set up 2FA before using their portal.
        // Keep this on everywhere except, optionally, your own local machine.
        'enforce_two_factor' => (bool) env('PAYGATE_ENFORCE_2FA', true),
    ],

];
