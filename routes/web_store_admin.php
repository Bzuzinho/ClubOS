<?php

declare(strict_types=1);

use App\Http\Controllers\AdminLojaController;
use App\Http\Controllers\AdminLojaEncomendaController;
use App\Http\Controllers\AdminLojaHeroController;
use App\Http\Controllers\AdminLojaProdutoController;
use Illuminate\Support\Facades\Route;

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
