<?php

declare(strict_types=1);

use App\Http\Controllers\LogisticaController;
use Illuminate\Support\Facades\Route;

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
