<?php

use App\Http\Controllers\Desportivo\SportsPlanningWorkspaceController;
use App\Http\Controllers\Desportivo\SportsTrainingLibraryController;
use App\Http\Controllers\Desportivo\SportsTrainingWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->get('/desportivo/planeamento', SportsPlanningWorkspaceController::class . '@index')
    ->middleware('permission.access:desportivo.planeamento,view')
    ->name('desportivo.planeamento');

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->prefix('desportivo/biblioteca')
    ->group(function (): void {
        Route::get('/', [SportsTrainingLibraryController::class, 'index'])
            ->middleware('permission.access:desportivo.treinos.biblioteca,view')
            ->name('desportivo.biblioteca');
        Route::post('/planos', [SportsTrainingLibraryController::class, 'store'])
            ->middleware('permission.access:desportivo.treinos.biblioteca,edit')
            ->name('desportivo.biblioteca.planos.store');
        Route::put('/planos/{plan}', [SportsTrainingLibraryController::class, 'revise'])
            ->middleware('permission.access:desportivo.treinos.biblioteca,edit')
            ->name('desportivo.biblioteca.planos.revise');
        Route::post('/planos/{plan}/duplicar', [SportsTrainingLibraryController::class, 'duplicate'])
            ->middleware('permission.access:desportivo.treinos.biblioteca,edit')
            ->name('desportivo.biblioteca.planos.duplicate');
        Route::delete('/planos/{plan}', [SportsTrainingLibraryController::class, 'archive'])
            ->middleware('permission.access:desportivo.treinos.biblioteca,delete')
            ->name('desportivo.biblioteca.planos.archive');
        Route::post('/planos/{plan}/reativar', [SportsTrainingLibraryController::class, 'restore'])
            ->middleware('permission.access:desportivo.treinos.biblioteca,edit')
            ->name('desportivo.biblioteca.planos.restore');
    });

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->prefix('desportivo/treinos')
    ->group(function (): void {
        Route::get('/', [SportsTrainingWorkspaceController::class, 'index'])
            ->middleware('permission.access:desportivo.treinos,view')
            ->name('desportivo.treinos');
        Route::post('/{training}/cancelar', [SportsTrainingWorkspaceController::class, 'cancel'])
            ->middleware('permission.access:desportivo.treinos.agendamento,edit')
            ->name('desportivo.treinos.sessions.cancel');
        Route::post('/{training}/plano', [SportsTrainingWorkspaceController::class, 'applyPlanVersion'])
            ->middleware('permission.access:desportivo.treinos.agendamento,edit')
            ->name('desportivo.treinos.sessions.plan-version');
        Route::put('/{training}/snapshot', [SportsTrainingWorkspaceController::class, 'overrideSnapshot'])
            ->middleware('permission.access:desportivo.treinos.agendamento,edit')
            ->name('desportivo.treinos.sessions.snapshot');
    });
