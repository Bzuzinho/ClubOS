<?php

declare(strict_types=1);

use App\Http\Controllers\Financeiro\BankReconciliationAliasController;
use App\Http\Controllers\Financeiro\BankReconciliationAuditController;
use App\Http\Controllers\Financeiro\BankReconciliationSuggestionController;
use App\Http\Controllers\Financeiro\ReceiptImportController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\RelatoriosFinanceirosController;
use Illuminate\Support\Facades\Route;

Route::get('financeiro/relatorios', [RelatoriosFinanceirosController::class, 'index'])
    ->middleware('module.access:financeiro')
    ->middleware('permission.access:financeiro.dashboard,view')
    ->name('relatorios-financeiros.index');

Route::get('financeiro/receipt-imports', [ReceiptImportController::class, 'index'])
    ->middleware('module.access:financeiro')
    ->middleware('permission.access:financeiro.importacao_recibos,view')
    ->name('financeiro.receipt-imports.index');
Route::post('financeiro/receipt-imports', [ReceiptImportController::class, 'store'])
    ->middleware('module.access:financeiro')
    ->middleware('permission.access:financeiro.importacao_recibos,edit')
    ->name('financeiro.receipt-imports.store');
Route::patch('financeiro/receipt-import-items/{item}', [ReceiptImportController::class, 'updateItem'])
    ->middleware('module.access:financeiro')
    ->middleware('permission.access:financeiro.importacao_recibos,edit')
    ->whereUuid('item')
    ->name('financeiro.receipt-imports.items.update');
Route::post('financeiro/receipt-imports/{batch}/commit', [ReceiptImportController::class, 'commit'])
    ->middleware('module.access:financeiro')
    ->middleware('permission.access:financeiro.importacao_recibos,edit')
    ->whereUuid('batch')
    ->name('financeiro.receipt-imports.commit');
Route::get('financeiro/receipt-import-items/{item}/preview', [ReceiptImportController::class, 'preview'])
    ->middleware('module.access:financeiro')
    ->middleware('permission.access:financeiro.importacao_recibos,view')
    ->whereUuid('item')
    ->name('financeiro.receipt-imports.items.preview');

Route::get('financeiro/bank-reconciliation-suggestions', [BankReconciliationSuggestionController::class, 'index'])
    ->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,view'])
    ->name('financeiro.bank-reconciliation-suggestions.index');
Route::get('financeiro/bank-aliases', [BankReconciliationAliasController::class, 'index'])
    ->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,view'])
    ->name('financeiro.bank-aliases.index');

Route::resource('financeiro', FinanceiroController::class)
    ->middleware('module.access:financeiro')
    ->middlewareFor(['index', 'show'], 'permission.access:financeiro.dashboard,view')
    ->middlewareFor(['store', 'edit', 'update'], 'permission.access:financeiro.dashboard,edit')
    ->middlewareFor(['destroy'], 'permission.access:financeiro.dashboard,delete')
    ->except(['create']);
Route::prefix('financeiro')->name('financeiro.')->middleware('module.access:financeiro')->group(function () {
    Route::post('monthly-fees/generate', [FinanceiroController::class, 'generateMonthlyFees'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->name('monthly-fees.generate');
    Route::post('payments/allocate', [FinanceiroController::class, 'storePayment'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->name('payments.allocate');
    Route::post('mensalidades/{invoice}/estado', [FinanceiroController::class, 'updateMonthlyInvoiceStatus'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->name('mensalidades.estado');
    Route::post('invoices/{invoice}/estado', [FinanceiroController::class, 'updateInvoicePaymentStatus'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->whereUuid('invoice')
        ->name('invoices.estado');
    Route::get('invoices/open', [FinanceiroController::class, 'openInvoices'])
        ->middleware('permission.access:financeiro.dashboard,view')
        ->name('invoices.open');
    Route::get('movements/open', [FinanceiroController::class, 'openMovements'])
        ->middleware('permission.access:financeiro.dashboard,view')
        ->name('movements.open');
    Route::get('bank-statements/unreconciled', [FinanceiroController::class, 'unreconciledBankStatements'])
        ->middleware('permission.access:financeiro.dashboard,view')
        ->name('bank-statements.unreconciled');
    Route::get('banco/auditoria', [BankReconciliationAuditController::class, 'index'])
        ->middleware('permission.access:financeiro.dashboard,view')
        ->name('bank-reconciliation-audit.index');
    Route::get('banco/auditoria/export', [BankReconciliationAuditController::class, 'export'])
        ->middleware('permission.access:financeiro.dashboard,view')
        ->name('bank-reconciliation-audit.export');
    Route::get('banco/auditoria/export-summary', [BankReconciliationAuditController::class, 'exportSummary'])
        ->middleware('permission.access:financeiro.dashboard,view')
        ->name('bank-reconciliation-audit.export-summary');
    Route::post('bank-statements/{bankStatement}/generate-suggestions', [BankReconciliationSuggestionController::class, 'generateForBankStatement'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->whereUuid('bankStatement')
        ->name('bank-statements.generate-suggestions');
    Route::post('bank-reconciliation-suggestions/generate', [BankReconciliationSuggestionController::class, 'generate'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->name('bank-reconciliation-suggestions.generate');
    Route::post('bank-reconciliation-suggestions/{suggestion}/confirm', [BankReconciliationSuggestionController::class, 'confirm'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->whereUuid('suggestion')
        ->name('bank-reconciliation-suggestions.confirm');
    Route::post('bank-reconciliation-suggestions/{suggestion}/reject', [BankReconciliationSuggestionController::class, 'reject'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->whereUuid('suggestion')
        ->name('bank-reconciliation-suggestions.reject');
    Route::post('bank-reconciliation-suggestions/{suggestion}/clear-rejection', [BankReconciliationSuggestionController::class, 'clearRejection'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->whereUuid('suggestion')
        ->name('bank-reconciliation-suggestions.clear-rejection');
    Route::post('bank-statements/{bankStatement}/allocate', [BankReconciliationSuggestionController::class, 'allocate'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->whereUuid('bankStatement')
        ->name('bank-statements.allocate');
    Route::post('bank-aliases', [BankReconciliationAliasController::class, 'store'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->name('bank-aliases.store');
    Route::patch('bank-aliases/{alias}', [BankReconciliationAliasController::class, 'update'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->name('bank-aliases.update');
    Route::post('bank-aliases/{alias}/deactivate', [BankReconciliationAliasController::class, 'deactivate'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->name('bank-aliases.deactivate');
    Route::post('bank-aliases/{alias}/reactivate', [BankReconciliationAliasController::class, 'reactivate'])
        ->middleware('permission.access:financeiro.dashboard,edit')
        ->name('bank-aliases.reactivate');
    Route::delete('bank-aliases/{alias}', [BankReconciliationAliasController::class, 'destroy'])
        ->middleware('permission.access:financeiro.dashboard,delete')
        ->name('bank-aliases.destroy');
});
