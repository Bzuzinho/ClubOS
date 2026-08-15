<?php

use App\Http\Controllers\Desportivo\SportsDashboardWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web','auth','verified','module.access:desportivo'])
    ->prefix('desportivo')
    ->group(function (): void {
        Route::get('/dashboard', [SportsDashboardWorkspaceController::class, 'index'])
            ->middleware('permission.access:desportivo.dashboard,view')
            ->name('desportivo.dashboard');
    });
