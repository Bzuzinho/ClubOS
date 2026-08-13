<?php

use App\Http\Controllers\Desportivo\SportsPlanningWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->prefix('desportivo')
    ->group(function (): void {
        Route::get('/planeamento/workspace', [SportsPlanningWorkspaceController::class, 'index'])
            ->middleware('permission.access:desportivo.planeamento,view')
            ->name('desportivo.planeamento.workspace');

        Route::prefix('planeamento')->group(function (): void {
            Route::post('/macrociclos', [SportsPlanningWorkspaceController::class, 'storeMacro'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.macros.store');
            Route::put('/macrociclos/{macro}', [SportsPlanningWorkspaceController::class, 'updateMacro'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.macros.update');
            Route::delete('/macrociclos/{macro}', [SportsPlanningWorkspaceController::class, 'destroyMacro'])->middleware('permission.access:desportivo.planeamento,delete')->name('desportivo.planeamento.macros.destroy');

            Route::post('/mesociclos', [SportsPlanningWorkspaceController::class, 'storeMeso'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.mesos.store');
            Route::put('/mesociclos/{meso}', [SportsPlanningWorkspaceController::class, 'updateMeso'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.mesos.update');
            Route::delete('/mesociclos/{meso}', [SportsPlanningWorkspaceController::class, 'destroyMeso'])->middleware('permission.access:desportivo.planeamento,delete')->name('desportivo.planeamento.mesos.destroy');

            Route::post('/microciclos', [SportsPlanningWorkspaceController::class, 'storeMicro'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.micros.store');
            Route::put('/microciclos/{micro}', [SportsPlanningWorkspaceController::class, 'updateMicro'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.micros.update');
            Route::delete('/microciclos/{micro}', [SportsPlanningWorkspaceController::class, 'destroyMicro'])->middleware('permission.access:desportivo.planeamento,delete')->name('desportivo.planeamento.micros.destroy');

            Route::post('/sessoes', [SportsPlanningWorkspaceController::class, 'storeSession'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.sessions.store');
            Route::put('/sessoes/{training}', [SportsPlanningWorkspaceController::class, 'updateSession'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.sessions.update');

            Route::post('/recorrencias', [SportsPlanningWorkspaceController::class, 'storeRecurrence'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.recurrences.store');
            Route::put('/recorrencias/{recurrence}', [SportsPlanningWorkspaceController::class, 'updateRecurrence'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.recurrences.update');
            Route::delete('/recorrencias/{recurrence}', [SportsPlanningWorkspaceController::class, 'destroyRecurrence'])->middleware('permission.access:desportivo.planeamento,delete')->name('desportivo.planeamento.recurrences.destroy');
            Route::post('/recorrencias/{recurrence}/gerar', [SportsPlanningWorkspaceController::class, 'generateRecurrence'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.recurrences.generate');

            Route::post('/objetivos', [SportsPlanningWorkspaceController::class, 'storeObjective'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.objectives.store');
            Route::post('/objetivos/{objective}/rever', [SportsPlanningWorkspaceController::class, 'reviseObjective'])->middleware('permission.access:desportivo.planeamento,edit')->name('desportivo.planeamento.objectives.revise');
        });
    });
