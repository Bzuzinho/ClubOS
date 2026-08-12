<?php

use App\Http\Controllers\Desportivo\SportsPlanningWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
    ->get('/desportivo/planeamento', [SportsPlanningWorkspaceController::class, 'index'])
    ->middleware('permission.access:desportivo.planeamento,view')
    ->name('desportivo.planeamento');
