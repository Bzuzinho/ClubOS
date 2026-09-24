<?php

declare(strict_types=1);

use App\Http\Controllers\PatrocinosController;
use Illuminate\Support\Facades\Route;

Route::prefix('patrocinios')->middleware('module.access:patrocinios')->group(function () {
    Route::post('/patrocinadores', [PatrocinosController::class, 'storeSponsor'])->name('patrocinios.patrocinadores.store');
    Route::put('/patrocinadores/{sponsor}', [PatrocinosController::class, 'updateSponsor'])->name('patrocinios.patrocinadores.update');
    Route::delete('/patrocinadores/{sponsor}', [PatrocinosController::class, 'destroySponsor'])->name('patrocinios.patrocinadores.destroy');
    Route::get('/integracoes', [PatrocinosController::class, 'integrationsIndex'])->name('patrocinios.integrations.index');
    Route::post('/{patrocinio}/integracoes/retry', [PatrocinosController::class, 'retry'])->name('patrocinios.integrations.retry');
    Route::post('/{patrocinio}/fechar', [PatrocinosController::class, 'close'])->name('patrocinios.close');
    Route::post('/{patrocinio}/cancelar', [PatrocinosController::class, 'cancel'])->name('patrocinios.cancel');
});
Route::resource('patrocinios', PatrocinosController::class)->middleware('module.access:patrocinios');
