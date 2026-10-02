<?php

return [
    'url' => env('PAYMENTS_URL', 'https://pay.example.test/v1'),
    'key' => env('PAYMENTS_KEY', 'test-key'),
    'link_ttl_hours' => env('PAYMENTS_LINK_TTL_HOURS', 48),
];
