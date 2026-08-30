<?php

declare(strict_types=1);

use App\Http\Controllers\DesportivoController;
use Illuminate\Support\Facades\Route;

// Desportivo routes with tabs
Route::prefix('desportivo')->middleware('module.access:desportivo')->group(function () {
    Route::get('/', [DesportivoController::class, 'index'])->middleware('permission.access:desportivo.dashboard,view')->name('desportivo.index');
    Route::get('planeamento', [DesportivoController::class, 'planeamento'])->middleware('permission.access:desportivo.planeamento,view')->name('desportivo.planeamento');
    Route::get('treinos', [DesportivoController::class, 'treinos'])->middleware('permission.access:desportivo.treinos,view')->name('desportivo.treinos');
    Route::get('presencas', [DesportivoController::class, 'presencas'])->middleware('permission.access:desportivo.presencas,view')->name('desportivo.presencas');
    Route::get('cais', [DesportivoController::class, 'cais'])->middleware('permission.access:desportivo.treinos.cais,view')->name('desportivo.cais');
    Route::get('competicoes', [DesportivoController::class, 'competicoes'])->middleware('permission.access:desportivo.competicoes,view')->name('desportivo.competicoes');
    Route::get('relatorios', [DesportivoController::class, 'relatorios'])->middleware('permission.access:desportivo.resultados,view')->name('desportivo.relatorios');

    // Season (Época) operations
    Route::post('epocas', [DesportivoController::class, 'storeSeason'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.epoca.store');
    Route::put('epocas/{season}', [DesportivoController::class, 'updateSeason'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.epoca.update');
    Route::delete('epocas/{season}', [DesportivoController::class, 'deleteSeason'])->middleware('permission.access:desportivo.planeamento,delete')->name('desportivo.epoca.delete');
    Route::post('macrociclos', [DesportivoController::class, 'storeMacrocycle'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.macrociclo.store');
    Route::put('macrociclos/{macrocycle}', [DesportivoController::class, 'updateMacrocycle'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.macrociclo.update');
    Route::delete('macrociclos/{macrocycle}', [DesportivoController::class, 'deleteMacrocycle'])->middleware('permission.access:desportivo.planeamento,delete')->name('desportivo.macrociclo.delete');
    Route::post('mesociclos', [DesportivoController::class, 'storeMesocycle'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.mesociclo.store');
    Route::put('mesociclos/{mesocycle}', [DesportivoController::class, 'updateMesocycle'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.mesociclo.update');
    Route::delete('mesociclos/{mesocycle}', [DesportivoController::class, 'deleteMesocycle'])->middleware('permission.access:desportivo.planeamento,delete')->name('desportivo.mesociclo.delete');

    // Training operations
    Route::post('treinos', [DesportivoController::class, 'storeTraining'])->middleware('permission.access:desportivo.treinos.agendamento,edit')->name('desportivo.treino.store');
    Route::post('treinos/{training}/agendar', [DesportivoController::class, 'scheduleTraining'])->middleware('permission.access:desportivo.treinos.agendamento,edit')->name('desportivo.treino.schedule');
    Route::put('treinos/{training}', [DesportivoController::class, 'updateTraining'])->middleware('permission.access:desportivo.treinos.agendamento,edit')->name('desportivo.treino.update');
    Route::post('treinos/{training}/duplicar', [DesportivoController::class, 'duplicateTraining'])->middleware('permission.access:desportivo.treinos.agendamento,edit')->name('desportivo.treino.duplicate');
    Route::delete('treinos/{training}', [DesportivoController::class, 'deleteTraining'])->middleware('permission.access:desportivo.treinos.agendamento,delete')->name('desportivo.treino.delete');

    // Presence operations
    Route::put('treinos/{training}/presencas', [DesportivoController::class, 'updateTrainingPresencas'])->middleware('permission.access:desportivo.presencas,edit')->name('desportivo.treino.presencas.update');
    Route::post('treinos/{training}/atletas', [DesportivoController::class, 'addAthleteToTraining'])->middleware('permission.access:desportivo.treinos.agendamento,edit')->name('desportivo.treino.atleta.add');
    Route::delete('treinos/{training}/atletas/{user}', [DesportivoController::class, 'removeAthleteFromTraining'])->middleware('permission.access:desportivo.treinos.agendamento,delete')->name('desportivo.treino.atleta.remove');

    // Presence operations
    Route::put('presencas', [DesportivoController::class, 'updatePresencas'])->middleware('permission.access:desportivo.presencas,edit')->name('desportivo.presencas.update');
    Route::post('presencas/marcar-presentes', [DesportivoController::class, 'markAllPresent'])->middleware('permission.access:desportivo.presencas,edit')->name('desportivo.presencas.mark-all-present');
    Route::post('presencas/limpar', [DesportivoController::class, 'clearAllPresences'])->middleware('permission.access:desportivo.presencas,edit')->name('desportivo.presencas.clear-all');
    Route::get('cais/metricas', [DesportivoController::class, 'getCaisMetrics'])->middleware('permission.access:desportivo.treinos.cais,view')->name('desportivo.cais.metrics.index');
    Route::post('cais/metricas', [DesportivoController::class, 'storeCaisMetrics'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('desportivo.cais.metrics.store');
});
