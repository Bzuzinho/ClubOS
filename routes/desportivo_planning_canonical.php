<?php

use App\Http\Controllers\Desportivo\SportsPlanningWorkspaceController;
use App\Http\Controllers\Desportivo\SportsTrainingLibraryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->get('/desportivo/planeamento', SportsPlanningWorkspaceController::class . '@index')
    ->middleware('permission.access:desportivo.planeamento,view')
    ->name('desportivo.planeamento');

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->prefix('/desportivo/biblioteca')
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
