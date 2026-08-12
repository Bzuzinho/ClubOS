<?php

use App\Http\Controllers\Desportivo\SportsStructureController;
use App\Http\Controllers\Desportivo\SportsStructureWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->prefix('desportivo/estrutura')
    ->group(function () {
        Route::get('/', [SportsStructureWorkspaceController::class, 'index'])
            ->middleware('permission.access:desportivo.estrutura,view')
            ->name('desportivo.estrutura.index');

        Route::post('/modalidades', [SportsStructureController::class, 'storeModality'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.modalidades.store');
        Route::put('/modalidades/{modality}', [SportsStructureController::class, 'updateModality'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.modalidades.update');
        Route::delete('/modalidades/{modality}', [SportsStructureController::class, 'destroyModality'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.modalidades.destroy');

        Route::post('/programas', [SportsStructureController::class, 'storeProgram'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.programas.store');
        Route::put('/programas/{program}', [SportsStructureController::class, 'updateProgram'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.programas.update');
        Route::delete('/programas/{program}', [SportsStructureController::class, 'destroyProgram'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.programas.destroy');

        Route::post('/epocas', [SportsStructureWorkspaceController::class, 'storeSeason'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.epocas.store');
        Route::put('/epocas/{season}', [SportsStructureWorkspaceController::class, 'updateSeason'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.epocas.update');
        Route::delete('/epocas/{season}', [SportsStructureWorkspaceController::class, 'destroySeason'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.epocas.destroy');
        Route::post('/epocas/programas', [SportsStructureController::class, 'syncSeasonProgram'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.epocas.programas.store');
        Route::post('/epocas/{season}/encerrar', [SportsStructureController::class, 'closeSeason'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.epocas.close');
        Route::post('/epocas/{season}/reabrir', [SportsStructureController::class, 'reopenSeason'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.epocas.reopen');

        Route::post('/escaloes', [SportsStructureWorkspaceController::class, 'storeAgeGroup'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.escaloes.store');
        Route::put('/escaloes/{ageGroup}', [SportsStructureWorkspaceController::class, 'updateAgeGroup'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.escaloes.update');
        Route::delete('/escaloes/{ageGroup}', [SportsStructureWorkspaceController::class, 'destroyAgeGroup'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.escaloes.destroy');
        Route::post('/escaloes/regras', [SportsStructureController::class, 'storeAgeGroupRule'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.escaloes.regras.store');
        Route::delete('/escaloes/regras/{rule}', [SportsStructureController::class, 'destroyAgeGroupRule'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.escaloes.regras.destroy');
        Route::post('/escaloes/overrides', [SportsStructureController::class, 'storeAgeGroupOverride'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.escaloes.overrides.store');
        Route::delete('/escaloes/overrides/{override}', [SportsStructureController::class, 'destroyAgeGroupOverride'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.escaloes.overrides.destroy');

        Route::post('/grupos', [SportsStructureWorkspaceController::class, 'storeGroup'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.grupos.store');
        Route::put('/grupos/{group}', [SportsStructureWorkspaceController::class, 'updateGroup'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.grupos.update');
        Route::delete('/grupos/{group}', [SportsStructureWorkspaceController::class, 'destroyGroup'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.grupos.destroy');
        Route::post('/grupos/epocas', [SportsStructureController::class, 'storeGroupSeason'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.grupos.epocas.store');
        Route::post('/grupos/memberships', [SportsStructureController::class, 'storeMembership'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.grupos.memberships.store');
        Route::put('/grupos/memberships/{membership}', [SportsStructureWorkspaceController::class, 'updateMembership'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.grupos.memberships.update');
        Route::post('/grupos/memberships/{membership}/terminar', [SportsStructureWorkspaceController::class, 'endMembership'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.grupos.memberships.end');

        Route::post('/treinadores/funcoes', [SportsStructureController::class, 'storeCoachRole'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.treinadores.funcoes.store');
        Route::put('/treinadores/funcoes/{role}', [SportsStructureWorkspaceController::class, 'updateCoachRole'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.treinadores.funcoes.update');
        Route::delete('/treinadores/funcoes/{role}', [SportsStructureWorkspaceController::class, 'destroyCoachRole'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.treinadores.funcoes.destroy');
        Route::post('/treinadores/atribuicoes', [SportsStructureWorkspaceController::class, 'storeCoach'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.treinadores.atribuicoes.store');
        Route::put('/treinadores/atribuicoes/{coach}', [SportsStructureWorkspaceController::class, 'updateCoach'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.treinadores.atribuicoes.update');
        Route::post('/treinadores/atribuicoes/{coach}/terminar', [SportsStructureWorkspaceController::class, 'endCoach'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.treinadores.atribuicoes.end');

        Route::post('/locais', [SportsStructureWorkspaceController::class, 'storeVenue'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.locais.store');
        Route::put('/locais/{venue}', [SportsStructureWorkspaceController::class, 'updateVenue'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.locais.update');
        Route::delete('/locais/{venue}', [SportsStructureWorkspaceController::class, 'destroyVenue'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.locais.destroy');

        Route::post('/piscinas', [SportsStructureController::class, 'storePool'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.piscinas.store');
        Route::put('/piscinas/{pool}', [SportsStructureWorkspaceController::class, 'updatePool'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.piscinas.update');
        Route::delete('/piscinas/{pool}', [SportsStructureWorkspaceController::class, 'destroyPool'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.piscinas.destroy');
        Route::post('/piscinas/{pool}/pistas', [SportsStructureController::class, 'storeLane'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.pistas.store');
        Route::put('/pistas/{lane}', [SportsStructureWorkspaceController::class, 'updateLane'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.pistas.update');
        Route::delete('/pistas/{lane}', [SportsStructureWorkspaceController::class, 'destroyLane'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.pistas.destroy');
    });
