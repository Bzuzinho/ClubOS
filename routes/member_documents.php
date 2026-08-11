<?php

use App\Http\Controllers\Membros\MemberMedicalDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('membros/{member}/documentos')
    ->group(function (): void {
        Route::put('/atestado-medico', [MemberMedicalDocumentController::class, 'update'])
            ->name('membros.documents.medical.update');
    });
