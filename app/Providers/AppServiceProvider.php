<?php

namespace App\Providers;

use App\Contracts\Communication\SportsCommunicationGateway;
use App\Contracts\Desportivo\SportsAudienceProvider;
use App\Contracts\Financeiro\CompetitionFinanceGateway;
use App\Contracts\Logistica\SportsLogisticsGateway;
use App\Contracts\Members\MemberSportsIdentityProvider;
use App\Http\Controllers\Desportivo\SportsPlanningWorkspaceController;
use App\Http\Middleware\EnforceSportsLegacyCutover;
use App\Http\Middleware\PersistInAppNotificationPreference;
use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\EventConvocation;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\SupplierPurchase;
use App\Observers\ConvocationGroupPublicationObserver;
use App\Observers\EventConvocationObserver;
use App\Observers\EventObserver;
use App\Observers\InvoiceObserver;
use App\Observers\LogisticsRequestObserver;
use App\Observers\MovementDocumentObserver;
use App\Observers\MovementObserver;
use App\Observers\SupplierPurchaseObserver;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Communication\SportsCommunicationGatewayService;
use App\Services\Desportivo\CanonicalSportsAudienceService;
use App\Services\Financeiro\CompetitionFinancialObligationService;
use App\Services\Logistica\SportsLogisticsGatewayService;
use App\Services\Members\MemberSportsIdentityService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserTypeAccessControlService::class);
        $this->app->bind(MemberSportsIdentityProvider::class, MemberSportsIdentityService::class);
        $this->app->bind(SportsAudienceProvider::class, CanonicalSportsAudienceService::class);
        $this->app->bind(CompetitionFinanceGateway::class, CompetitionFinancialObligationService::class);
        $this->app->bind(SportsCommunicationGateway::class, SportsCommunicationGatewayService::class);
        $this->app->bind(SportsLogisticsGateway::class, SportsLogisticsGatewayService::class);
    }

    public function boot(): void
    {
        $this->app['router']->pushMiddlewareToGroup('web', EnforceSportsLegacyCutover::class);
        $this->app['router']->pushMiddlewareToGroup('web', PersistInAppNotificationPreference::class);
        $this->loadRoutesFrom(base_path('routes/desportivo_configuration.php'));
        $this->loadRoutesFrom(base_path('routes/desportivo_structure.php'));
        $this->loadRoutesFrom(base_path('routes/desportivo_planning.php'));
        $this->loadRoutesFrom(base_path('routes/desportivo_member_contract.php'));
        $this->loadRoutesFrom(base_path('routes/desportivo_communication_logistics.php'));
        $this->loadRoutesFrom(base_path('routes/member_documents.php'));

        if (! $this->app->routesAreCached()) {
            // routes/web.php is loaded after AppServiceProvider::boot in Laravel 11.
            // Register the canonical Planning GET once the application has booted so
            // it replaces the historical DesportivoController handler by URI/name.
            $this->app->booted(function (): void {
                Route::middleware(['web', 'auth', 'verified', 'module.access:desportivo'])
                    ->get('/desportivo/planeamento', [SportsPlanningWorkspaceController::class, 'index'])
                    ->middleware('permission.access:desportivo.planeamento,view')
                    ->name('desportivo.planeamento');
            });
        }

        Event::observe(EventObserver::class);
        EventConvocation::observe(EventConvocationObserver::class);
        ConvocationGroup::observe(ConvocationGroupPublicationObserver::class);
        Invoice::observe(InvoiceObserver::class);
        LogisticsRequest::observe(LogisticsRequestObserver::class);
        Movement::observe(MovementObserver::class);
        MovementDocument::observe(MovementDocumentObserver::class);
        SupplierPurchase::observe(SupplierPurchaseObserver::class);
    }
}
