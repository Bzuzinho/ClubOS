<?php

declare(strict_types=1);

use App\Http\Controllers\FamilyPortalController;
use App\Http\Controllers\LojaCarrinhoController;
use App\Http\Controllers\LojaController;
use App\Http\Controllers\LojaEncomendaController;
use App\Http\Controllers\LojaProdutoController;
use App\Http\Controllers\PortalDocumentController;
use App\Http\Controllers\PortalEventController;
use App\Http\Controllers\PortalPageController;
use App\Http\Controllers\PortalProfileController;
use App\Http\Controllers\PortalTrainingController;
use Illuminate\Support\Facades\Route;

Route::get('/portal/perfil', [PortalProfileController::class, 'show'])
    ->name('portal.profile');
Route::patch('/portal/perfil', [PortalProfileController::class, 'update'])
    ->name('portal.profile.update');
Route::get('/portal/treinos', [PortalTrainingController::class, 'index'])
    ->name('portal.trainings');
Route::patch('/portal/treinos/{trainingReference}', [PortalTrainingController::class, 'update'])
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
