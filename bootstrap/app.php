<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\AppServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Console\Commands\DatabaseSafety\MigrateFreshGuardedCommand::class,
        \App\Console\Commands\DatabaseSafety\MigrateRefreshGuardedCommand::class,
        \App\Console\Commands\DatabaseSafety\MigrateResetGuardedCommand::class,
        \App\Console\Commands\DatabaseSafety\DbWipeGuardedCommand::class,
        \App\Console\Commands\SetupCommand::class,
        \App\Console\Commands\DevResetDatabaseCommand::class,
        \App\Console\Commands\BackfillFinanceiroIntegracoes::class,
        \App\Console\Commands\AuditarTrainingSessions::class,
        \App\Console\Commands\MigrarPresencasLegacy::class,
        \App\Console\Commands\BenchmarkModulesPerformance::class,
        \App\Console\Commands\CatalogBackfillStoreProductsIntoProducts::class,
        \App\Console\Commands\CatalogAuditBackfillMappings::class,
        \App\Console\Commands\CatalogResetBackfillFixtures::class,
        \App\Console\Commands\Inventario\StockIntegrityAuditCommand::class,
        \App\Console\Commands\Inventario\StockMismatchInspectionCommand::class,
        \App\Console\Commands\Inventario\StockCorrectionPreflightCommand::class,
        \App\Console\Commands\Inventario\StockCorrectionApplicationCommand::class,
        \App\Console\Commands\Inventario\OrphanStockMovementInspectionCommand::class,
        \App\Console\Commands\Inventario\OrphanStockMovementResolutionCommand::class,
        \App\Console\Commands\Inventario\StockSourceOfTruthAuditCommand::class,
        \App\Console\Commands\Inventario\SupplierPurchaseStockAuditCommand::class,
        \App\Console\Commands\Inventario\LogisticsRequestStockAuditCommand::class,
        \App\Console\Commands\Inventario\EquipmentLoanStockAuditCommand::class,
        \App\Console\Commands\Inventario\EquipmentLoanStockInspectionCommand::class,
        \App\Console\Commands\Inventario\EquipmentLoanStockResolutionCommand::class,
        \App\Console\Commands\Inventario\StoreLogisticsStockAuditCommand::class,
        \App\Console\Commands\Pessoas\GrantPlatformAccessCommand::class,
        \App\Console\Commands\Pessoas\MemberModelAuditCommand::class,
        \App\Console\Commands\Pessoas\MemberPlatformAccessInspectionCommand::class,
        \App\Console\Commands\Pessoas\RevokePlatformAccessCommand::class,
        \App\Console\Commands\System\PerformanceAuditCommand::class,
        \App\Console\Commands\ReleaseVisibleInvoiceCommunications::class,
        \App\Console\Commands\SyncPermissionNodes::class,
        \App\Console\Commands\GenerateMonthlyFeesCommand::class,
        \App\Console\Commands\ActivateDueMonthlyFeesCommand::class,
        \App\Console\Commands\Financeiro\AccountCreditAuditCommand::class,
        \App\Console\Commands\Financeiro\ApplyAccountCreditCommand::class,
        \App\Console\Commands\Financeiro\AuditMemberCostCentersCommand::class,
        \App\Console\Commands\Financeiro\AuditMemberCurrentAccountsCommand::class,
        \App\Console\Commands\Financeiro\AuditLegacyCleanupReadinessCommand::class,
        \App\Console\Commands\Financeiro\BankReconciliationAuditCommand::class,
        \App\Console\Commands\Financeiro\AuditMemberMonthlyFeesCommand::class,
        \App\Console\Commands\Financeiro\AuditPendingMonthlyFeeRequirementsCommand::class,
        \App\Console\Commands\Financeiro\ExternalFiscalReceiptRecordingCommand::class,
        \App\Console\Commands\Financeiro\FinancialIntegrationAuditCommand::class,
        \App\Console\Commands\Financeiro\FinancialTimelineAnomalyInspectionCommand::class,
        \App\Console\Commands\Financeiro\FinancialTimelineAuditCommand::class,
        \App\Console\Commands\Financeiro\FiscalDocumentAuditCommand::class,
        \App\Console\Commands\Financeiro\FiscalDocumentIssuePreflightCommand::class,
        \App\Console\Commands\Financeiro\FiscalDocumentRequestProcessingCommand::class,
        \App\Console\Commands\Financeiro\FiscalRequestAnomalyInspectionCommand::class,
        \App\Console\Commands\Financeiro\InvoiceObligationAuditCommand::class,
        \App\Console\Commands\Financeiro\LegacyMonthlyInvoiceClassificationCommand::class,
        \App\Console\Commands\Financeiro\MonthlyFeeFutureInvoiceAuditCommand::class,
        \App\Console\Commands\Financeiro\PaymentAuditCommand::class,
        \App\Console\Commands\Financeiro\PaymentReversalAuditCommand::class,
        \App\Console\Commands\Financeiro\PendingFiscalRequestInspectionCommand::class,
        \App\Console\Commands\Financeiro\UnallocatedPaymentAuditCommand::class,
        \App\Console\Commands\Financeiro\StaleFiscalRequestCleanupCommand::class,
        \App\Console\Commands\Financeiro\NormalizeLegacyManualMovementSignCommand::class,
        \App\Console\Commands\Financeiro\BackfillMemberCostCentersCommand::class,
        \App\Console\Commands\Financeiro\BackfillMemberMonthlyFeesCommand::class,
        \App\Console\Commands\Financeiro\CreateAccountCreditFromPaymentCommand::class,
        \App\Console\Commands\AuditManualCurrentAccount::class,
        \App\Console\Commands\MigrateManualCurrentAccount::class,
        \App\Console\Commands\Members\AuditMemberDataFallbackCommand::class,
        \App\Console\Commands\Members\BackfillMemberContactCommand::class,
        \App\Console\Commands\Members\AuditUsersLegacyFieldMapCommand::class,
        \App\Console\Commands\Members\AuditUsersLegacyWriteGuardCommand::class,
        \App\Console\Commands\Members\AuditUsersLegacyReadCommand::class,
        \App\Console\Commands\Members\AuditUsersLegacyFieldRemovalReadinessCommand::class,
        \App\Console\Commands\Members\AuditUsersLegacyBackfillValidationCommand::class,
        \App\Console\Commands\Members\AuditUsersLegacyCanonicalTargetsCommand::class,
        \App\Console\Commands\Members\AuditMemberTypesCommand::class,
        \App\Console\Commands\Members\AuditLegacyCleanupReadinessCommand::class,
        \App\Console\Commands\Members\AuditPendingMedicalCertificateBackfillCommand::class,
        \App\Console\Commands\Members\PreflightUsersLegacyOnlyBackfillCommand::class,
        \App\Console\Commands\Members\BackfillUsersLegacyOnlyFieldsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Trust all proxies for Codespaces
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'module.access' => \App\Http\Middleware\EnsureModuleAccess::class,
            'permission.access' => \App\Http\Middleware\EnsurePermissionAccess::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'financeiro/*/apagar',
        ]);
        
        $middleware->web(append: [
            \App\Http\Middleware\ForceAppUrl::class,
            \App\Http\Middleware\LogSlowRequests::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Add session and cookie encryption to API routes for SPA authentication
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
