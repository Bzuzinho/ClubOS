<?php

declare(strict_types=1);

use App\Http\Controllers\CampanhasMarketingController;
use Illuminate\Support\Facades\Route;

Route::resource('campanhas-marketing', CampanhasMarketingController::class)->middleware('module.access:marketing');
