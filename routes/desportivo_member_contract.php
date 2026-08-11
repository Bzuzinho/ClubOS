<?php

use App\Http\Controllers\Desportivo\SportsMemberContractController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('desportivo/contratos/membros')
    ->group(function (): void {
        Route::get('/{member}/perfil', [SportsMemberContractController::class, 'show'])
            ->name('desportivo.membros.perfil.show');
        Route::put('/{member}/perfil', [SportsMemberContractController::class, 'update'])
            ->name('desportivo.membros.perfil.update');
    });
