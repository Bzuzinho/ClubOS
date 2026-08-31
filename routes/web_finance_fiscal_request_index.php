<?php

declare(strict_types=1);

use App\Http\Controllers\Financeiro\FiscalDocumentRequestController;
use Illuminate\Support\Facades\Route;

Route::get('financeiro/fiscal-document-requests', [FiscalDocumentRequestController::class, 'index'])
    ->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,view'])
    ->name('financeiro.fiscal-document-requests.index');
