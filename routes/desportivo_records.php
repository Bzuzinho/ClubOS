<?php

use App\Http\Controllers\Desportivo\SportsRecordsWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web','auth','verified','module.access:desportivo'])
    ->prefix('desportivo/registos')
    ->name('desportivo.registos.')
    ->group(function (): void {
        Route::get('/', [SportsRecordsWorkspaceController::class,'index'])
            ->middleware('permission.access:desportivo.treinos.cais,view')->name('index');
        Route::get('/treinos/{training}', [SportsRecordsWorkspaceController::class,'training'])
            ->middleware('permission.access:desportivo.treinos.cais,view')->name('training');
        Route::get('/atletas/{athlete}', [SportsRecordsWorkspaceController::class,'athlete'])
            ->middleware('permission.access:desportivo.treinos.cais,view')->name('athlete');
    });
