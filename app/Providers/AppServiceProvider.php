<?php

namespace App\Providers;

use App\Http\Middleware\PersistInAppNotificationPreference;
use App\Services\AccessControl\UserTypeAccessControlService;
use Illuminate\Support\ServiceProvider;
use App\Models\Event;
use App\Models\EventConvocation;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\SupplierPurchase;
use App\Observers\EventObserver;
use App\Observers\EventConvocationObserver;
use App\Observers\InvoiceObserver;
use App\Observers\LogisticsRequestObserver;
use App\Observers\MovementObserver;
use App\Observers\MovementDocumentObserver;
use App\Observers\SupplierPurchaseObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(UserTypeAccessControlService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app['router']->pushMiddlewareToGroup('web', PersistInAppNotificationPreference::class);

        Event::observe(EventObserver::class);
        EventConvocation::observe(EventConvocationObserver::class);
        Invoice::observe(InvoiceObserver::class);
        LogisticsRequest::observe(LogisticsRequestObserver::class);
        Movement::observe(MovementObserver::class);
        MovementDocument::observe(MovementDocumentObserver::class);
        SupplierPurchase::observe(SupplierPurchaseObserver::class);
    }
}
