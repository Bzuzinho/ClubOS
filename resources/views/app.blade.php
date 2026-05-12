<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <!-- ✅ CSRF Token para Inertia/Axios -->
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0f57b3">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="BSCN">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png?v=2">
        <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png?v=2">
        <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png?v=2">
        <link rel="manifest" href="/site.webmanifest?v=2">
        <link rel="shortcut icon" href="/favicon.ico?v=2">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
