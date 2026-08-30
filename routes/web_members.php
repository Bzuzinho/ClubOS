<?php

declare(strict_types=1);

use App\Http\Controllers\DocumentosMembrosController;
use App\Http\Controllers\MemberFamilyRelationsController;
use App\Http\Controllers\MembrosController;
use App\Http\Controllers\MembrosImportController;
use Illuminate\Support\Facades\Route;

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
    Route::post('familia/encarregados', [MemberFamilyRelationsController::class, 'storeGuardian'])
        ->middleware('permission.access:membros.ficha,edit')
        ->name('membros.familia.encarregados.store');
    Route::delete('familia/encarregados/{guardian}', [MemberFamilyRelationsController::class, 'destroyGuardian'])
        ->middleware('permission.access:membros.ficha,edit')
        ->name('membros.familia.encarregados.destroy');
    Route::post('familia/membros', [MemberFamilyRelationsController::class, 'storeFamilyMember'])
        ->middleware('permission.access:membros.ficha,edit')
        ->name('membros.familia.membros.store');
    Route::patch('familia/{family}/membros/{familyMember}', [MemberFamilyRelationsController::class, 'updateFamilyMember'])
        ->middleware('permission.access:membros.ficha,edit')
        ->name('membros.familia.membros.update');
    Route::delete('familia/{family}/membros/{familyMember}', [MemberFamilyRelationsController::class, 'destroyFamilyMember'])
        ->middleware('permission.access:membros.ficha,edit')
        ->name('membros.familia.membros.destroy');

    Route::get('documentos', [DocumentosMembrosController::class, 'index'])
        ->middleware('permission.access:membros.ficha,view')
        ->name('membros.documentos.index');
    Route::post('documentos', [DocumentosMembrosController::class, 'store'])
        ->middleware('permission.access:membros.ficha,edit')
        ->name('membros.documentos.store');
    Route::delete('documentos/{document}', [DocumentosMembrosController::class, 'destroy'])
        ->middleware('permission.access:membros.ficha,delete')
        ->name('membros.documentos.destroy');

    Route::post('send-access-email', [MembrosController::class, 'sendAccessEmail'])
        ->middleware('permission.access:membros.ficha,edit')
        ->name('membros.send-access-email');
});
