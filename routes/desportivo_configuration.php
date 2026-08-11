<?php

use App\Http\Controllers\Desportivo\SportsConfigurationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->prefix('desportivo/configuracao')
    ->name('desportivo.configuracao.')
    ->group(function (): void {
        Route::get('/', [SportsConfigurationController::class, 'index'])
            ->middleware('permission.access:desportivo.configuracao,view')
            ->name('index');

        Route::post('/{catalog}', [SportsConfigurationController::class, 'store'])
            ->middleware('permission.access:desportivo.configuracao,edit')
            ->name('store');

        Route::put('/{catalog}/{id}', [SportsConfigurationController::class, 'update'])
            ->middleware('permission.access:desportivo.configuracao,edit')
            ->name('update');

        Route::delete('/{catalog}/{id}', [SportsConfigurationController::class, 'destroy'])
            ->middleware('permission.access:desportivo.configuracao,delete')
            ->name('destroy');
    });
