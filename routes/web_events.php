<?php

declare(strict_types=1);

use App\Http\Controllers\EventosController;
use Illuminate\Support\Facades\Route;

Route::resource('eventos', EventosController::class)
    ->middleware('module.access:eventos')
    ->middlewareFor(['index'], 'permission.access:eventos.calendario,view')
    ->middlewareFor(['store', 'update'], 'permission.access:eventos.calendario,edit')
    ->middlewareFor(['destroy'], 'permission.access:eventos.calendario,delete')
    ->only(['index', 'store', 'update', 'destroy']);

// Event participant management routes
Route::post('eventos/{event}/participantes', [EventosController::class, 'addParticipant'])
    ->middleware(['module.access:eventos', 'permission.access:eventos.convocatorias,edit'])
    ->name('eventos.participantes.add');
Route::delete('eventos/{event}/participantes/{user}', [EventosController::class, 'removeParticipant'])
    ->middleware(['module.access:eventos', 'permission.access:eventos.convocatorias,delete'])
    ->name('eventos.participantes.remove');
Route::put('eventos/{event}/participantes/{user}', [EventosController::class, 'updateParticipantStatus'])
    ->middleware(['module.access:eventos', 'permission.access:eventos.convocatorias,edit'])
    ->name('eventos.participantes.update');
Route::get('eventos-stats', [EventosController::class, 'stats'])
    ->middleware(['module.access:eventos', 'permission.access:eventos.resultados,view'])
    ->name('eventos.stats');
