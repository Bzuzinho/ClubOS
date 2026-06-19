<?php

return [
    'protect_destructive_commands' => env('DB_PROTECT_DESTRUCTIVE_COMMANDS', true),
    'allow_destructive_commands' => env('DB_ALLOW_DESTRUCTIVE_COMMANDS', false),
    'destructive_confirmation' => env('DB_DESTRUCTIVE_CONFIRMATION', 'DESTROY_LOCAL_DATABASE'),

    'blocked_commands' => [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ],

    'protected_database_signatures' => [
        'neon.tech',
    ],
];
