<?php

use App\Http\Controllers\Desportivo\SportsAnalysisWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web','auth','verified','module.access:desportivo'])
    ->prefix('desportivo/analise')
    ->name('desportivo.analise.')
    ->group(function (): void {
        Route::get('/', [SportsAnalysisWorkspaceController::class, 'index'])
            ->middleware('permission.access:desportivo.treinos.cais,view')->name('index');
        Route::get('/atletas/{athlete}/export.csv', [SportsAnalysisWorkspaceController::class, 'exportAthlete'])
            ->middleware('permission.access:desportivo.treinos.cais,view')->name('athlete.export');
        Route::get('/atletas/{athlete}', [SportsAnalysisWorkspaceController::class, 'athlete'])
            ->middleware('permission.access:desportivo.treinos.cais,view')->name('athlete');
        Route::get('/grupos/{group}', [SportsAnalysisWorkspaceController::class, 'group'])
            ->middleware('permission.access:desportivo.treinos.cais,view')->name('group');
        Route::get('/competicoes/{competition}', [SportsAnalysisWorkspaceController::class, 'competition'])
            ->middleware('permission.access:desportivo.treinos.cais,view')->name('competition');
    });