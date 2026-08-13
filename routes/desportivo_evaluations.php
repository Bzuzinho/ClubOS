<?php

use App\Http\Controllers\Desportivo\SportsEvaluationWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web','auth','verified','module.access:desportivo'])
    ->prefix('desportivo/avaliacoes')
    ->name('desportivo.avaliacoes.')
    ->group(function (): void {
        Route::get('/', [SportsEvaluationWorkspaceController::class,'index'])->middleware('permission.access:desportivo.treinos.cais,view')->name('index');
        Route::post('/modelos', [SportsEvaluationWorkspaceController::class,'storeModel'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('models.store');
        Route::put('/modelos/{model}', [SportsEvaluationWorkspaceController::class,'updateModel'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('models.update');
        Route::delete('/modelos/{model}', [SportsEvaluationWorkspaceController::class,'destroyModel'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('models.destroy');
        Route::post('/modelos/versoes/{version}/fork', [SportsEvaluationWorkspaceController::class,'forkVersion'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('versions.fork');
        Route::post('/modelos/versoes/{version}/publicar', [SportsEvaluationWorkspaceController::class,'publishVersion'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('versions.publish');
        Route::post('/modelos/versoes/{version}/seccoes', [SportsEvaluationWorkspaceController::class,'storeSection'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('sections.store');
        Route::put('/seccoes/{section}', [SportsEvaluationWorkspaceController::class,'updateSection'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('sections.update');
        Route::delete('/seccoes/{section}', [SportsEvaluationWorkspaceController::class,'destroySection'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('sections.destroy');
        Route::post('/seccoes/{section}/criterios', [SportsEvaluationWorkspaceController::class,'storeCriterion'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('criteria.store');
        Route::put('/criterios/{criterion}', [SportsEvaluationWorkspaceController::class,'updateCriterion'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('criteria.update');
        Route::delete('/criterios/{criterion}', [SportsEvaluationWorkspaceController::class,'destroyCriterion'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('criteria.destroy');
        Route::post('/campanhas', [SportsEvaluationWorkspaceController::class,'storeCampaign'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('campaigns.store');
        Route::post('/campanhas/{campaign}/publicar', [SportsEvaluationWorkspaceController::class,'publishCampaign'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('campaigns.publish');
        Route::post('/campanhas/atletas/{campaignAthlete}/avaliacao', [SportsEvaluationWorkspaceController::class,'startEvaluation'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('evaluations.start');
        Route::put('/avaliacoes/{evaluation}', [SportsEvaluationWorkspaceController::class,'saveEvaluation'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('evaluations.update');
        Route::post('/avaliacoes/{evaluation}/concluir', [SportsEvaluationWorkspaceController::class,'completeEvaluation'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('evaluations.complete');
        Route::post('/avaliacoes/{evaluation}/reabrir', [SportsEvaluationWorkspaceController::class,'reopenEvaluation'])->middleware('permission.access:desportivo.treinos.cais,edit')->name('evaluations.reopen');
        Route::get('/atletas/{athlete}/historico', [SportsEvaluationWorkspaceController::class,'athleteHistory'])->middleware('permission.access:desportivo.treinos.cais,view')->name('athletes.history');
    });
