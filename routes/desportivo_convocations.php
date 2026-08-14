<?php

use App\Http\Controllers\Desportivo\SportsConvocationWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'web',
    'auth',
    'verified',
    'module.access:desportivo',
])->prefix('/desportivo/convocatorias')->name('desportivo.convocatorias.')->group(function (): void {
    Route::get('/', [SportsConvocationWorkspaceController::class, 'index'])
        ->middleware('permission.access:eventos.convocatorias,view')
        ->name('index');
    Route::get('/{convocationGroup}', [SportsConvocationWorkspaceController::class, 'show'])
        ->middleware('permission.access:eventos.convocatorias,view')
        ->name('show');
    Route::post('/', [SportsConvocationWorkspaceController::class, 'store'])
        ->middleware('permission.access:eventos.convocatorias,edit')
        ->name('store');
    Route::put('/{convocationGroup}', [SportsConvocationWorkspaceController::class, 'update'])
        ->middleware('permission.access:eventos.convocatorias,edit')
        ->name('update');
    Route::post('/{convocationGroup}/publicar', [SportsConvocationWorkspaceController::class, 'publish'])
        ->middleware('permission.access:eventos.convocatorias,edit')
        ->name('publish');
});
