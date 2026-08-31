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
        $this->assertSame(37, $report['summary']['modular_route_file_count']);
        $this->assertSame(37, $report['summary']['loaded_modular_route_file_count']);
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

    public function test_finance_delete_compatibility_route_is_loaded_from_the_dedicated_module_without_access_drift(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $compatRoutes = File::get(base_path('routes/web_finance_delete_compat.php'));
        $route = Route::getRoutes()->getByName('financeiro.destroy.post');

        $this->assertTrue($routeFiles['routes/web_finance_delete_compat.php']['loaded']);
        $this->assertSame(1, $routeFiles['routes/web_finance_delete_compat.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_finance_delete_compat.php';", $webRoutes);
        $this->assertStringNotContainsString('FinanceiroController::class', $webRoutes);
        $this->assertStringContainsString("Route::post('financeiro/{financeiro}/apagar', [FinanceiroController::class, 'destroy'])", $compatRoutes);
        $this->assertStringContainsString("->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,delete'])", $compatRoutes);
        $this->assertStringContainsString("->name('financeiro.destroy.post');", $compatRoutes);

        $this->assertNotNull($route);
        $this->assertSame('financeiro/{financeiro}/apagar', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('verified', $route->gatherMiddleware());
        $this->assertContains('module.access:financeiro', $route->gatherMiddleware());
        $this->assertContains('permission.access:financeiro.dashboard,delete', $route->gatherMiddleware());
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
