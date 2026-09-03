<?php

return [
    'sms' => [
        'enabled' => env('SMS_ENABLED', false),
        'provider' => env('SMS_PROVIDER', 'sms_http'),
        'api_url' => env('SMS_API_URL'),
        'token' => env('SMS_API_TOKEN'),
        'sender' => env('SMS_SENDER', 'ClubOS'),
    ],
];
