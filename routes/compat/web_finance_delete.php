<?php

declare(strict_types=1);

use App\Http\Controllers\FinanceiroController;
use Illuminate\Support\Facades\Route;

Route::post('financeiro/{financeiro}/apagar', [FinanceiroController::class, 'destroy'])
    ->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,delete'])
    ->name('financeiro.destroy.post');
