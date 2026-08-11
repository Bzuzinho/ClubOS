<?php

use App\Http\Controllers\Desportivo\SportsStructureController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web','auth','verified','module.access:desportivo'])->prefix('desportivo/estrutura')->group(function () {
    Route::get('/', [SportsStructureController::class,'index'])->middleware('permission.access:desportivo.estrutura,view')->name('desportivo.estrutura.index');
    Route::post('/modalidades', [SportsStructureController::class,'storeModality'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.modalidades.store');
    Route::put('/modalidades/{modality}', [SportsStructureController::class,'updateModality'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.modalidades.update');
    Route::delete('/modalidades/{modality}', [SportsStructureController::class,'destroyModality'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.modalidades.destroy');
    Route::post('/programas', [SportsStructureController::class,'storeProgram'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.programas.store');
    Route::put('/programas/{program}', [SportsStructureController::class,'updateProgram'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.programas.update');
    Route::delete('/programas/{program}', [SportsStructureController::class,'destroyProgram'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.programas.destroy');
    Route::post('/epocas/programas', [SportsStructureController::class,'syncSeasonProgram'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.epocas.programas.store');
    Route::post('/escaloes/regras', [SportsStructureController::class,'storeAgeGroupRule'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.escaloes.regras.store');
    Route::delete('/escaloes/regras/{rule}', [SportsStructureController::class,'destroyAgeGroupRule'])->middleware('permission.access:desportivo.estrutura,delete')->name('desportivo.estrutura.escaloes.regras.destroy');
    Route::post('/grupos/epocas', [SportsStructureController::class,'storeGroupSeason'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.grupos.epocas.store');
    Route::post('/treinadores/funcoes', [SportsStructureController::class,'storeCoachRole'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.treinadores.funcoes.store');
    Route::post('/piscinas', [SportsStructureController::class,'storePool'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.piscinas.store');
    Route::post('/piscinas/{pool}/pistas', [SportsStructureController::class,'storeLane'])->middleware('permission.access:desportivo.estrutura,edit')->name('desportivo.estrutura.pistas.store');
});
