<?php

use App\Http\Controllers\Desportivo\SportsAthletesWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->prefix('desportivo/atletas')
    ->name('desportivo.atletas.')
    ->group(function (): void {
        Route::get('/', [SportsAthletesWorkspaceController::class, 'index'])
            ->middleware('permission.access:desportivo.dashboard,view')
            ->name('index');
        Route::get('/{athlete}', [SportsAthletesWorkspaceController::class, 'show'])
            ->middleware('permission.access:desportivo.dashboard,view')
            ->name('show');
    });
