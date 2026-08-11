<?php

namespace App\Providers;

use App\Contracts\Members\MemberSportsIdentityProvider;
use App\Http\Middleware\PersistInAppNotificationPreference;
use App\Models\Event;
use App\Models\EventConvocation;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\SupplierPurchase;
use App\Observers\EventConvocationObserver;
use App\Observers\EventObserver;
use App\Observers\InvoiceObserver;
use App\Observers\LogisticsRequestObserver;
use App\Observers\MovementDocumentObserver;
use App\Observers\MovementObserver;
use App\Observers\SupplierPurchaseObserver;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Members\MemberSportsIdentityService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserTypeAccessControlService::class);
        $this->app->bind(MemberSportsIdentityProvider::class, MemberSportsIdentityService::class);
    }

    public function boot(): void
    {
        $this->app['router']->pushMiddlewareToGroup('web', PersistInAppNotificationPreference::class);
        $this->loadRoutesFrom(base_path('routes/desportivo_configuration.php'));
        $this->loadRoutesFrom(base_path('routes/desportivo_structure.php'));
        $this->loadRoutesFrom(base_path('routes/desportivo_member_contract.php'));
        $this->loadRoutesFrom(base_path('routes/member_documents.php'));

        Event::observe(EventObserver::class);
        EventConvocation::observe(EventConvocationObserver::class);
        Invoice::observe(InvoiceObserver::class);
        LogisticsRequest::observe(LogisticsRequestObserver::class);
        Movement::observe(MovementObserver::class);
        MovementDocument::observe(MovementDocumentObserver::class);
        SupplierPurchase::observe(SupplierPurchaseObserver::class);
    }
}
