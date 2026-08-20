<?php

use App\Http\Controllers\CategoriasFinanceirasController;
use App\Http\Controllers\TransacoesController;
use Illuminate\Support\Facades\Route;

// Register the retired collection GET routes before the generic
// /financeiro/{financeiro} resource route from routes/web.php.
// The canonical web declarations later replace these exact URI entries
// without changing their route-collection position, so known legacy URLs
// reach the retirement controllers instead of implicit Invoice binding.
Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('/financeiro/transacoes', [TransacoesController::class, 'index']);
    Route::get('/financeiro/categorias', [CategoriasFinanceirasController::class, 'index']);
});
