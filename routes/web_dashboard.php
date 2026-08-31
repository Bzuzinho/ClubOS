<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// The controller dispatches athlete/guardian versus administrative dashboards.
// Do not add module.access:inicio: it would reject personal-dashboard users first.
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->name('dashboard');
