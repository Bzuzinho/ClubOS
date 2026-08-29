<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Backward compatibility redirects (English → Portuguese).
// Keep these routes in their original registration position until the audit
// reports zero external consumers and retirement is explicitly reviewed.
Route::redirect('/members', '/membros', 301);
Route::redirect('/members/{id}', '/membros/{id}', 301);
Route::redirect('/events', '/eventos', 301);
Route::redirect('/events/{id}', '/eventos', 301);
Route::redirect('/sports', '/desportivo', 301);
Route::redirect('/sports/{id}', '/desportivo/{id}', 301);
Route::redirect('/financial', '/financeiro', 301);
Route::redirect('/shop', '/loja', 301);
Route::redirect('/shop/{id}', '/loja/{id}', 301);
Route::redirect('/sponsorships', '/patrocinios', 301);
Route::redirect('/sponsorships/{id}', '/patrocinios/{id}', 301);
Route::redirect('/communication', '/comunicacao', 301);
Route::redirect('/communication/{id}', '/comunicacao/{id}', 301);
Route::redirect('/marketing', '/campanhas-marketing', 301);
Route::redirect('/marketing/{id}', '/campanhas-marketing/{id}', 301);
Route::redirect('/settings', '/configuracoes', 301);
Route::redirect('/settings/desportivo', '/configuracoes/desportivo', 301);
Route::redirect('/teams', '/equipas', 301);
Route::redirect('/teams/{id}', '/equipas/{id}', 301);
Route::redirect('/team-members', '/membros-equipa', 301);
Route::redirect('/training-sessions', '/sessoes-formacao', 301);
Route::redirect('/call-ups', '/convocatorias', 301);
