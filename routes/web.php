<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PortalPageController;
use App\Http\Controllers\PortalDocumentController;
use App\Http\Controllers\PortalProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FamilyPortalController;
use App\Http\Controllers\MembrosController;
use App\Http\Controllers\MembrosImportController;
use App\Http\Controllers\DocumentosMembrosController;
use App\Http\Controllers\RelacoesMembroController;
use App\Http\Controllers\EventosController;
use App\Http\Controllers\DesportivoController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\TransacoesController;
use App\Http\Controllers\TaxasController;
use App\Http\Controllers\CategoriasFinanceirasController;
use App\Http\Controllers\RelatoriosFinanceirosController;
use App\Http\Controllers\AdminLojaController;
use App\Http\Controllers\AdminLojaEncomendaController;
use App\Http\Controllers\AdminLojaHeroController;
use App\Http\Controllers\AdminLojaProdutoController;
use App\Http\Controllers\LojaCarrinhoController;
use App\Http\Controllers\LojaController;
use App\Http\Controllers\LojaEncomendaController;
use App\Http\Controllers\LojaProdutoController;
use App\Http\Controllers\PatrocinosController;
use App\Http\Controllers\ComunicacaoController;
use App\Http\Controllers\CampanhasMarketingController;
use App\Http\Controllers\Communication\CommunicationAlertController;
use App\Http\Controllers\Communication\CommunicationCampaignController;
use App\Http\Controllers\Communication\CommunicationDeliveryController;
use App\Http\Controllers\Communication\CommunicationSegmentController;
use App\Http\Controllers\Communication\CommunicationTemplateController;
use App\Http\Controllers\ConfiguracoesController;
use App\Http\Controllers\ConfiguracoesDesportivoController;
use App\Http\Controllers\LogisticaController;
use App\Http\Controllers\EquipasController;
use App\Http\Controllers\Financeiro\BankReconciliationAliasController;
use App\Http\Controllers\Financeiro\FiscalDocumentRequestController;
use App\Http\Controllers\PortalTrainingController;
use App\Http\Controllers\PortalEventController;
use App\Http\Controllers\MembrosEquipaController;
use App\Http\Controllers\SessoesFormacaoController;
use App\Http\Controllers\ConvocatoriasController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard — gate handled inside DashboardController (athlete vs admin dispatch).
    // Do not add module.access:inicio here, otherwise athlete/encarregado get 403
    // before the controller can render the personal dashboard.
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('/portal/perfil', [PortalProfileController::class, 'show'])
        ->name('portal.profile');
    Route::patch('/portal/perfil', [PortalProfileController::class, 'update'])
        ->name('portal.profile.update');
    Route::get('/portal/treinos', [PortalTrainingController::class, 'index'])
        ->name('portal.trainings');
    Route::patch('/portal/treinos/{trainingAthlete}', [PortalTrainingController::class, 'update'])
        ->name('portal.trainings.update');
    Route::get('/portal/eventos', [PortalEventController::class, 'index'])
        ->name('portal.events');
    Route::patch('/portal/eventos/{eventConvocation}', [PortalEventController::class, 'update'])
        ->name('portal.events.update');
    Route::get('/portal/pagamentos', [PortalPageController::class, 'payments'])
        ->name('portal.payments');
    Route::get('/portal/resultados', [PortalPageController::class, 'results'])
        ->name('portal.results');
    Route::get('/portal/documentos', [PortalDocumentController::class, 'index'])
        ->name('portal.documents');
    Route::post('/portal/documentos', [PortalDocumentController::class, 'store'])
        ->name('portal.documents.store');
    Route::get('/portal/documentos/essenciais/{documentType}', [PortalDocumentController::class, 'showLegacy'])
        ->name('portal.documents.legacy.view');
    Route::get('/portal/documentos/essenciais/{documentType}/download', [PortalDocumentController::class, 'downloadLegacy'])
        ->name('portal.documents.legacy.download');
    Route::get('/portal/documentos/uploads/{document}', [PortalDocumentController::class, 'showUpload'])
        ->name('portal.documents.uploads.view');
    Route::get('/portal/documentos/uploads/{document}/download', [PortalDocumentController::class, 'downloadUpload'])
        ->name('portal.documents.uploads.download');
    Route::get('/portal/comunicados', [PortalPageController::class, 'communications'])
        ->name('portal.communications');
    Route::post('/portal/comunicados', [PortalPageController::class, 'storeCommunication'])
        ->name('portal.communications.store');
    Route::post('/portal/comunicados/read', [PortalPageController::class, 'markCommunicationRead'])
        ->name('portal.communications.read');
    Route::post('/portal/comunicados/unread', [PortalPageController::class, 'markCommunicationUnread'])
        ->name('portal.communications.unread');
    Route::post('/portal/comunicados/mark-all-read', [PortalPageController::class, 'markAllCommunicationsRead'])
        ->name('portal.communications.markAllRead');
    Route::delete('/portal/comunicados/received', [PortalPageController::class, 'destroyReceivedCommunication'])
        ->name('portal.communications.received.destroy');
    Route::delete('/portal/comunicados/sent/{message}', [PortalPageController::class, 'destroySentCommunication'])
        ->name('portal.communications.sent.destroy');
    Route::redirect('/portal/loja', '/loja')
        ->name('portal.shop');
    Route::get('/loja', [LojaController::class, 'index'])->name('store.front.index');
    Route::get('/loja', [LojaController::class, 'index'])->name('loja.index');
    Route::get('/loja/produto/{produto:slug}', [LojaProdutoController::class, 'show'])->name('store.front.product.show');
    Route::get('/loja/carrinho', [LojaCarrinhoController::class, 'show'])->name('store.front.cart.show');
    Route::get('/loja/historico', [LojaEncomendaController::class, 'index'])->name('store.front.orders.index');
    Route::get('/loja/historico/{encomenda}', [LojaEncomendaController::class, 'show'])->name('store.front.orders.show');
    Route::get('/portal/familia', [FamilyPortalController::class, 'show'])
        ->name('portal.family');
    Route::get('/portal/familia/membros/search', [FamilyPortalController::class, 'searchMembers'])
        ->name('portal.family.members.search');
    Route::post('/portal/familia/membros', [FamilyPortalController::class, 'storeMember'])
        ->name('portal.family.members.store');
    
    Route::get('financeiro/fiscal-document-requests', [FiscalDocumentRequestController::class, 'index'])
        ->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,view'])
        ->name('financeiro.fiscal-document-requests.index');

    // Resource routes
    Route::resource('membros', MembrosController::class)
        ->middleware('module.access:membros')
        ->middlewareFor(['index'], 'permission.access:membros.lista,view')
        ->middlewareFor(['show'], 'permission.access:membros.ficha,view')
        ->middlewareFor(['create', 'store', 'edit', 'update'], 'permission.access:membros.ficha,edit')
        ->middlewareFor(['destroy'], 'permission.access:membros.ficha,delete')
        ->parameters(['membros' => 'member']);

    Route::prefix('membros/import')->middleware(['module.access:membros', 'permission.access:membros.ficha,edit'])->group(function () {
        Route::get('template', [MembrosImportController::class, 'template'])
            ->name('membros.import.template');
        Route::post('preview', [MembrosImportController::class, 'preview'])
            ->name('membros.import.preview');
        Route::post('/', [MembrosImportController::class, 'store'])
            ->name('membros.import.store');
    });
    
    // Member documents and relationships
    Route::prefix('membros/{member}')->middleware('module.access:membros')->group(function() {
        Route::get('documentos', [DocumentosMembrosController::class, 'index'])
            ->middleware('permission.access:membros.ficha,view')
            ->name('membros.documentos.index');
        Route::post('documentos', [DocumentosMembrosController::class, 'store'])
            ->middleware('permission.access:membros.ficha,edit')
            ->name('membros.documentos.store');
        Route::delete('documentos/{document}', [DocumentosMembrosController::class, 'destroy'])
            ->middleware('permission.access:membros.ficha,delete')
            ->name('membros.documentos.destroy');
        
        Route::get('relacoes', [RelacoesMembroController::class, 'index'])
            ->middleware('permission.access:membros.ficha,view')
            ->name('membros.relacoes.index');
        Route::post('relacoes', [RelacoesMembroController::class, 'store'])
            ->middleware('permission.access:membros.ficha,edit')
            ->name('membros.relacoes.store');
        Route::delete('relacoes/{relationship}', [RelacoesMembroController::class, 'destroy'])
            ->middleware('permission.access:membros.ficha,delete')
            ->name('membros.relacoes.destroy');
        Route::post('send-access-email', [MembrosController::class, 'sendAccessEmail'])
            ->middleware('permission.access:membros.ficha,edit')
            ->name('membros.send-access-email');
    });
    
    Route::resource('eventos', EventosController::class)
        ->middleware('module.access:eventos')
        ->middlewareFor(['index', 'show'], 'permission.access:eventos.calendario,view')
        ->middlewareFor(['store', 'edit', 'update'], 'permission.access:eventos.calendario,edit')
        ->middlewareFor(['destroy'], 'permission.access:eventos.calendario,delete')
        ->except(['create']);
    
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
    
    Route::resource('financeiro', FinanceiroController::class)
        ->middleware('module.access:financeiro')
        ->middlewareFor(['index', 'show'], 'permission.access:financeiro.dashboard,view')
        ->middlewareFor(['store', 'edit', 'update'], 'permission.access:financeiro.dashboard,edit')
        ->middlewareFor(['destroy'], 'permission.access:financeiro.dashboard,delete')
        ->except(['create']);
    Route::prefix('financeiro')->name('financeiro.')->middleware('module.access:financeiro')->group(function () {
        Route::post('payments/allocate', [FinanceiroController::class, 'storePayment'])
            ->middleware('permission.access:financeiro.dashboard,edit')
            ->name('payments.allocate');
        Route::get('invoices/open', [FinanceiroController::class, 'openInvoices'])
            ->middleware('permission.access:financeiro.dashboard,view')
            ->name('invoices.open');
        Route::get('bank-statements/unreconciled', [FinanceiroController::class, 'unreconciledBankStatements'])
            ->middleware('permission.access:financeiro.dashboard,view')
            ->name('bank-statements.unreconciled');
        Route::get('bank-aliases', [BankReconciliationAliasController::class, 'index'])
            ->middleware('permission.access:financeiro.dashboard,view')
            ->name('bank-aliases.index');
        Route::post('bank-aliases', [BankReconciliationAliasController::class, 'store'])
            ->middleware('permission.access:financeiro.dashboard,edit')
            ->name('bank-aliases.store');
        Route::patch('bank-aliases/{alias}', [BankReconciliationAliasController::class, 'update'])
            ->middleware('permission.access:financeiro.dashboard,edit')
            ->name('bank-aliases.update');
        Route::delete('bank-aliases/{alias}', [BankReconciliationAliasController::class, 'destroy'])
            ->middleware('permission.access:financeiro.dashboard,delete')
            ->name('bank-aliases.destroy');
    });
    Route::prefix('logistica')->middleware('module.access:logistica')->group(function () {
        Route::get('/', [LogisticaController::class, 'index'])->name('logistica.index');
        Route::post('/requisicoes', [LogisticaController::class, 'storeRequest'])->name('logistica.requisicoes.store');
        Route::put('/requisicoes/{logisticsRequest}', [LogisticaController::class, 'updateRequest'])->name('logistica.requisicoes.update');
        Route::delete('/requisicoes/{logisticsRequest}', [LogisticaController::class, 'destroyRequest'])->name('logistica.requisicoes.destroy');
        Route::post('/requisicoes/{logisticsRequest}/aprovar', [LogisticaController::class, 'approveRequest'])->name('logistica.requisicoes.approve');
        Route::post('/requisicoes/{logisticsRequest}/faturar', [LogisticaController::class, 'invoiceRequest'])->name('logistica.requisicoes.invoice');
        Route::post('/requisicoes/{logisticsRequest}/entregar', [LogisticaController::class, 'deliverRequest'])->name('logistica.requisicoes.deliver');

        Route::post('/stock/movimentos', [LogisticaController::class, 'registerStockMovement'])->name('logistica.stock.movimentos.store');

        Route::post('/emprestimos', [LogisticaController::class, 'storeLoan'])->name('logistica.emprestimos.store');
        Route::put('/emprestimos/{equipmentLoan}', [LogisticaController::class, 'updateLoan'])->name('logistica.emprestimos.update');
        Route::delete('/emprestimos/{equipmentLoan}', [LogisticaController::class, 'destroyLoan'])->name('logistica.emprestimos.destroy');
        Route::post('/emprestimos/{equipmentLoan}/devolver', [LogisticaController::class, 'returnLoan'])->name('logistica.emprestimos.return');

        Route::post('/fornecedores/compras', [LogisticaController::class, 'registerSupplierPurchase'])->name('logistica.fornecedores.compras.store');
        Route::put('/fornecedores/compras/{supplierPurchase}', [LogisticaController::class, 'updateSupplierPurchase'])->name('logistica.fornecedores.compras.update');
        Route::delete('/fornecedores/compras/{supplierPurchase}', [LogisticaController::class, 'destroySupplierPurchase'])->name('logistica.fornecedores.compras.destroy');
    });
    Route::post('financeiro/{financeiro}/apagar', [FinanceiroController::class, 'destroy'])
        ->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,delete'])
        ->name('financeiro.destroy.post');
    Route::prefix('admin/loja')->middleware('module.access:loja')->name('admin.loja.')->group(function () {
        Route::get('/', [AdminLojaController::class, 'index'])->name('index');

        Route::get('/produtos', [AdminLojaProdutoController::class, 'index'])->name('produtos.index');
        Route::get('/produtos/criar', [AdminLojaProdutoController::class, 'create'])->name('produtos.create');
        Route::get('/produtos/{produto}/editar', [AdminLojaProdutoController::class, 'edit'])->name('produtos.edit');

        Route::get('/encomendas', [AdminLojaEncomendaController::class, 'index'])->name('encomendas.index');
        Route::get('/encomendas/{encomenda}', [AdminLojaEncomendaController::class, 'show'])->name('encomendas.show');

        Route::get('/hero', [AdminLojaHeroController::class, 'index'])->name('hero.index');
        Route::get('/hero/criar', [AdminLojaHeroController::class, 'create'])->name('hero.create');
        Route::get('/hero/{item}/editar', [AdminLojaHeroController::class, 'edit'])->name('hero.edit');
    });
    Route::prefix('patrocinios')->middleware('module.access:patrocinios')->group(function () {
        Route::get('/integracoes', [PatrocinosController::class, 'integrationsIndex'])->name('patrocinios.integrations.index');
        Route::post('/{patrocinio}/integracoes/retry', [PatrocinosController::class, 'retry'])->name('patrocinios.integrations.retry');
        Route::post('/{patrocinio}/fechar', [PatrocinosController::class, 'close'])->name('patrocinios.close');
        Route::post('/{patrocinio}/cancelar', [PatrocinosController::class, 'cancel'])->name('patrocinios.cancel');
    });
    Route::resource('patrocinios', PatrocinosController::class)->middleware('module.access:patrocinios');
    Route::get('/comunicacao', [ComunicacaoController::class, 'index'])->middleware('module.access:comunicacao')->name('comunicacao.index');

    Route::post('/comunicacao/campaigns', [CommunicationCampaignController::class, 'store'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.store');
    Route::put('/comunicacao/campaigns/{campaign}', [CommunicationCampaignController::class, 'update'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.update');
    Route::post('/comunicacao/campaigns/{campaign}/duplicate', [CommunicationCampaignController::class, 'duplicate'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.duplicate');
    Route::post('/comunicacao/campaigns/{campaign}/send', [CommunicationCampaignController::class, 'send'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.send');
    Route::post('/comunicacao/campaigns/{campaign}/schedule', [CommunicationCampaignController::class, 'schedule'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.schedule');
    Route::post('/comunicacao/campaigns/{campaign}/cancel', [CommunicationCampaignController::class, 'cancel'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.cancel');
    Route::delete('/comunicacao/campaigns/{campaign}', [CommunicationCampaignController::class, 'destroy'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.destroy');
    Route::post('/comunicacao/campaigns/send-individual', [CommunicationCampaignController::class, 'sendIndividual'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.sendIndividual');

    Route::get('/comunicacao/deliveries', [CommunicationDeliveryController::class, 'index'])->middleware('module.access:comunicacao')->name('comunicacao.deliveries.index');

    Route::get('/comunicacao/templates', [CommunicationTemplateController::class, 'index'])->middleware('module.access:comunicacao')->name('comunicacao.templates.index');
    Route::post('/comunicacao/templates', [CommunicationTemplateController::class, 'store'])->middleware('module.access:comunicacao')->name('comunicacao.templates.store');
    Route::put('/comunicacao/templates/{template}', [CommunicationTemplateController::class, 'update'])->middleware('module.access:comunicacao')->name('comunicacao.templates.update');
    Route::post('/comunicacao/templates/{template}/duplicate', [CommunicationTemplateController::class, 'duplicate'])->middleware('module.access:comunicacao')->name('comunicacao.templates.duplicate');
    Route::post('/comunicacao/templates/{template}/toggle', [CommunicationTemplateController::class, 'toggle'])->middleware('module.access:comunicacao')->name('comunicacao.templates.toggle');
    Route::delete('/comunicacao/templates/{template}', [CommunicationTemplateController::class, 'destroy'])->middleware('module.access:comunicacao')->name('comunicacao.templates.destroy');

    Route::get('/comunicacao/segments', [CommunicationSegmentController::class, 'index'])->middleware('module.access:comunicacao')->name('comunicacao.segments.index');
    Route::post('/comunicacao/segments', [CommunicationSegmentController::class, 'store'])->middleware('module.access:comunicacao')->name('comunicacao.segments.store');
    Route::put('/comunicacao/segments/{segment}', [CommunicationSegmentController::class, 'update'])->middleware('module.access:comunicacao')->name('comunicacao.segments.update');
    Route::delete('/comunicacao/segments/{segment}', [CommunicationSegmentController::class, 'destroy'])->middleware('module.access:comunicacao')->name('comunicacao.segments.destroy');

    Route::get('/comunicacao/alerts', [CommunicationAlertController::class, 'index'])->middleware('module.access:comunicacao')->name('comunicacao.alerts.index');
    Route::post('/comunicacao/alerts/mark-read', [CommunicationAlertController::class, 'markRead'])->middleware('module.access:comunicacao')->name('comunicacao.alerts.markRead');
    Route::post('/comunicacao/alerts/mark-unread', [CommunicationAlertController::class, 'markUnread'])->middleware('module.access:comunicacao')->name('comunicacao.alerts.markUnread');
    Route::post('/comunicacao/alerts/mark-all-read', [CommunicationAlertController::class, 'markAllRead'])->middleware('module.access:comunicacao')->name('comunicacao.alerts.markAllRead');
    Route::delete('/comunicacao/alerts/{alert}', [CommunicationAlertController::class, 'destroy'])->middleware('module.access:comunicacao')->name('comunicacao.alerts.destroy');

    Route::resource('campanhas-marketing', CampanhasMarketingController::class)->middleware('module.access:marketing');
    
    // Settings routes
    Route::get('/configuracoes', [ConfiguracoesController::class, 'index'])
        ->middleware('module.access:configuracoes')
        ->name('configuracoes');
    Route::get('/configuracoes/desportivo', [ConfiguracoesDesportivoController::class, 'index'])
        ->middleware(['module.access:configuracoes', 'permission.access:configuracoes.estados,view'])
        ->name('configuracoes.desportivo.index');
    Route::post('/configuracoes/desportivo/estados-atleta', [ConfiguracoesDesportivoController::class, 'storeAthleteStatus'])
        ->middleware(['module.access:configuracoes', 'permission.access:configuracoes.estados,edit'])
        ->name('configuracoes.desportivo.estados-atleta.store');
    Route::put('/configuracoes/desportivo/estados-atleta/{athleteStatus}', [ConfiguracoesDesportivoController::class, 'updateAthleteStatus'])
        ->middleware(['module.access:configuracoes', 'permission.access:configuracoes.estados,edit'])
        ->name('configuracoes.desportivo.estados-atleta.update');
    Route::delete('/configuracoes/desportivo/estados-atleta/{athleteStatus}', [ConfiguracoesDesportivoController::class, 'destroyAthleteStatus'])
        ->middleware(['module.access:configuracoes', 'permission.access:configuracoes.estados,delete'])
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
    
    // Settings CRUD sub-routes
    Route::post('/configuracoes/tipos-utilizador', [ConfiguracoesController::class, 'storeUserType'])->middleware(['module.access:configuracoes', 'permission.access:configuracoes.tipos_utilizador,edit'])->name('configuracoes.tipos-utilizador.store');
    Route::put('/configuracoes/tipos-utilizador/{userType}', [ConfiguracoesController::class, 'updateUserType'])->middleware(['module.access:configuracoes', 'permission.access:configuracoes.tipos_utilizador,edit'])->name('configuracoes.tipos-utilizador.update');
    Route::delete('/configuracoes/tipos-utilizador/{userType}', [ConfiguracoesController::class, 'destroyUserType'])->middleware(['module.access:configuracoes', 'permission.access:configuracoes.tipos_utilizador,delete'])->name('configuracoes.tipos-utilizador.destroy');
    
    Route::post('/configuracoes/escaloes', [ConfiguracoesController::class, 'storeAgeGroup'])->name('configuracoes.escaloes.store');
    Route::put('/configuracoes/escaloes/{ageGroup}', [ConfiguracoesController::class, 'updateAgeGroup'])->name('configuracoes.escaloes.update');
    Route::delete('/configuracoes/escaloes/{ageGroup}', [ConfiguracoesController::class, 'destroyAgeGroup'])->name('configuracoes.escaloes.destroy');
    
    Route::post('/configuracoes/tipos-evento', [ConfiguracoesController::class, 'storeEventType'])->name('configuracoes.tipos-evento.store');
    Route::put('/configuracoes/tipos-evento/{eventType}', [ConfiguracoesController::class, 'updateEventType'])->name('configuracoes.tipos-evento.update');
    Route::delete('/configuracoes/tipos-evento/{eventType}', [ConfiguracoesController::class, 'destroyEventType'])->name('configuracoes.tipos-evento.destroy');
    
    Route::put('/configuracoes/clube', [ConfiguracoesController::class, 'updateClubSettings'])->name('configuracoes.club.update');
    
    Route::put('/configuracoes/clube', [ConfiguracoesController::class, 'updateClubSettings'])->name('configuracoes.clube.update');

    Route::post('/configuracoes/permissoes', [ConfiguracoesController::class, 'storePermission'])->middleware(['module.access:configuracoes', 'permission.access:configuracoes.permissoes,edit'])->name('configuracoes.permissoes.store');
    Route::put('/configuracoes/permissoes/{permission}', [ConfiguracoesController::class, 'updatePermission'])->middleware(['module.access:configuracoes', 'permission.access:configuracoes.permissoes,edit'])->name('configuracoes.permissoes.update');
    Route::delete('/configuracoes/permissoes/{permission}', [ConfiguracoesController::class, 'destroyPermission'])->middleware(['module.access:configuracoes', 'permission.access:configuracoes.permissoes,delete'])->name('configuracoes.permissoes.destroy');

    Route::post('/configuracoes/centros-custo', [ConfiguracoesController::class, 'storeCostCenter'])->name('configuracoes.centros-custo.store');
    Route::put('/configuracoes/centros-custo/{costCenter}', [ConfiguracoesController::class, 'updateCostCenter'])->name('configuracoes.centros-custo.update');
    Route::delete('/configuracoes/centros-custo/{costCenter}', [ConfiguracoesController::class, 'destroyCostCenter'])->name('configuracoes.centros-custo.destroy');

    Route::post('/configuracoes/tipos-fatura', [ConfiguracoesController::class, 'storeInvoiceType'])->name('configuracoes.tipos-fatura.store');
    Route::put('/configuracoes/tipos-fatura/{invoiceType}', [ConfiguracoesController::class, 'updateInvoiceType'])->name('configuracoes.tipos-fatura.update');
    Route::delete('/configuracoes/tipos-fatura/{invoiceType}', [ConfiguracoesController::class, 'destroyInvoiceType'])->name('configuracoes.tipos-fatura.destroy');

    Route::post('/configuracoes/mensalidades', [ConfiguracoesController::class, 'storeMonthlyFee'])->name('configuracoes.mensalidades.store');
    Route::put('/configuracoes/mensalidades/{monthlyFee}', [ConfiguracoesController::class, 'updateMonthlyFee'])->name('configuracoes.mensalidades.update');
    Route::delete('/configuracoes/mensalidades/{monthlyFee}', [ConfiguracoesController::class, 'destroyMonthlyFee'])->name('configuracoes.mensalidades.destroy');

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
    // Sports module routes
    Route::resource('equipas', EquipasController::class);
    Route::resource('membros-equipa', MembrosEquipaController::class)->except(['index', 'create', 'show', 'edit']);
    Route::resource('sessoes-formacao', SessoesFormacaoController::class);
    Route::resource('convocatorias', ConvocatoriasController::class);
    // Financial module routes
    Route::prefix('financeiro')->group(function () {
        // Transactions
        Route::get('/transacoes', [TransacoesController::class, 'index'])->name('transacoes.index');
        Route::post('/transacoes', [TransacoesController::class, 'store'])->name('transacoes.store');
        Route::put('/transacoes/{transaction}', [TransacoesController::class, 'update'])->name('transacoes.update');
        Route::delete('/transacoes/{transaction}', [TransacoesController::class, 'destroy'])->name('transacoes.destroy');
        
        // Membership Fees
        Route::get('/taxas', [TaxasController::class, 'index'])->name('taxas.index');
        Route::post('/taxas', [TaxasController::class, 'store'])->name('taxas.store');
        Route::put('/taxas/{membershipFee}', [TaxasController::class, 'update'])->name('taxas.update');
        Route::delete('/taxas/{membershipFee}', [TaxasController::class, 'destroy'])->name('taxas.destroy');
        Route::post('/taxas/gerar', [TaxasController::class, 'generate'])->name('taxas.gerar');
        Route::post('/taxas/{membershipFee}/marcar-pago', [TaxasController::class, 'markAsPaid'])->name('taxas.marcar-pago');
        
        // Categories
        Route::get('/categorias', [CategoriasFinanceirasController::class, 'index'])->name('categorias-financeiras.index');
        Route::post('/categorias', [CategoriasFinanceirasController::class, 'store'])->name('categorias-financeiras.store');
        Route::put('/categorias/{category}', [CategoriasFinanceirasController::class, 'update'])->name('categorias-financeiras.update');
        Route::delete('/categorias/{category}', [CategoriasFinanceirasController::class, 'destroy'])->name('categorias-financeiras.destroy');
        
        // Reports
        Route::get('/relatorios', [RelatoriosFinanceirosController::class, 'index'])->name('relatorios-financeiros.index');

        Route::post('/movimentos', [FinanceiroController::class, 'storeMovimento'])->name('financeiro.movimentos.store');
        Route::put('/movimentos/{movimento}', [FinanceiroController::class, 'updateMovimento'])->name('financeiro.movimentos.update');
        Route::delete('/movimentos/{movimento}', [FinanceiroController::class, 'destroyMovimento'])->name('financeiro.movimentos.destroy');
        Route::post('/movimentos/{movimento}/liquidar', [FinanceiroController::class, 'liquidarMovimento'])->name('financeiro.movimentos.liquidar');

        Route::post('/extratos', [FinanceiroController::class, 'storeExtrato'])->name('financeiro.extratos.store');
        Route::post('/extratos/bulk', [FinanceiroController::class, 'storeExtratosBulk'])->name('financeiro.extratos.bulk');
        Route::put('/extratos/{extrato}', [FinanceiroController::class, 'updateExtrato'])->name('financeiro.extratos.update');
        Route::delete('/extratos/{extrato}', [FinanceiroController::class, 'destroyExtrato'])->name('financeiro.extratos.destroy');
        Route::post('/extratos/{extrato}/conciliar', [FinanceiroController::class, 'conciliarExtrato'])->name('financeiro.extratos.conciliar');
        Route::post('/extratos/{extrato}/desconciliar', [FinanceiroController::class, 'desconciliarExtrato'])->name('financeiro.extratos.desconciliar');

        Route::post('/invoices/{invoice}/fiscal-document-request', [FiscalDocumentRequestController::class, 'createFromInvoice'])
            ->middleware('permission.access:financeiro.dashboard,edit')
            ->name('financeiro.invoices.fiscal-document-request.store');

        Route::post('/fiscal-document-requests', [FiscalDocumentRequestController::class, 'store'])
            ->middleware('permission.access:financeiro.dashboard,edit')
            ->name('financeiro.fiscal-document-requests.store');
        Route::patch('/fiscal-document-requests/{fiscalDocumentRequest}', [FiscalDocumentRequestController::class, 'update'])
            ->middleware('permission.access:financeiro.dashboard,edit')
            ->name('financeiro.fiscal-document-requests.update');
        Route::post('/fiscal-document-requests/{fiscalDocumentRequest}/mark-in-progress', [FiscalDocumentRequestController::class, 'markInProgress'])
            ->middleware('permission.access:financeiro.dashboard,edit')
            ->name('financeiro.fiscal-document-requests.mark-in-progress');
        Route::post('/fiscal-document-requests/{fiscalDocumentRequest}/mark-issued', [FiscalDocumentRequestController::class, 'markIssued'])
            ->middleware('permission.access:financeiro.dashboard,edit')
            ->name('financeiro.fiscal-document-requests.mark-issued');
        Route::post('/fiscal-document-requests/{fiscalDocumentRequest}/mark-cancelled', [FiscalDocumentRequestController::class, 'markCancelled'])
            ->middleware('permission.access:financeiro.dashboard,edit')
            ->name('financeiro.fiscal-document-requests.mark-cancelled');
        Route::post('/fiscal-document-requests/{fiscalDocumentRequest}/mark-error-data', [FiscalDocumentRequestController::class, 'markErrorData'])
            ->middleware('permission.access:financeiro.dashboard,edit')
            ->name('financeiro.fiscal-document-requests.mark-error-data');
        Route::delete('/fiscal-document-requests/{fiscalDocumentRequest}', [FiscalDocumentRequestController::class, 'destroy'])
            ->middleware('permission.access:financeiro.dashboard,delete')
            ->name('financeiro.fiscal-document-requests.destroy');
    });
});

// Backward compatibility redirects (English → Portuguese)
// These redirects help with transition period and maintain old bookmarks
Route::redirect('/members', '/membros', 301);
Route::redirect('/members/{id}', '/membros/{id}', 301);
Route::redirect('/events', '/eventos', 301);
Route::redirect('/events/{id}', '/eventos/{id}', 301);
Route::redirect('/sports', '/desportivo', 301);
Route::redirect('/sports/{id}', '/desportivo/{id}', 301);
Route::redirect('/financial', '/financeiro', 301);
Route::redirect('/shop', '/loja', 301);
Route::redirect('/shop/{id}', '/loja/{id}', 301);
Route::redirect('/sponsorships', '/patrocinios', 301);
Route::redirect('/sponsorships/{id}', '/patrocinios/{id}', 301);
Route::redirect('/communication', '/comunicacao', 301);
Route::redirect('/communication/{id}', '/comunicacao/{id}', 301);
Route::redirect('/marketing', '/campanhas-marketing', 301);
Route::redirect('/marketing/{id}', '/campanhas-marketing/{id}', 301);
Route::redirect('/settings', '/configuracoes', 301);
Route::redirect('/settings/desportivo', '/configuracoes/desportivo', 301);
Route::redirect('/teams', '/equipas', 301);
Route::redirect('/teams/{id}', '/equipas/{id}', 301);
Route::redirect('/team-members', '/membros-equipa', 301);
Route::redirect('/training-sessions', '/sessoes-formacao', 301);
Route::redirect('/call-ups', '/convocatorias', 301);

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';