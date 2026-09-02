<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Services\Financeiro\FiscalDocumentAuditService;
use App\Services\Financeiro\FiscalProvider\FiscalDocumentProviderAdapter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class FiscalOperationalReadinessCommand extends Command
{
    private const VERSION = 'h4-fiscal-operational-readiness-v1';

    private const MANUAL_MODE = 'manual_wintouch';

    protected $signature = 'finance:audit-fiscal-operational-readiness
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Guarda o relatorio JSON no caminho indicado}
        {--fail-on-not-ready : Falha se o contrato fiscal produtivo nao estiver pronto}';

    protected $description = 'Valida, sem alterar dados, o contrato produtivo de emissao fiscal manual Wintouch';

    public function __construct(
        private readonly FiscalDocumentAuditService $auditService,
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mode = trim((string) config('fiscal.operation_mode', self::MANUAL_MODE));
        $provider = trim((string) config('fiscal.provider', FiscalDocumentRequest::PROVIDER_WINTOUCH));
        $schema = $this->schemaDetected();
        $routes = $this->routeContract();
        $adapterProviders = $this->adapterProviders();
        $audit = $this->auditService->audit();
        $auditSummary = is_array($audit['summary'] ?? null) ? $audit['summary'] : [];
        $criticalCount = (int) ($auditSummary['critical_count'] ?? 0);

        $manualContractConfigured = $mode === self::MANUAL_MODE
            && $provider === FiscalDocumentRequest::PROVIDER_WINTOUCH;
        $automaticAdapterAbsent = $adapterProviders === [];
        $ready = $manualContractConfigured
            && $automaticAdapterAbsent
            && $schema['required_schema_present']
            && $routes['all_required_routes_present']
            && $criticalCount === 0;

        $payload = [
            'version' => self::VERSION,
            'read_only' => true,
            'contract' => [
                'operation_mode' => $mode,
                'provider' => $provider,
                'manual_mode_expected' => self::MANUAL_MODE,
                'manual_provider_expected' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
                'manual_contract_configured' => $manualContractConfigured,
                'automatic_provider_adapter_count' => count($adapterProviders),
                'automatic_provider_adapters' => $adapterProviders,
                'automatic_issue_enabled' => $mode === 'provider_api' && $adapterProviders !== [],
            ],
            'summary' => [
                'ready' => $ready,
                'critical_count' => $criticalCount,
                'warning_count' => (int) ($auditSummary['warning_count'] ?? 0),
                'pending_request_count' => (int) ($auditSummary['pending_request_count'] ?? 0),
                'pending_ready_for_external_issue_count' => (int) ($auditSummary['pending_ready_for_external_issue_count'] ?? 0),
                'issued_document_count' => (int) ($auditSummary['issued_document_count'] ?? 0),
                'total_external_documents_detected' => (int) ($auditSummary['total_external_documents_detected'] ?? 0),
                'total_fiscal_requests_scanned' => (int) ($auditSummary['total_fiscal_requests_scanned'] ?? 0),
            ],
            'schema_detected' => $schema,
            'route_contract' => $routes,
            'interpretation' => [
                'payment_and_fiscal_document_are_separate' => true,
                'manual_recording_confirms_an_external_document' => true,
                'wintouch_dll_execution_from_laravel_is_supported' => false,
                'provider_api_requires_explicit_mode_and_adapter' => true,
                'warnings_and_pending_requests_are_operational_queue' => true,
                'no_data_changed' => true,
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $reportPath = trim((string) $this->option('report-path'));

        if ($reportPath !== '') {
            $path = str_starts_with($reportPath, '/') ? $reportPath : base_path($reportPath);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $json.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($json);
        } else {
            $this->table(['Metrica', 'Valor'], [
                ['operation_mode', $mode],
                ['provider', $provider],
                ['automatic_provider_adapters', count($adapterProviders)],
                ['critical_findings', $criticalCount],
                ['warnings', (int) ($auditSummary['warning_count'] ?? 0)],
                ['pending_requests', (int) ($auditSummary['pending_request_count'] ?? 0)],
                ['ready', $ready ? 'true' : 'false'],
            ]);
        }

        return (bool) $this->option('fail-on-not-ready') && ! $ready
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** @return array<string,bool> */
    private function schemaDetected(): array
    {
        $required = [
            'fiscal_document_requests' => [
                'invoice_id',
                'provider',
                'document_type',
                'status',
                'amount',
                'external_document_number',
                'external_document_id',
                'issued_at',
                'deleted_at',
            ],
            'invoices' => [
                'estado_pagamento',
                'valor_total',
                'numero_recibo',
                'recibo_emitido_em',
            ],
            'payments' => ['status', 'amount', 'deleted_at'],
            'payment_allocations' => ['payment_id', 'invoice_id', 'amount', 'status', 'deleted_at'],
        ];

        $detected = [];
        $allPresent = true;

        foreach ($required as $table => $columns) {
            $tablePresent = Schema::hasTable($table);
            $detected[$table.'_table_present'] = $tablePresent;
            $allPresent = $allPresent && $tablePresent;

            foreach ($columns as $column) {
                $present = $tablePresent && Schema::hasColumn($table, $column);
                $detected[$table.'_'.$column.'_present'] = $present;
                $allPresent = $allPresent && $present;
            }
        }

        return [
            ...$detected,
            'required_schema_present' => $allPresent,
        ];
    }

    /** @return array{required_routes:list<string>, missing_routes:list<string>, all_required_routes_present:bool} */
    private function routeContract(): array
    {
        $requiredRoutes = [
            'financeiro.fiscal-document-requests.index',
            'financeiro.invoices.fiscal-document-request.store',
            'financeiro.fiscal-document-requests.mark-in-progress',
            'financeiro.fiscal-document-requests.mark-issued',
            'financeiro.fiscal-document-requests.mark-cancelled',
            'financeiro.fiscal-document-requests.mark-error-data',
            'financeiro.fiscal-document-requests.destroy',
        ];
        $missingRoutes = array_values(array_filter(
            $requiredRoutes,
            static fn (string $route): bool => ! Route::has($route),
        ));

        return [
            'required_routes' => $requiredRoutes,
            'missing_routes' => $missingRoutes,
            'all_required_routes_present' => $missingRoutes === [],
        ];
    }

    /** @return list<string> */
    private function adapterProviders(): array
    {
        $providers = [];

        if ($this->container->bound(FiscalDocumentProviderAdapter::class)) {
            $adapter = $this->container->make(FiscalDocumentProviderAdapter::class);
            if ($adapter instanceof FiscalDocumentProviderAdapter) {
                $providers[] = $adapter->provider();
            }
        }

        foreach ($this->container->tagged('financeiro.fiscal_document_provider_adapters') as $adapter) {
            if ($adapter instanceof FiscalDocumentProviderAdapter) {
                $providers[] = $adapter->provider();
            }
        }

        $providers = array_values(array_unique(array_filter(array_map('trim', $providers))));
        sort($providers);

        return $providers;
    }
}
