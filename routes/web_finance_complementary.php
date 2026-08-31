<?php

declare(strict_types=1);

use App\Http\Controllers\CategoriasFinanceirasController;
use App\Http\Controllers\Financeiro\FiscalDocumentRequestController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\TransacoesController;
use Illuminate\Support\Facades\Route;

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
