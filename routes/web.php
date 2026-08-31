<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\Financeiro\FiscalDocumentRequestController;
use App\Http\Controllers\PublicFormSubmissionController;
use App\Http\Controllers\PublicSiteController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard — gate handled inside DashboardController (athlete vs admin dispatch).
    // Do not add module.access:inicio here, otherwise athlete/encarregado get 403
    // before the controller can render the personal dashboard.
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    require __DIR__.'/web_website.php';

    require __DIR__.'/web_portal.php';

    Route::get('financeiro/fiscal-document-requests', [FiscalDocumentRequestController::class, 'index'])
        ->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,view'])
        ->name('financeiro.fiscal-document-requests.index');

    require __DIR__.'/web_members.php';

    require __DIR__.'/web_events.php';

    require __DIR__.'/web_sports.php';

    require __DIR__.'/web_finance.php';

    require __DIR__.'/web_logistics.php';

    Route::post('financeiro/{financeiro}/apagar', [FinanceiroController::class, 'destroy'])
        ->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,delete'])
        ->name('financeiro.destroy.post');

    require __DIR__.'/web_store_admin.php';

    require __DIR__.'/web_sponsorships.php';

    require __DIR__.'/web_communication.php';

    require __DIR__.'/web_marketing.php';
    
    require __DIR__.'/web_settings.php';

    require __DIR__.'/web_sports_resources.php';

    require __DIR__.'/web_finance_complementary.php';
});

require __DIR__.'/web_compatibility.php';

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

// Custom public pages are a true fallback so named application routes always win,
// including when Laravel compiles and caches the route collection.
Route::fallback([PublicSiteController::class, 'custom'])
    ->name('public.custom-page');
