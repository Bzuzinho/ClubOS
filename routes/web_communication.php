<?php

declare(strict_types=1);

use App\Http\Controllers\ComunicacaoController;
use App\Http\Controllers\Communication\CommunicationAlertController;
use App\Http\Controllers\Communication\CommunicationCampaignController;
use App\Http\Controllers\Communication\CommunicationDeliveryController;
use App\Http\Controllers\Communication\CommunicationSegmentController;
use App\Http\Controllers\Communication\CommunicationTemplateController;
use Illuminate\Support\Facades\Route;

Route::get('/comunicacao', [ComunicacaoController::class, 'index'])->middleware('module.access:comunicacao')->name('comunicacao.index');

Route::post('/comunicacao/campaigns', [CommunicationCampaignController::class, 'store'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.store');
Route::put('/comunicacao/campaigns/{campaign}', [CommunicationCampaignController::class, 'update'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.update');
Route::post('/comunicacao/campaigns/{campaign}/duplicate', [CommunicationCampaignController::class, 'duplicate'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.duplicate');
Route::post('/comunicacao/campaigns/{campaign}/send', [CommunicationCampaignController::class, 'send'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.send');
Route::post('/comunicacao/campaigns/{campaign}/schedule', [CommunicationCampaignController::class, 'schedule'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.schedule');
Route::post('/comunicacao/campaigns/{campaign}/cancel', [CommunicationCampaignController::class, 'cancel'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.cancel');
Route::delete('/comunicacao/campaigns/{campaign}', [CommunicationCampaignController::class, 'destroy'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.destroy');
Route::post('/comunicacao/campaigns/send-individual', [CommunicationCampaignController::class, 'sendIndividual'])->middleware('module.access:comunicacao')->name('comunicacao.campaigns.sendIndividual');

Route::get('/comunicacao/deliveries', [CommunicationDeliveryController::class, 'index'])->middleware('module.access:comunicacao')->name('comunicacao.deliveries.index');

Route::get('/comunicacao/templates', [CommunicationTemplateController::class, 'index'])->middleware('module.access:comunicacao')->name('comunicacao.templates.index');
Route::post('/comunicacao/templates', [CommunicationTemplateController::class, 'store'])->middleware('module.access:comunicacao')->name('comunicacao.templates.store');
Route::put('/comunicacao/templates/{template}', [CommunicationTemplateController::class, 'update'])->middleware('module.access:comunicacao')->name('comunicacao.templates.update');
Route::post('/comunicacao/templates/{template}/duplicate', [CommunicationTemplateController::class, 'duplicate'])->middleware('module.access:comunicacao')->name('comunicacao.templates.duplicate');
Route::post('/comunicacao/templates/{template}/toggle', [CommunicationTemplateController::class, 'toggle'])->middleware('module.access:comunicacao')->name('comunicacao.templates.toggle');
Route::delete('/comunicacao/templates/{template}', [CommunicationTemplateController::class, 'destroy'])->middleware('module.access:comunicacao')->name('comunicacao.templates.destroy');

Route::get('/comunicacao/segments', [CommunicationSegmentController::class, 'index'])->middleware('module.access:comunicacao')->name('comunicacao.segments.index');
Route::post('/comunicacao/segments', [CommunicationSegmentController::class, 'store'])->middleware('module.access:comunicacao')->name('comunicacao.segments.store');
Route::put('/comunicacao/segments/{segment}', [CommunicationSegmentController::class, 'update'])->middleware('module.access:comunicacao')->name('comunicacao.segments.update');
Route::delete('/comunicacao/segments/{segment}', [CommunicationSegmentController::class, 'destroy'])->middleware('module.access:comunicacao')->name('comunicacao.segments.destroy');

Route::get('/comunicacao/alerts', [CommunicationAlertController::class, 'index'])->middleware('module.access:comunicacao')->name('comunicacao.alerts.index');
Route::post('/comunicacao/alerts/mark-read', [CommunicationAlertController::class, 'markRead'])->middleware('module.access:comunicacao')->name('comunicacao.alerts.markRead');
Route::post('/comunicacao/alerts/mark-unread', [CommunicationAlertController::class, 'markUnread'])->middleware('module.access:comunicacao')->name('comunicacao.alerts.markUnread');
Route::post('/comunicacao/alerts/mark-all-read', [CommunicationAlertController::class, 'markAllRead'])->middleware('module.access:comunicacao')->name('comunicacao.alerts.markAllRead');
Route::delete('/comunicacao/alerts/{alert}', [CommunicationAlertController::class, 'destroy'])->middleware('module.access:comunicacao')->name('comunicacao.alerts.destroy');
