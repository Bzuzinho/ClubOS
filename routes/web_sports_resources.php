<?php

declare(strict_types=1);

use App\Http\Controllers\ConvocatoriasController;
use App\Http\Controllers\EquipasController;
use App\Http\Controllers\MembrosEquipaController;
use App\Http\Controllers\SessoesFormacaoController;
use Illuminate\Support\Facades\Route;

Route::resource('equipas', EquipasController::class);
Route::resource('membros-equipa', MembrosEquipaController::class)->except(['index', 'create', 'show', 'edit']);
Route::resource('sessoes-formacao', SessoesFormacaoController::class);
Route::resource('convocatorias', ConvocatoriasController::class);
