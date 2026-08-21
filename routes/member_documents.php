<?php

use App\Http\Controllers\Membros\MemberMedicalDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:membros'])
    ->prefix('membros/{member}/documentos')
    ->group(function (): void {
        Route::put('/atestado-medico', [MemberMedicalDocumentController::class, 'update'])
            ->middleware('permission.access:membros.ficha,edit')
            ->name('membros.documents.medical.update');
    });
