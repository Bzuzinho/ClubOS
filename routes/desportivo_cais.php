<?php

use App\Http\Controllers\Desportivo\SportsCaisWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->prefix('desportivo/cais')
    ->group(function (): void {
        Route::get('/metricas', [SportsCaisWorkspaceController::class, 'metrics'])
            ->middleware('permission.access:desportivo.treinos.cais,view')
            ->name('desportivo.cais.metrics.index');
        Route::post('/metricas', [SportsCaisWorkspaceController::class, 'storeMetrics'])
            ->middleware('permission.access:desportivo.treinos.cais,edit')
            ->name('desportivo.cais.metrics.store');
        Route::patch('/{training}/atletas/{athlete}/presenca', [SportsCaisWorkspaceController::class, 'presence'])
            ->middleware('permission.access:desportivo.treinos.cais,edit')
            ->name('desportivo.cais.presence');
        Route::post('/{training}/atletas/{athlete}/rapido', [SportsCaisWorkspaceController::class, 'quick'])
            ->middleware('permission.access:desportivo.treinos.cais,edit')
            ->name('desportivo.cais.quick');
        Route::put('/{training}/atletas/{athlete}/registo', [SportsCaisWorkspaceController::class, 'register'])
            ->middleware('permission.access:desportivo.treinos.cais,edit')
            ->name('desportivo.cais.register');
    });
