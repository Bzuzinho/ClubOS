<?php

return [
    'middleware' => [
        'web' => env('INERTIA_SSR_ENABLED', false)
            ? [App\Http\Middleware\HandleInertiaRequests::class]
            : [App\Http\Middleware\HandleInertiaRequests::class],
    ],

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [
            resource_path('js/Pages'),
        ],
        'page_extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],
];
