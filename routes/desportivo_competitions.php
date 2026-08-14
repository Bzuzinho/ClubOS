<?php

use App\Http\Controllers\Desportivo\SportsCompetitionWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->prefix('desportivo/competicoes')
    ->name('desportivo.competicoes.')
    ->group(function (): void {
        Route::get('/{competition}/workspace', [SportsCompetitionWorkspaceController::class, 'show'])
            ->middleware('permission.access:desportivo.competicoes,view')
            ->whereUuid('competition')
            ->name('show');
    });
