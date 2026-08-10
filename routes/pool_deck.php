<?php

use App\Http\Controllers\Desportivo\PoolDeckController;
use Illuminate\Support\Facades\Route;

Route::get('workspace', [PoolDeckController::class, 'workspace'])
    ->middleware('permission.access:desportivo.treinos.cais,view')
    ->name('desportivo.cais.runtime.workspace');

Route::post('treinos/{training}/abrir', [PoolDeckController::class, 'open'])
    ->middleware('permission.access:desportivo.treinos.cais,edit')
    ->name('desportivo.cais.runtime.open');

Route::patch('treinos/{training}/atletas/{trainingAthlete}', [PoolDeckController::class, 'updateAthlete'])
    ->middleware('permission.access:desportivo.treinos.cais,edit')
    ->name('desportivo.cais.runtime.athletes.update');

Route::post('treinos/{training}/metricas', [PoolDeckController::class, 'metric'])
    ->middleware('permission.access:desportivo.treinos.cais,edit')
    ->name('desportivo.cais.runtime.metrics.store');

Route::post('treinos/{training}/timers', [PoolDeckController::class, 'startTimer'])
    ->middleware('permission.access:desportivo.treinos.cais,edit')
    ->name('desportivo.cais.runtime.timers.start');

Route::post('treinos/{training}/timers/{timer}/{event}', [PoolDeckController::class, 'timerEvent'])
    ->whereIn('event', ['pause', 'resume', 'lap', 'stop'])
    ->middleware('permission.access:desportivo.treinos.cais,edit')
    ->name('desportivo.cais.runtime.timers.event');

Route::post('treinos/{training}/excecoes', [PoolDeckController::class, 'exception'])
    ->middleware('permission.access:desportivo.treinos.cais,edit')
    ->name('desportivo.cais.runtime.exceptions.store');

Route::post('treinos/{training}/fechar', [PoolDeckController::class, 'close'])
    ->middleware('permission.access:desportivo.treinos.cais,edit')
    ->name('desportivo.cais.runtime.close');
