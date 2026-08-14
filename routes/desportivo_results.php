<?php

use App\Http\Controllers\Desportivo\SportsResultsWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->prefix('desportivo/resultados')
    ->group(function (): void {
        Route::get('/', [SportsResultsWorkspaceController::class, 'index'])
            ->middleware('permission.access:desportivo.resultados,view')
            ->name('desportivo.resultados');

        Route::get('/{competition}/workspace', [SportsResultsWorkspaceController::class, 'show'])
            ->middleware('permission.access:desportivo.resultados,view')
            ->whereUuid('competition')
            ->name('desportivo.resultados.show');

        Route::post('/{competition}/bulk', [SportsResultsWorkspaceController::class, 'bulkStore'])
            ->middleware('permission.access:desportivo.resultados,edit')
            ->whereUuid('competition')
            ->name('desportivo.resultados.bulk');
    });
