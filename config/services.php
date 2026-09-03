<?php

return [
    'sms' => [
        'enabled' => env('SMS_ENABLED', false),
        'provider' => env('SMS_PROVIDER', 'sms_http'),
        'api_url' => env('SMS_API_URL'),
        'token' => env('SMS_API_TOKEN'),
        'sender' => env('SMS_SENDER', 'ClubOS'),
    ],

    'push' => [
        'enabled' => env('PUSH_ENABLED', false),
        'provider' => env('PUSH_PROVIDER', 'push_http'),
        'api_url' => env('PUSH_API_URL'),
        'token' => env('PUSH_API_TOKEN'),
    ],

    'communication_webhooks' => [
        'max_age_seconds' => env('COMMUNICATION_WEBHOOK_MAX_AGE_SECONDS', 300),
        'secrets' => [
            'email' => env('MAIL_WEBHOOK_SECRET'),
            'sms' => env('SMS_WEBHOOK_SECRET'),
            'push' => env('PUSH_WEBHOOK_SECRET'),
        ],
    ],
];
