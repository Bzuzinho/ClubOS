<?php

use App\Http\Controllers\Desportivo\SportsLiveWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web','auth','verified','module.access:desportivo'])
    ->prefix('desportivo/live')
    ->name('desportivo.live.')
    ->group(function (): void {
        Route::post('/{training}/monitorizacoes', [SportsLiveWorkspaceController::class,'startPlanned'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('monitorings.planned');
        Route::post('/{training}/atletas/{athlete}/livre', [SportsLiveWorkspaceController::class,'startFree'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('monitorings.free');
        Route::post('/monitorizacoes/{monitoring}/seguinte', [SportsLiveWorkspaceController::class,'next'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('monitorings.next');
        Route::post('/medicoes/{measurement}/atletas/{athlete}/split', [SportsLiveWorkspaceController::class,'split'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('measurements.split');
        Route::post('/medicoes/{measurement}/atletas/{athlete}/stop', [SportsLiveWorkspaceController::class,'stop'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('measurements.stop');
        Route::post('/medicoes/{measurement}/stop-geral', [SportsLiveWorkspaceController::class,'stopAll'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('measurements.stop-all');
        Route::post('/monitorizacoes/{monitoring}/concluir', [SportsLiveWorkspaceController::class,'complete'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('monitorings.complete');
        Route::delete('/monitorizacoes/{monitoring}', [SportsLiveWorkspaceController::class,'destroy'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('monitorings.destroy');
        Route::post('/medicoes/{measurement}/atletas/{athlete}/classificar', [SportsLiveWorkspaceController::class,'classify'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('measurements.classify');
        Route::post('/{training}/atletas/{athlete}/metricas', [SportsLiveWorkspaceController::class,'saveMetric'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('metrics.store');
        Route::get('/{training}/atletas/{athlete}/metricas/{definition}/historico', [SportsLiveWorkspaceController::class,'metricHistory'])->middleware('permission.access:desportivo.treinos.cais,view')->name('metrics.history');
        Route::delete('/{training}/atletas/{athlete}/metricas/{definition}/ultima', [SportsLiveWorkspaceController::class,'voidLatestMetric'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('metrics.latest.destroy');
    });
