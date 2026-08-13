<?php

use App\Http\Controllers\Desportivo\SportsCaisWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->prefix('desportivo/cais')
    ->group(function (): void {
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
