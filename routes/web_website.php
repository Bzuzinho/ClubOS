<?php

declare(strict_types=1);

use App\Http\Controllers\WebsiteController;
use App\Http\Controllers\WebsiteMediaController;
use App\Http\Controllers\WebsitePageController;
use Illuminate\Support\Facades\Route;

Route::prefix('website')
    ->middleware('module.access:website')
    ->group(function () {
        Route::get('/', [WebsiteController::class, 'index'])
            ->middleware('permission.access:website.dashboard,view')
            ->name('website.index');
        Route::get('/pedidos/{submission}', [WebsiteController::class, 'show'])
            ->middleware('permission.access:website.pedidos,view')
            ->name('website.submissions.show');
        Route::patch('/pedidos/{submission}/estado', [WebsiteController::class, 'updateStatus'])
            ->middleware('permission.access:website.pedidos,edit')
            ->name('website.submissions.status');
        Route::get('/paginas', [WebsitePageController::class, 'index'])
            ->middleware('permission.access:website.paginas,view')
            ->name('website.pages.index');
        Route::post('/paginas', [WebsitePageController::class, 'store'])
            ->middleware('permission.access:website.paginas,create')
            ->name('website.pages.store');
        Route::post('/paginas/importar-website-atual', [WebsitePageController::class, 'importWebsite'])
            ->middleware('permission.access:website.paginas,edit')
            ->name('website.pages.import-website');
        Route::get('/paginas/{page}/editar', [WebsitePageController::class, 'edit'])
            ->middleware('permission.access:website.paginas,view')
            ->name('website.pages.edit');
        Route::post('/paginas/{page}/importar', [WebsitePageController::class, 'import'])
            ->middleware('permission.access:website.paginas,edit')
            ->name('website.pages.import');
        Route::patch('/paginas/{page}', [WebsitePageController::class, 'update'])
            ->middleware('permission.access:website.paginas,edit')
            ->name('website.pages.update');
        Route::patch('/paginas/{page}/autosave', [WebsitePageController::class, 'autosave'])
            ->middleware('permission.access:website.paginas,edit')
            ->name('website.pages.autosave');
        Route::delete('/paginas/{page}', [WebsitePageController::class, 'destroy'])
            ->middleware('permission.access:website.paginas,delete')
            ->name('website.pages.destroy');
        Route::get('/paginas/{page}/previsualizar', [WebsitePageController::class, 'preview'])
            ->middleware('permission.access:website.paginas,view')
            ->name('website.pages.preview');
        Route::post('/paginas/{page}/versoes/{version}/recuperar', [WebsitePageController::class, 'restore'])
            ->middleware('permission.access:website.paginas,edit')
            ->name('website.pages.versions.restore');
        Route::post('/media', [WebsiteMediaController::class, 'store'])
            ->middleware('permission.access:website.paginas,edit')
            ->name('website.media.store');
        Route::patch('/media/{media}', [WebsiteMediaController::class, 'update'])
            ->middleware('permission.access:website.paginas,edit')
            ->name('website.media.update');
        Route::delete('/media/{media}', [WebsiteMediaController::class, 'destroy'])
            ->middleware('permission.access:website.paginas,delete')
            ->name('website.media.destroy');
    });

Route::get('/website-redes/{path?}', [WebsiteController::class, 'legacyRedirect'])
    ->where('path', '.*')
    ->name('website.legacy-redirect');
