<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\TransacoesController;
use App\Http\Controllers\CategoriasFinanceirasController;
use App\Http\Controllers\ComunicacaoController;
use App\Http\Controllers\CampanhasMarketingController;
use App\Http\Controllers\Communication\CommunicationAlertController;
use App\Http\Controllers\Communication\CommunicationCampaignController;
use App\Http\Controllers\Communication\CommunicationDeliveryController;
use App\Http\Controllers\Communication\CommunicationSegmentController;
use App\Http\Controllers\Communication\CommunicationTemplateController;
use App\Http\Controllers\EquipasController;
use App\Http\Controllers\Financeiro\FiscalDocumentRequestController;
use App\Http\Controllers\PublicFormSubmissionController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\MembrosEquipaController;
use App\Http\Controllers\SessoesFormacaoController;
use App\Http\Controllers\ConvocatoriasController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/site.webmanifest', function () {
    return response()->file(public_path('site.webmanifest'), [
        'Content-Type' => 'application/manifest+json',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('pwa.manifest');

Route::get('/favicon.ico', function () {
    return response()->file(public_path('favicon.ico'), [
        'Content-Type' => 'image/x-icon',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('pwa.favicon');

Route::get('/icons/{asset}', function (string $asset) {
    $allowedAssets = [
        'apple-touch-icon.png',
        'favicon-16x16.png',
        'favicon-32x32.png',
        'icon-192.png',
        'icon-512.png',
    ];

    abort_unless(in_array($asset, $allowedAssets, true), 404);

    $path = public_path('icons/'.$asset);

    abort_unless(File::exists($path), 404);

    return response()->file($path, [
        'Content-Type' => 'image/png',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->where('asset', '[A-Za-z0-9._-]+')->name('pwa.icon');

Route::get('/', [PublicSiteController::class, 'home'])->name('public.home');
Route::get('/{page}', [PublicSiteController::class, 'show'])
    ->whereIn('page', ['clube', 'competicao', 'treinos', 'noticias', 'calendario', 'parceiros', 'contactos', 'junta-te', 'inscricao', 'privacidade'])
    ->name('public.page');
Route::post('/junta-te', [PublicFormSubmissionController::class, 'contact'])
    ->middleware('throttle:5,1')
    ->name('public.contact.store');
Route::post('/inscricao', [PublicFormSubmissionController::class, 'registration'])
    ->middleware('throttle:5,1')
    ->name('public.registration.store');

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard — gate handled inside DashboardController (athlete vs admin dispatch).
    // Do not add module.access:inicio here, otherwise athlete/encarregado get 403
    // before the controller can render the personal dashboard.
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    require __DIR__.'/web_website.php';

    require __DIR__.'/web_portal.php';

    Route::get('financeiro/fiscal-document-requests', [FiscalDocumentRequestController::class, 'index'])
        ->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,view'])
        ->name('financeiro.fiscal-document-requests.index');

    require __DIR__.'/web_members.php';

    require __DIR__.'/web_events.php';

    require __DIR__.'/web_sports.php';

    require __DIR__.'/web_finance.php';

    require __DIR__.'/web_logistics.php';

    Route::post('financeiro/{financeiro}/apagar', [FinanceiroController::class, 'destroy'])
        ->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,delete'])
        ->name('financeiro.destroy.post');

    require __DIR__.'/web_store_admin.php';

    require __DIR__.'/web_sponsorships.php';

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
    
    require __DIR__.'/web_settings.php';

    // Sports module routes
    Route::resource('equipas', EquipasController::class);
    Route::resource('membros-equipa', MembrosEquipaController::class)->except(['index', 'create', 'show', 'edit']);
    Route::resource('sessoes-formacao', SessoesFormacaoController::class);
    Route::resource('convocatorias', ConvocatoriasController::class);
    // Financial module routes
    Route::prefix('financeiro')->middleware('module.access:financeiro')->group(function () {
        // Transactions
        Route::get('/transacoes', [TransacoesController::class, 'index'])->name('transacoes.index');
        Route::post('/transacoes', [TransacoesController::class, 'store'])->name('transacoes.store');
        Route::put('/transacoes/{transaction}', [TransacoesController::class, 'update'])->name('transacoes.update');
        Route::delete('/transacoes/{transaction}', [TransacoesController::class, 'destroy'])->name('transacoes.destroy');
        
        // Categories
        Route::get('/categorias', [CategoriasFinanceirasController::class, 'index'])->name('categorias-financeiras.index');
        Route::post('/categorias', [CategoriasFinanceirasController::class, 'store'])->name('categorias-financeiras.store');
        Route::put('/categorias/{category}', [CategoriasFinanceirasController::class, 'update'])->name('categorias-financeiras.update');
        Route::delete('/categorias/{category}', [CategoriasFinanceirasController::class, 'destroy'])->name('categorias-financeiras.destroy');
        
        Route::get('/movimentos/{movimento}', [FinanceiroController::class, 'showMovimento'])->name('financeiro.movimentos.show');
        Route::post('/movimentos', [FinanceiroController::class, 'storeMovimento'])->name('financeiro.movimentos.store');
        Route::put('/movimentos/{movimento}', [FinanceiroController::class, 'updateMovimento'])->name('financeiro.movimentos.update');
        Route::delete('/movimentos/{movimento}', [FinanceiroController::class, 'destroyMovimento'])->name('financeiro.movimentos.destroy');
        Route::post('/movimentos/{movimento}/liquidar', [FinanceiroController::class, 'liquidarMovimento'])->name('financeiro.movimentos.liquidar');
        Route::post('/movimentos/{movimento}/reabrir', [FinanceiroController::class, 'reopenMovimento'])->name('financeiro.movimentos.reabrir');
        Route::post('/movimentos/{movimento}/documents', [FinanceiroController::class, 'storeMovementDocument'])->name('financeiro.movimentos.documents.store');
        Route::patch('/movimentos/{movimento}/documents/{document}/validate', [FinanceiroController::class, 'validateMovementDocument'])->name('financeiro.movimentos.documents.validate');
        Route::patch('/movimentos/{movimento}/documents/{document}/reject', [FinanceiroController::class, 'rejectMovementDocument'])->name('financeiro.movimentos.documents.reject');
        Route::patch('/movimentos/{movimento}/documents/{document}/duplicate', [FinanceiroController::class, 'markMovementDocumentDuplicate'])->name('financeiro.movimentos.documents.duplicate');
        Route::patch('/movimentos/{movimento}/recalculate-document-status', [FinanceiroController::class, 'recalculateMovementDocumentStatus'])->name('financeiro.movimentos.recalculate-document-status');
        Route::patch('/movimentos/{movimento}/mark-divergent', [FinanceiroController::class, 'markMovementConciliationDivergent'])->name('financeiro.movimentos.mark-divergent');
        Route::patch('/movimentos/{movimento}/notes', [FinanceiroController::class, 'updateMovementNotes'])->name('financeiro.movimentos.notes.update');

        Route::post('/extratos', [FinanceiroController::class, 'storeExtrato'])->name('financeiro.extratos.store');
        Route::post('/extratos/bulk', [FinanceiroController::class, 'storeExtratosBulk'])->name('financeiro.extratos.bulk');
        Route::put('/extratos/{extrato}', [FinanceiroController::class, 'updateExtrato'])->name('financeiro.extratos.update');
        Route::delete('/extratos/{extrato}', [FinanceiroController::class, 'destroyExtrato'])->name('financeiro.extratos.destroy');
        Route::post('/extratos/{extrato}/conciliar', [FinanceiroController::class, 'conciliarExtrato'])->name('financeiro.extratos.conciliar');
        Route::post('/extratos/{extrato}/desconciliar', [FinanceiroController::class, 'desconciliarExtrato'])->name('financeiro.extratos.desconciliar');
        Route::post('/extratos/{extrato}/criar-despesa', [FinanceiroController::class, 'createExpenseFromBankStatement'])->name('financeiro.extratos.criar-despesa');

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

require __DIR__.'/web_compatibility.php';

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

// Custom public pages are a true fallback so named application routes always win,
// including when Laravel compiles and caches the route collection.
Route::fallback([PublicSiteController::class, 'custom'])
    ->name('public.custom-page');
