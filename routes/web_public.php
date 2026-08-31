<?php

declare(strict_types=1);

use App\Http\Controllers\PublicFormSubmissionController;
use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/site.webmanifest', function () {
    return response()->file(public_path('site.webmanifest'), [
        'Content-Type' => 'application/manifest+json',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('pwa.manifest');

Route::get('/favicon.ico', function () {
    return response()->file(public_path('favicon.ico'), [
        'Content-Type' => 'image/x-icon',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('pwa.favicon');

Route::get('/icons/{asset}', function (string $asset) {
    $allowedAssets = [
        'apple-touch-icon.png',
        'favicon-16x16.png',
        'favicon-32x32.png',
        'icon-192.png',
        'icon-512.png',
    ];

    abort_unless(in_array($asset, $allowedAssets, true), 404);

    $path = public_path('icons/'.$asset);

    abort_unless(File::exists($path), 404);

    return response()->file($path, [
        'Content-Type' => 'image/png',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->where('asset', '[A-Za-z0-9._-]+')->name('pwa.icon');

Route::get('/', [PublicSiteController::class, 'home'])->name('public.home');
Route::get('/{page}', [PublicSiteController::class, 'show'])
    ->whereIn('page', ['clube', 'competicao', 'treinos', 'noticias', 'calendario', 'parceiros', 'contactos', 'junta-te', 'inscricao', 'privacidade'])
    ->name('public.page');
Route::post('/junta-te', [PublicFormSubmissionController::class, 'contact'])
    ->middleware('throttle:5,1')
    ->name('public.contact.store');
Route::post('/inscricao', [PublicFormSubmissionController::class, 'registration'])
    ->middleware('throttle:5,1')
    ->name('public.registration.store');
