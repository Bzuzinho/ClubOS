<?php

use App\Http\Controllers\Api\ConvocationPublicationController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'api',
    'auth',
    'module.access:eventos',
    'permission.access:eventos.convocatorias,edit',
])->post(
    '/api/eventos/convocation-groups/{convocationGroup}/publish',
    ConvocationPublicationController::class,
)->name('api.eventos.convocation-groups.publish');
