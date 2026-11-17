<?php

/*
| Cross-origin (CORS) access: none. The Partner API is called from partners'
| servers with a signing secret that must never be in a browser, and the
| portals and payment page are same-origin. Laravel's default (any site on
| api/*) was flagged by the security scan (Document/Security-Testing.md).
*/

return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
