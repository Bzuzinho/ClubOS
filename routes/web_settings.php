<?php

declare(strict_types=1);

use App\Http\Controllers\ConfiguracoesController;
use App\Http\Controllers\ConfiguracoesDesportivoController;
use Illuminate\Support\Facades\Route;

Route::middleware('module.access:configuracoes')->group(function () {
    Route::get('/configuracoes', [ConfiguracoesController::class, 'index'])
        ->name('configuracoes');
    Route::get('/configuracoes/desportivo', [ConfiguracoesDesportivoController::class, 'index'])
        ->middleware('permission.access:configuracoes.estados,view')
        ->name('configuracoes.desportivo.index');
    Route::post('/configuracoes/desportivo/estados-atleta', [ConfiguracoesDesportivoController::class, 'storeAthleteStatus'])
        ->middleware('permission.access:configuracoes.estados,edit')
        ->name('configuracoes.desportivo.estados-atleta.store');
    Route::put('/configuracoes/desportivo/estados-atleta/{athleteStatus}', [ConfiguracoesDesportivoController::class, 'updateAthleteStatus'])
        ->middleware('permission.access:configuracoes.estados,edit')
        ->name('configuracoes.desportivo.estados-atleta.update');
    Route::delete('/configuracoes/desportivo/estados-atleta/{athleteStatus}', [ConfiguracoesDesportivoController::class, 'destroyAthleteStatus'])
        ->middleware('permission.access:configuracoes.estados,delete')
        ->name('configuracoes.desportivo.estados-atleta.destroy');
    Route::post('/configuracoes/desportivo/tipos-treino', [ConfiguracoesDesportivoController::class, 'storeTrainingType'])
        ->name('configuracoes.desportivo.tipos-treino.store');
    Route::put('/configuracoes/desportivo/tipos-treino/{trainingType}', [ConfiguracoesDesportivoController::class, 'updateTrainingType'])
        ->name('configuracoes.desportivo.tipos-treino.update');
    Route::delete('/configuracoes/desportivo/tipos-treino/{trainingType}', [ConfiguracoesDesportivoController::class, 'destroyTrainingType'])
        ->name('configuracoes.desportivo.tipos-treino.destroy');
    Route::post('/configuracoes/desportivo/zonas-treino', [ConfiguracoesDesportivoController::class, 'storeTrainingZone'])
        ->name('configuracoes.desportivo.zonas-treino.store');
    Route::put('/configuracoes/desportivo/zonas-treino/{trainingZone}', [ConfiguracoesDesportivoController::class, 'updateTrainingZone'])
        ->name('configuracoes.desportivo.zonas-treino.update');
    Route::delete('/configuracoes/desportivo/zonas-treino/{trainingZone}', [ConfiguracoesDesportivoController::class, 'destroyTrainingZone'])
        ->name('configuracoes.desportivo.zonas-treino.destroy');
    Route::post('/configuracoes/desportivo/motivos-ausencia', [ConfiguracoesDesportivoController::class, 'storeAbsenceReason'])
        ->name('configuracoes.desportivo.motivos-ausencia.store');
    Route::put('/configuracoes/desportivo/motivos-ausencia/{absenceReason}', [ConfiguracoesDesportivoController::class, 'updateAbsenceReason'])
        ->name('configuracoes.desportivo.motivos-ausencia.update');
    Route::delete('/configuracoes/desportivo/motivos-ausencia/{absenceReason}', [ConfiguracoesDesportivoController::class, 'destroyAbsenceReason'])
        ->name('configuracoes.desportivo.motivos-ausencia.destroy');
    Route::post('/configuracoes/desportivo/motivos-lesao', [ConfiguracoesDesportivoController::class, 'storeInjuryReason'])
        ->name('configuracoes.desportivo.motivos-lesao.store');
    Route::put('/configuracoes/desportivo/motivos-lesao/{injuryReason}', [ConfiguracoesDesportivoController::class, 'updateInjuryReason'])
        ->name('configuracoes.desportivo.motivos-lesao.update');
    Route::delete('/configuracoes/desportivo/motivos-lesao/{injuryReason}', [ConfiguracoesDesportivoController::class, 'destroyInjuryReason'])
        ->name('configuracoes.desportivo.motivos-lesao.destroy');
    Route::post('/configuracoes/desportivo/tipos-piscina', [ConfiguracoesDesportivoController::class, 'storePoolType'])
        ->name('configuracoes.desportivo.tipos-piscina.store');
    Route::put('/configuracoes/desportivo/tipos-piscina/{poolType}', [ConfiguracoesDesportivoController::class, 'updatePoolType'])
        ->name('configuracoes.desportivo.tipos-piscina.update');
    Route::delete('/configuracoes/desportivo/tipos-piscina/{poolType}', [ConfiguracoesDesportivoController::class, 'destroyPoolType'])
        ->name('configuracoes.desportivo.tipos-piscina.destroy');

    Route::post('/configuracoes/tipos-utilizador', [ConfiguracoesController::class, 'storeUserType'])->middleware('permission.access:configuracoes.tipos_utilizador,edit')->name('configuracoes.tipos-utilizador.store');
    Route::put('/configuracoes/tipos-utilizador/{userType}', [ConfiguracoesController::class, 'updateUserType'])->middleware('permission.access:configuracoes.tipos_utilizador,edit')->name('configuracoes.tipos-utilizador.update');
    Route::delete('/configuracoes/tipos-utilizador/{userType}', [ConfiguracoesController::class, 'destroyUserType'])->middleware('permission.access:configuracoes.tipos_utilizador,delete')->name('configuracoes.tipos-utilizador.destroy');

    Route::post('/configuracoes/escaloes', [ConfiguracoesController::class, 'storeAgeGroup'])->name('configuracoes.escaloes.store');
    Route::put('/configuracoes/escaloes/{ageGroup}', [ConfiguracoesController::class, 'updateAgeGroup'])->name('configuracoes.escaloes.update');
    Route::delete('/configuracoes/escaloes/{ageGroup}', [ConfiguracoesController::class, 'destroyAgeGroup'])->name('configuracoes.escaloes.destroy');

    Route::post('/configuracoes/tipos-evento', [ConfiguracoesController::class, 'storeEventType'])->name('configuracoes.tipos-evento.store');
    Route::put('/configuracoes/tipos-evento/{eventType}', [ConfiguracoesController::class, 'updateEventType'])->name('configuracoes.tipos-evento.update');
    Route::delete('/configuracoes/tipos-evento/{eventType}', [ConfiguracoesController::class, 'destroyEventType'])->name('configuracoes.tipos-evento.destroy');

    Route::put('/configuracoes/clube', [ConfiguracoesController::class, 'updateClubSettings'])->name('configuracoes.clube.update');

    Route::post('/configuracoes/permissoes', [ConfiguracoesController::class, 'storePermission'])->middleware('permission.access:configuracoes.permissoes,edit')->name('configuracoes.permissoes.store');
    Route::put('/configuracoes/permissoes/{permission}', [ConfiguracoesController::class, 'updatePermission'])->middleware('permission.access:configuracoes.permissoes,edit')->name('configuracoes.permissoes.update');
    Route::delete('/configuracoes/permissoes/{permission}', [ConfiguracoesController::class, 'destroyPermission'])->middleware('permission.access:configuracoes.permissoes,delete')->name('configuracoes.permissoes.destroy');

    Route::post('/configuracoes/centros-custo', [ConfiguracoesController::class, 'storeCostCenter'])->name('configuracoes.centros-custo.store');
    Route::put('/configuracoes/centros-custo/{costCenter}', [ConfiguracoesController::class, 'updateCostCenter'])->name('configuracoes.centros-custo.update');
    Route::delete('/configuracoes/centros-custo/{costCenter}', [ConfiguracoesController::class, 'destroyCostCenter'])->name('configuracoes.centros-custo.destroy');

    Route::post('/configuracoes/tipos-fatura', [ConfiguracoesController::class, 'storeInvoiceType'])->name('configuracoes.tipos-fatura.store');
    Route::put('/configuracoes/tipos-fatura/{invoiceType}', [ConfiguracoesController::class, 'updateInvoiceType'])->name('configuracoes.tipos-fatura.update');
    Route::delete('/configuracoes/tipos-fatura/{invoiceType}', [ConfiguracoesController::class, 'destroyInvoiceType'])->name('configuracoes.tipos-fatura.destroy');

    Route::post('/configuracoes/mensalidades', [ConfiguracoesController::class, 'storeMonthlyFee'])->name('configuracoes.mensalidades.store');
    Route::put('/configuracoes/mensalidades/{monthlyFee}', [ConfiguracoesController::class, 'updateMonthlyFee'])->name('configuracoes.mensalidades.update');
    Route::delete('/configuracoes/mensalidades/{monthlyFee}', [ConfiguracoesController::class, 'destroyMonthlyFee'])->name('configuracoes.mensalidades.destroy');

    Route::post('/configuracoes/metodos-pagamento', [ConfiguracoesController::class, 'storePaymentMethod'])->name('configuracoes.metodos-pagamento.store');
    Route::put('/configuracoes/metodos-pagamento/{paymentMethod}', [ConfiguracoesController::class, 'updatePaymentMethod'])->name('configuracoes.metodos-pagamento.update');
    Route::delete('/configuracoes/metodos-pagamento/{paymentMethod}', [ConfiguracoesController::class, 'destroyPaymentMethod'])->name('configuracoes.metodos-pagamento.destroy');

    Route::post('/configuracoes/artigos', [ConfiguracoesController::class, 'storeProduct'])->name('configuracoes.artigos.store');
    Route::put('/configuracoes/artigos/{product}', [ConfiguracoesController::class, 'updateProduct'])->name('configuracoes.artigos.update');
    Route::post('/configuracoes/artigos/{product}', [ConfiguracoesController::class, 'updateProduct']);
    Route::delete('/configuracoes/artigos/{product}', [ConfiguracoesController::class, 'destroyProduct'])->name('configuracoes.artigos.destroy');

    Route::post('/configuracoes/categorias-itens', [ConfiguracoesController::class, 'storeItemCategory'])->name('configuracoes.categorias-itens.store');
    Route::put('/configuracoes/categorias-itens/{itemCategory}', [ConfiguracoesController::class, 'updateItemCategory'])->name('configuracoes.categorias-itens.update');
    Route::delete('/configuracoes/categorias-itens/{itemCategory}', [ConfiguracoesController::class, 'destroyItemCategory'])->name('configuracoes.categorias-itens.destroy');

    Route::post('/configuracoes/patrocinadores', [ConfiguracoesController::class, 'storeSponsor'])->name('configuracoes.patrocinadores.store');
    Route::put('/configuracoes/patrocinadores/{sponsor}', [ConfiguracoesController::class, 'updateSponsor'])->name('configuracoes.patrocinadores.update');
    Route::delete('/configuracoes/patrocinadores/{sponsor}', [ConfiguracoesController::class, 'destroySponsor'])->name('configuracoes.patrocinadores.destroy');

    Route::post('/configuracoes/fornecedores', [ConfiguracoesController::class, 'storeSupplier'])->name('configuracoes.fornecedores.store');
    Route::put('/configuracoes/fornecedores/{supplier}', [ConfiguracoesController::class, 'updateSupplier'])->name('configuracoes.fornecedores.update');
    Route::delete('/configuracoes/fornecedores/{supplier}', [ConfiguracoesController::class, 'destroySupplier'])->name('configuracoes.fornecedores.destroy');

    Route::post('/configuracoes/provas', [ConfiguracoesController::class, 'storeProvaTipo'])->name('configuracoes.provas.store');
    Route::put('/configuracoes/provas/{provaTipo}', [ConfiguracoesController::class, 'updateProvaTipo'])->name('configuracoes.provas.update');
    Route::delete('/configuracoes/provas/{provaTipo}', [ConfiguracoesController::class, 'destroyProvaTipo'])->name('configuracoes.provas.destroy');

    Route::put('/configuracoes/notificacoes', [ConfiguracoesController::class, 'updateNotificationPreferences'])->name('configuracoes.notificacoes.update');
    Route::post('/configuracoes/notificacoes/fontes-dinamicas', [ConfiguracoesController::class, 'storeCommunicationDynamicSource'])->name('configuracoes.notificacoes.fontes-dinamicas.store');
    Route::put('/configuracoes/notificacoes/fontes-dinamicas/{dynamicSource}', [ConfiguracoesController::class, 'updateCommunicationDynamicSource'])->name('configuracoes.notificacoes.fontes-dinamicas.update');
    Route::delete('/configuracoes/notificacoes/fontes-dinamicas/{dynamicSource}', [ConfiguracoesController::class, 'destroyCommunicationDynamicSource'])->name('configuracoes.notificacoes.fontes-dinamicas.destroy');
    Route::post('/configuracoes/notificacoes/categorias-alerta', [ConfiguracoesController::class, 'storeCommunicationAlertCategory'])->name('configuracoes.notificacoes.categorias-alerta.store');
    Route::put('/configuracoes/notificacoes/categorias-alerta/{alertCategory}', [ConfiguracoesController::class, 'updateCommunicationAlertCategory'])->name('configuracoes.notificacoes.categorias-alerta.update');
    Route::delete('/configuracoes/notificacoes/categorias-alerta/{alertCategory}', [ConfiguracoesController::class, 'destroyCommunicationAlertCategory'])->name('configuracoes.notificacoes.categorias-alerta.destroy');
});
