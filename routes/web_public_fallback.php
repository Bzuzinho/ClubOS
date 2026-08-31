<?php

use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

// Custom public pages are a true fallback so named application routes always win,
// including when Laravel compiles and caches the route collection.
Route::fallback([PublicSiteController::class, 'custom'])
    ->name('public.custom-page');
