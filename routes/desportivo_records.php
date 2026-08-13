<?php

use App\Http\Controllers\Desportivo\SportsRecordsWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web','auth','verified','module.access:desportivo'])
    ->prefix('desportivo/registos')
    ->name('desportivo.registos.')
    ->group(function (): void {
        Route::get('/', [SportsRecordsWorkspaceController::class,'index'])
            ->middleware('permission.access:desportivo.registos,view')->name('index');
        Route::get('/export', [SportsRecordsWorkspaceController::class,'export'])
            ->middleware('permission.access:desportivo.registos,view')->name('export');
        Route::get('/treinos/{training}', [SportsRecordsWorkspaceController::class,'training'])
            ->middleware('permission.access:desportivo.registos,view')->name('training');
        Route::get('/atletas/{athlete}', [SportsRecordsWorkspaceController::class,'athlete'])
            ->middleware('permission.access:desportivo.registos,view')->name('athlete');
    });
