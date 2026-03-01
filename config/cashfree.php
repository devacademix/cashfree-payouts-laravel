<?php

return [
    'base_url' => env('CASHFREE_PAYOUT_BASE_URL'),
    'client_id' => env('CASHFREE_CLIENT_ID'),
    'client_secret' => env('CASHFREE_CLIENT_SECRET'),
    'webhook_secret' => env('CASHFREE_WEBHOOK_SECRET', env('CASHFREE_CLIENT_SECRET')),
    'api_version' => env('CASHFREE_API_VERSION', '2024-01-01'),
];
