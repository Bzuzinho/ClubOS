<?php

declare(strict_types=1);

namespace Tests\Feature\Routing;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Financeiro\FiscalDocumentRequestController;
use App\Services\Routing\RouteTopologyAuditService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WebRouteTopologyAuditTest extends TestCase
{
    public function test_web_route_topology_matches_the_reviewed_h25a_contract(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();

        $this->assertSame('web-route-topology-v1', $report['version']);
        $this->assertTrue($report['read_only']);
        $this->assertTrue($report['summary']['contract_matches_baseline']);
        $this->assertSame(1, $report['summary']['fallback_route_count']);
        $this->assertFalse($report['summary']['fallback_registered_last']);
        $this->assertSame('public.custom-page', $report['contract']['fallback_name']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $report['contract']['hash']);
        $this->assertSame(36, $report['summary']['modular_route_file_count']);
        $this->assertSame(36, $report['summary']['loaded_modular_route_file_count']);
        $this->assertSame(23, $report['summary']['legacy_redirect_count']);
        $this->assertSame(0, $report['summary']['source_literal_duplicate_candidate_count']);
        $this->assertSame(0, $report['summary']['source_literal_duplicate_reviewed_count']);
        $this->assertSame(0, $report['summary']['source_literal_duplicate_unclassified_count']);
        $this->assertSame(2, $report['summary']['retired_shadowed_alias_count']);
        $this->assertSame(0, $report['summary']['retired_shadowed_alias_reference_count']);
        $this->assertArrayNotHasKey('store.front.index', $report['contract']['named_routes']);
        $this->assertArrayNotHasKey('configuracoes.club.update', $report['contract']['named_routes']);
        $this->assertArrayHasKey('loja.index', $report['contract']['named_routes']);
        $this->assertArrayHasKey('configuracoes.clube.update', $report['contract']['named_routes']);
        $this->assertSame('loja', $report['contract']['named_routes']['loja.index']['uri']);
        $this->assertSame('configuracoes/clube', $report['contract']['named_routes']['configuracoes.clube.update']['uri']);
        $classifications = collect($report['duplicates']['reviewed_source_classifications'])
            ->keyBy(fn (array $candidate): string => $candidate['method'].' '.$candidate['uri']);
        $this->assertCount(0, $classifications);
        $retiredAliases = collect($report['duplicates']['retired_shadowed_aliases'])->keyBy('retired_name');
        $this->assertSame('loja.index', $retiredAliases['store.front.index']['effective_name']);
        $this->assertSame('configuracoes.clube.update', $retiredAliases['configuracoes.club.update']['effective_name']);
        $this->assertSame([], $report['duplicates']['retired_shadowed_alias_references']);
        $this->assertTrue($report['interpretation']['diagnostic_only']);
        $this->assertTrue($report['interpretation']['no_routes_changed']);
        $this->assertTrue($report['interpretation']['all_source_literal_duplicate_candidates_reviewed']);
        $this->assertTrue($report['interpretation']['retired_shadowed_aliases_have_zero_first_party_references']);
    }

    public function test_authenticated_dashboard_route_is_loaded_from_the_dedicated_module_without_access_drift(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $dashboardRoutes = File::get(base_path('routes/web_dashboard.php'));
        $route = Route::getRoutes()->getByName('dashboard');

        $this->assertTrue($routeFiles['routes/web_dashboard.php']['loaded']);
        $this->assertSame(1, $routeFiles['routes/web_dashboard.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_dashboard.php';", $webRoutes);
        $this->assertStringNotContainsString('DashboardController::class', $webRoutes);
        $this->assertStringContainsString("Route::get('/dashboard', [DashboardController::class, 'index'])", $dashboardRoutes);
        $this->assertStringNotContainsString("->middleware('module.access:inicio')", $dashboardRoutes);

        $this->assertNotNull($route);
        $this->assertSame('dashboard', $route->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertSame(DashboardController::class.'@index', $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('verified', $route->gatherMiddleware());
        $this->assertNotContains('module.access:inicio', $route->gatherMiddleware());
    }

    public function test_fiscal_document_request_index_route_is_loaded_from_the_dedicated_module_without_access_drift(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $fiscalRequestRoutes = File::get(base_path('routes/web_finance_fiscal_request_index.php'));
        $route = Route::getRoutes()->getByName('financeiro.fiscal-document-requests.index');

        $this->assertTrue($routeFiles['routes/web_finance_fiscal_request_index.php']['loaded']);
        $this->assertSame(1, $routeFiles['routes/web_finance_fiscal_request_index.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_finance_fiscal_request_index.php';", $webRoutes);
        $this->assertStringNotContainsString('FiscalDocumentRequestController::class', $webRoutes);
        $this->assertStringContainsString("Route::get('financeiro/fiscal-document-requests', [FiscalDocumentRequestController::class, 'index'])", $fiscalRequestRoutes);
        $this->assertStringContainsString("->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,view'])", $fiscalRequestRoutes);
        $this->assertStringContainsString("->name('financeiro.fiscal-document-requests.index');", $fiscalRequestRoutes);

        $this->assertNotNull($route);
        $this->assertSame('financeiro/fiscal-document-requests', $route->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertSame(FiscalDocumentRequestController::class.'@index', $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('verified', $route->gatherMiddleware());
        $this->assertContains('module.access:financeiro', $route->gatherMiddleware());
        $this->assertContains('permission.access:financeiro.dashboard,view', $route->gatherMiddleware());
    }

    public function test_public_pwa_website_and_form_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $publicRoutes = File::get(base_path('routes/web_public.php'));

        $this->assertTrue($routeFiles['routes/web_public.php']['loaded']);
        $this->assertSame(7, $routeFiles['routes/web_public.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_public.php';", $webRoutes);
        $this->assertStringNotContainsString('PublicFormSubmissionController::class', $webRoutes);
        $this->assertStringNotContainsString('File::exists', $webRoutes);
        $this->assertStringContainsString('PublicSiteController::class', $webRoutes);
        $this->assertStringContainsString("->name('pwa.manifest');", $publicRoutes);
        $this->assertStringContainsString("->name('pwa.favicon');", $publicRoutes);
        $this->assertStringContainsString("->where('asset', '[A-Za-z0-9._-]+')->name('pwa.icon');", $publicRoutes);
        $this->assertStringContainsString("->name('public.home');", $publicRoutes);
        $this->assertStringContainsString("->name('public.page');", $publicRoutes);
        $this->assertStringContainsString("->name('public.contact.store');", $publicRoutes);
        $this->assertStringContainsString("->name('public.registration.store');", $publicRoutes);
        $this->assertSame(2, substr_count($publicRoutes, "->middleware('throttle:5,1')"));
        $this->assertStringContainsString("Route::middleware(['auth', 'verified'])", $webRoutes);
        $this->assertStringContainsString("Route::fallback([PublicSiteController::class, 'custom'])", $webRoutes);
    }

    public function test_settings_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $settingsRoutes = File::get(base_path('routes/web_settings.php'));

        $this->assertTrue($routeFiles['routes/web_settings.php']['loaded']);
        $this->assertSame(68, $routeFiles['routes/web_settings.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_settings.php';", $webRoutes);
        $this->assertStringNotContainsString('ConfiguracoesController::class', $webRoutes);
        $this->assertStringNotContainsString('ConfiguracoesDesportivoController::class', $webRoutes);
        $this->assertStringContainsString("Route::middleware('module.access:configuracoes')", $settingsRoutes);
        $this->assertStringContainsString("->name('configuracoes');", $settingsRoutes);
        $this->assertStringContainsString("->name('configuracoes.clube.update');", $settingsRoutes);
        $this->assertStringContainsString("->name('configuracoes.desportivo.index');", $settingsRoutes);
    }

    public function test_administrative_website_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $websiteRoutes = File::get(base_path('routes/web_website.php'));

        $this->assertTrue($routeFiles['routes/web_website.php']['loaded']);
        $this->assertSame(17, $routeFiles['routes/web_website.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_website.php';", $webRoutes);
        $this->assertStringNotContainsString('WebsiteController::class', $webRoutes);
        $this->assertStringNotContainsString('WebsitePageController::class', $webRoutes);
        $this->assertStringNotContainsString('WebsiteMediaController::class', $webRoutes);
        $this->assertStringContainsString('PublicSiteController::class', $webRoutes);
        $this->assertStringNotContainsString('PublicFormSubmissionController::class', $webRoutes);
        $this->assertStringNotContainsString('PublicSiteController::class', $websiteRoutes);
        $this->assertStringNotContainsString('PublicFormSubmissionController::class', $websiteRoutes);
        $this->assertStringContainsString("Route::prefix('website')", $websiteRoutes);
        $this->assertStringContainsString("->middleware('module.access:website')", $websiteRoutes);
        $this->assertStringContainsString("->name('website.index');", $websiteRoutes);
        $this->assertStringContainsString("->name('website.pages.update');", $websiteRoutes);
        $this->assertStringContainsString("->name('website.legacy-redirect');", $websiteRoutes);
    }

    public function test_administrative_member_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $memberRoutes = File::get(base_path('routes/web_members.php'));

        $this->assertTrue($routeFiles['routes/web_members.php']['loaded']);
        $this->assertTrue($routeFiles['routes/member_documents.php']['loaded']);
        $this->assertSame(13, $routeFiles['routes/web_members.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_members.php';", $webRoutes);
        $this->assertStringNotContainsString('MembrosController::class', $webRoutes);
        $this->assertStringNotContainsString('MembrosImportController::class', $webRoutes);
        $this->assertStringNotContainsString('MemberFamilyRelationsController::class', $webRoutes);
        $this->assertStringNotContainsString('DocumentosMembrosController::class', $webRoutes);
        $this->assertStringContainsString("Route::resource('membros', MembrosController::class)", $memberRoutes);
        $this->assertStringContainsString("->name('membros.import.store');", $memberRoutes);
        $this->assertStringContainsString("->name('membros.familia.membros.update');", $memberRoutes);
        $this->assertStringContainsString("->name('membros.documentos.store');", $memberRoutes);
        $this->assertStringContainsString("->name('membros.send-access-email');", $memberRoutes);
        $this->assertStringNotContainsString('EventosController::class', $memberRoutes);
    }

    public function test_administrative_event_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $eventRoutes = File::get(base_path('routes/web_events.php'));

        $this->assertTrue($routeFiles['routes/web_events.php']['loaded']);
        $this->assertSame(5, $routeFiles['routes/web_events.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_events.php';", $webRoutes);
        $this->assertStringNotContainsString('EventosController::class', $webRoutes);
        $this->assertStringContainsString("Route::resource('eventos', EventosController::class)", $eventRoutes);
        $this->assertStringContainsString("->name('eventos.participantes.add');", $eventRoutes);
        $this->assertStringContainsString("->name('eventos.participantes.remove');", $eventRoutes);
        $this->assertStringContainsString("->name('eventos.participantes.update');", $eventRoutes);
        $this->assertStringContainsString("->name('eventos.stats');", $eventRoutes);
        $this->assertStringNotContainsString('DesportivoController::class', $eventRoutes);
    }

    public function test_administrative_sports_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $sportsRoutes = File::get(base_path('routes/web_sports.php'));

        $this->assertTrue($routeFiles['routes/web_sports.php']['loaded']);
        $this->assertSame(29, $routeFiles['routes/web_sports.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_sports.php';", $webRoutes);
        $this->assertStringNotContainsString('DesportivoController::class', $webRoutes);
        $this->assertStringContainsString("Route::prefix('desportivo')->middleware('module.access:desportivo')", $sportsRoutes);
        $this->assertStringContainsString("->name('desportivo.index');", $sportsRoutes);
        $this->assertStringContainsString("->name('desportivo.epoca.store');", $sportsRoutes);
        $this->assertStringContainsString("->name('desportivo.mesociclo.delete');", $sportsRoutes);
        $this->assertStringContainsString("->name('desportivo.treino.duplicate');", $sportsRoutes);
        $this->assertStringContainsString("->name('desportivo.treino.presencas.update');", $sportsRoutes);
        $this->assertStringContainsString("->name('desportivo.presencas.clear-all');", $sportsRoutes);
        $this->assertStringContainsString("->name('desportivo.cais.metrics.store');", $sportsRoutes);
        $this->assertStringNotContainsString('FinanceiroController::class', $sportsRoutes);
    }

    public function test_core_administrative_finance_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $financeRoutes = File::get(base_path('routes/web_finance.php'));

        $this->assertTrue($routeFiles['routes/web_finance.php']['loaded']);
        $this->assertSame(30, $routeFiles['routes/web_finance.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_finance.php';", $webRoutes);
        $this->assertStringNotContainsString('RelatoriosFinanceirosController::class', $webRoutes);
        $this->assertStringNotContainsString('ReceiptImportController::class', $webRoutes);
        $this->assertStringNotContainsString('BankReconciliationSuggestionController::class', $webRoutes);
        $this->assertStringNotContainsString('BankReconciliationAliasController::class', $webRoutes);
        $this->assertStringNotContainsString('BankReconciliationAuditController::class', $webRoutes);
        $this->assertStringNotContainsString('FinanceiroController::class', $webRoutes);
        $this->assertStringContainsString("->name('relatorios-financeiros.index');", $financeRoutes);
        $this->assertStringContainsString("->name('financeiro.receipt-imports.items.update');", $financeRoutes);
        $this->assertStringContainsString("->name('financeiro.bank-aliases.index');", $financeRoutes);
        $this->assertStringContainsString("Route::resource('financeiro', FinanceiroController::class)", $financeRoutes);
        $this->assertStringContainsString("->name('monthly-fees.generate');", $financeRoutes);
        $this->assertStringContainsString("->name('bank-reconciliation-audit.export-summary');", $financeRoutes);
        $this->assertStringContainsString("->name('bank-reconciliation-suggestions.confirm');", $financeRoutes);
        $this->assertStringContainsString("->name('bank-aliases.destroy');", $financeRoutes);
        $this->assertStringNotContainsString('LogisticaController::class', $financeRoutes);
    }

    public function test_administrative_logistics_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $logisticsRoutes = File::get(base_path('routes/web_logistics.php'));

        $this->assertTrue($routeFiles['routes/web_logistics.php']['loaded']);
        $this->assertSame(15, $routeFiles['routes/web_logistics.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_logistics.php';", $webRoutes);
        $this->assertStringNotContainsString('LogisticaController::class', $webRoutes);
        $this->assertStringContainsString("Route::prefix('logistica')->middleware('module.access:logistica')", $logisticsRoutes);
        $this->assertStringContainsString("->name('logistica.index');", $logisticsRoutes);
        $this->assertStringContainsString("->name('logistica.requisicoes.approve');", $logisticsRoutes);
        $this->assertStringContainsString("->name('logistica.requisicoes.invoice');", $logisticsRoutes);
        $this->assertStringContainsString("->name('logistica.requisicoes.deliver');", $logisticsRoutes);
        $this->assertStringContainsString("->name('logistica.stock.movimentos.store');", $logisticsRoutes);
        $this->assertStringContainsString("->name('logistica.emprestimos.return');", $logisticsRoutes);
        $this->assertStringContainsString("->name('logistica.fornecedores.compras.destroy');", $logisticsRoutes);
        $this->assertStringNotContainsString('FinanceiroController::class', $logisticsRoutes);
    }

    public function test_administrative_store_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $storeRoutes = File::get(base_path('routes/web_store_admin.php'));

        $this->assertTrue($routeFiles['routes/web_store_admin.php']['loaded']);
        $this->assertSame(9, $routeFiles['routes/web_store_admin.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_store_admin.php';", $webRoutes);
        $this->assertStringNotContainsString('AdminLojaController::class', $webRoutes);
        $this->assertStringNotContainsString('AdminLojaProdutoController::class', $webRoutes);
        $this->assertStringNotContainsString('AdminLojaEncomendaController::class', $webRoutes);
        $this->assertStringNotContainsString('AdminLojaHeroController::class', $webRoutes);
        $this->assertStringContainsString("Route::prefix('admin/loja')->middleware('module.access:loja')->name('admin.loja.')", $storeRoutes);
        $this->assertStringContainsString("->name('index');", $storeRoutes);
        $this->assertStringContainsString("->name('produtos.index');", $storeRoutes);
        $this->assertStringContainsString("->name('produtos.create');", $storeRoutes);
        $this->assertStringContainsString("->name('produtos.edit');", $storeRoutes);
        $this->assertStringContainsString("->name('encomendas.show');", $storeRoutes);
        $this->assertStringContainsString("->name('hero.edit');", $storeRoutes);
        $this->assertStringNotContainsString('PatrocinosController::class', $storeRoutes);
    }

    public function test_administrative_sponsorship_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $sponsorshipRoutes = File::get(base_path('routes/web_sponsorships.php'));

        $this->assertTrue($routeFiles['routes/web_sponsorships.php']['loaded']);
        $this->assertSame(5, $routeFiles['routes/web_sponsorships.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_sponsorships.php';", $webRoutes);
        $this->assertStringNotContainsString('PatrocinosController::class', $webRoutes);
        $this->assertStringContainsString("Route::prefix('patrocinios')->middleware('module.access:patrocinios')", $sponsorshipRoutes);
        $this->assertStringContainsString("->name('patrocinios.integrations.index');", $sponsorshipRoutes);
        $this->assertStringContainsString("->name('patrocinios.integrations.retry');", $sponsorshipRoutes);
        $this->assertStringContainsString("->name('patrocinios.close');", $sponsorshipRoutes);
        $this->assertStringContainsString("->name('patrocinios.cancel');", $sponsorshipRoutes);
        $this->assertStringContainsString("Route::resource('patrocinios', PatrocinosController::class)->middleware('module.access:patrocinios');", $sponsorshipRoutes);
        $this->assertStringNotContainsString('ComunicacaoController::class', $sponsorshipRoutes);
    }

    public function test_administrative_communication_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $communicationRoutes = File::get(base_path('routes/web_communication.php'));

        $this->assertTrue($routeFiles['routes/web_communication.php']['loaded']);
        $this->assertSame(25, $routeFiles['routes/web_communication.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_communication.php';", $webRoutes);
        $this->assertStringNotContainsString('ComunicacaoController::class', $webRoutes);
        $this->assertStringNotContainsString('CommunicationCampaignController::class', $webRoutes);
        $this->assertStringNotContainsString('CommunicationDeliveryController::class', $webRoutes);
        $this->assertStringNotContainsString('CommunicationTemplateController::class', $webRoutes);
        $this->assertStringNotContainsString('CommunicationSegmentController::class', $webRoutes);
        $this->assertStringNotContainsString('CommunicationAlertController::class', $webRoutes);
        $this->assertStringContainsString("->name('comunicacao.index');", $communicationRoutes);
        $this->assertStringContainsString("->name('comunicacao.campaigns.sendIndividual');", $communicationRoutes);
        $this->assertStringContainsString("->name('comunicacao.deliveries.index');", $communicationRoutes);
        $this->assertStringContainsString("->name('comunicacao.templates.toggle');", $communicationRoutes);
        $this->assertStringContainsString("->name('comunicacao.segments.destroy');", $communicationRoutes);
        $this->assertStringContainsString("->name('comunicacao.alerts.destroy');", $communicationRoutes);
        $this->assertStringNotContainsString('CampanhasMarketingController::class', $communicationRoutes);
        $this->assertStringContainsString("require __DIR__.'/web_marketing.php';", $webRoutes);
    }

    public function test_administrative_marketing_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $communicationRoutes = File::get(base_path('routes/web_communication.php'));
        $marketingRoutes = File::get(base_path('routes/web_marketing.php'));

        $this->assertTrue($routeFiles['routes/web_marketing.php']['loaded']);
        $this->assertSame(1, $routeFiles['routes/web_marketing.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_marketing.php';", $webRoutes);
        $this->assertStringNotContainsString('CampanhasMarketingController::class', $webRoutes);
        $this->assertStringNotContainsString('CampanhasMarketingController::class', $communicationRoutes);
        $this->assertStringContainsString("Route::resource('campanhas-marketing', CampanhasMarketingController::class)", $marketingRoutes);
        $this->assertStringContainsString("->middleware('module.access:marketing');", $marketingRoutes);
        $this->assertStringNotContainsString('EquipasController::class', $marketingRoutes);
        $this->assertStringContainsString("require __DIR__.'/web_settings.php';", $webRoutes);
    }

    public function test_additional_administrative_sports_resources_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $sportsResourceRoutes = File::get(base_path('routes/web_sports_resources.php'));

        $this->assertTrue($routeFiles['routes/web_sports_resources.php']['loaded']);
        $this->assertSame(4, $routeFiles['routes/web_sports_resources.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_sports_resources.php';", $webRoutes);
        $this->assertStringNotContainsString('EquipasController::class', $webRoutes);
        $this->assertStringNotContainsString('MembrosEquipaController::class', $webRoutes);
        $this->assertStringNotContainsString('SessoesFormacaoController::class', $webRoutes);
        $this->assertStringNotContainsString('ConvocatoriasController::class', $webRoutes);
        $this->assertStringContainsString("Route::resource('equipas', EquipasController::class);", $sportsResourceRoutes);
        $this->assertStringContainsString("Route::resource('membros-equipa', MembrosEquipaController::class)->except(['index', 'create', 'show', 'edit']);", $sportsResourceRoutes);
        $this->assertStringContainsString("Route::resource('sessoes-formacao', SessoesFormacaoController::class);", $sportsResourceRoutes);
        $this->assertStringContainsString("Route::resource('convocatorias', ConvocatoriasController::class);", $sportsResourceRoutes);
        $this->assertStringNotContainsString('FinanceiroController::class', $sportsResourceRoutes);
        $this->assertStringContainsString("require __DIR__.'/web_finance_complementary.php';", $webRoutes);
    }

    public function test_complementary_administrative_finance_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $financeRoutes = File::get(base_path('routes/web_finance_complementary.php'));

        $this->assertTrue($routeFiles['routes/web_finance_complementary.php']['loaded']);
        $this->assertSame(36, $routeFiles['routes/web_finance_complementary.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_finance_complementary.php';", $webRoutes);
        $this->assertStringNotContainsString('TransacoesController::class', $webRoutes);
        $this->assertStringNotContainsString('CategoriasFinanceirasController::class', $webRoutes);
        $this->assertStringNotContainsString('FinanceiroController::class', $webRoutes);
        $this->assertStringNotContainsString('FiscalDocumentRequestController::class', $webRoutes);
        $this->assertStringContainsString('FiscalDocumentRequestController::class', $financeRoutes);
        $this->assertStringContainsString("Route::prefix('financeiro')->middleware('module.access:financeiro')", $financeRoutes);
        $this->assertStringContainsString("->name('transacoes.index');", $financeRoutes);
        $this->assertStringContainsString("->name('categorias-financeiras.destroy');", $financeRoutes);
        $this->assertStringContainsString("->name('financeiro.movimentos.documents.validate');", $financeRoutes);
        $this->assertStringContainsString("->name('financeiro.movimentos.notes.update');", $financeRoutes);
        $this->assertStringContainsString("->name('financeiro.extratos.criar-despesa');", $financeRoutes);
        $this->assertStringContainsString("->name('financeiro.invoices.fiscal-document-request.store');", $financeRoutes);
        $this->assertStringContainsString("->name('financeiro.fiscal-document-requests.destroy');", $financeRoutes);
        $this->assertStringNotContainsString('PublicSiteController::class', $financeRoutes);
        $this->assertStringContainsString("require __DIR__.'/web_compatibility.php';", $webRoutes);
    }

    public function test_compatibility_redirects_are_modular_and_have_no_first_party_consumers(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $redirects = collect($report['legacy']['redirects']);
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $appLayout = File::get(resource_path('js/Layouts/Spark/AppLayout.tsx'));

        $this->assertSame(0, $report['summary']['legacy_redirect_consumer_count']);
        $this->assertTrue($redirects->contains(
            fn (array $redirect): bool => $redirect['source'] === '/marketing'
                && $redirect['target'] === '/campanhas-marketing'
                && $redirect['declared_in'] === 'routes/web_compatibility.php',
        ));
        $this->assertTrue($redirects->contains(
            fn (array $redirect): bool => $redirect['source'] === '/settings'
                && $redirect['target'] === '/configuracoes'
                && $redirect['declared_in'] === 'routes/web_compatibility.php',
        ));
        $this->assertTrue($routeFiles['routes/web_compatibility.php']['loaded']);
        $this->assertTrue($routeFiles['routes/web_portal.php']['loaded']);
        $this->assertTrue($redirects->contains(
            fn (array $redirect): bool => $redirect['source'] === '/portal/loja'
                && $redirect['target'] === '/loja'
                && $redirect['declared_in'] === 'routes/web_portal.php',
        ));
        $this->assertStringContainsString("href: '/campanhas-marketing'", $appLayout);
        $this->assertStringContainsString('href="/configuracoes"', $appLayout);
        $this->assertStringNotContainsString("href: '/marketing'", $appLayout);
        $this->assertStringNotContainsString('href="/settings"', $appLayout);
    }

    public function test_artisan_command_writes_a_machine_readable_report_and_enforces_the_contract(): void
    {
        $path = storage_path('framework/testing/web-route-topology.json');
        File::delete($path);

        $this->artisan('routes:audit-web-topology', [
            '--report-path' => $path,
            '--fail-on-contract-drift' => true,
        ])->assertSuccessful();

        $payload = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['read_only']);
        $this->assertTrue($payload['summary']['contract_matches_baseline']);
        $this->assertNotEmpty($payload['contract']['signatures']);
    }
}
