<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class InternalLogisticsLifecycleAuditService
{
    private const VERSION = 'h5e-internal-logistics-lifecycle-audit-v1';

    public function __construct(
        private readonly SupplierPurchaseStockAuditService $supplierPurchases,
        private readonly LogisticsRequestStockAuditService $logisticsRequests,
        private readonly EquipmentLoanStockAuditService $equipmentLoans,
    ) {
    }

    /** @return array<string,mixed> */
    public function audit(): array
    {
        $purchaseAudit = $this->supplierPurchases->audit();
        $requestAudit = $this->logisticsRequests->audit();
        $loanAudit = $this->equipmentLoans->audit();

        $criticalCount = $this->sumMetric('critical_count', $purchaseAudit, $requestAudit, $loanAudit);
        $warningCount = $this->sumMetric('warning_count', $purchaseAudit, $requestAudit, $loanAudit);
        $actionableCount = $this->sumMetric('actionable_count', $purchaseAudit, $requestAudit, $loanAudit);

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'read_only' => true,
            'schema_detected' => $this->schemaDetected(),
            'summary' => [
                'supplier_purchase_count' => $this->tableCount('supplier_purchases'),
                'logistics_request_count' => $this->tableCount('logistics_requests'),
                'equipment_loan_count' => $this->tableCount('equipment_loans'),
                'sports_linked_request_count' => $this->sportsLinkedRequestCount(),
                'supplier_purchase_missing_movement_count' => $this->supplierPurchaseMissingMovementCount(),
                'supplier_purchase_legacy_financial_entry_count' => $this->supplierPurchaseLegacyFinancialEntryCount(),
                'invoiced_request_missing_invoice_count' => $this->invoicedRequestMissingInvoiceCount(),
                'financial_origin_mismatch_count' => $this->financialOriginMismatchCount(),
                'stock_audit_critical_count' => $criticalCount,
                'stock_audit_warning_count' => $warningCount,
                'stock_audit_actionable_count' => $actionableCount,
                'critical_count' => $criticalCount,
                'warning_count' => $warningCount,
                'actionable_count' => $actionableCount,
            ],
            'domain_audits' => [
                'supplier_purchases' => $purchaseAudit,
                'logistics_requests' => $requestAudit,
                'equipment_loans' => $loanAudit,
            ],
            'interpretation' => [
                'catalog_is_shared_with_store_and_configuration' => true,
                'stock_movements_is_the_only_stock_ledger' => true,
                'request_creation_does_not_move_stock' => true,
                'request_approval_reserves_and_delivery_exits_stock' => true,
                'equipment_loan_exit_and_return_use_stock_ledger' => true,
                'supplier_purchase_uses_movement_as_financial_boundary' => true,
                'supplier_purchase_financial_entry_is_legacy_read_only' => true,
                'sports_requests_keep_structured_source_identity' => true,
                'no_data_changed' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function schemaDetected(): array
    {
        return [
            'tables' => collect([
                'products',
                'stock_movements',
                'supplier_purchases',
                'supplier_purchase_items',
                'logistics_requests',
                'logistics_request_items',
                'equipment_loans',
                'movements',
                'invoices',
            ])->mapWithKeys(static fn (string $table): array => [$table => Schema::hasTable($table)])->all(),
            'product_capability_fields' => [
                'active' => Schema::hasColumn('products', 'ativo'),
                'allow_request' => Schema::hasColumn('products', 'allow_request'),
                'allow_loan' => Schema::hasColumn('products', 'allow_loan'),
                'track_stock' => Schema::hasColumn('products', 'track_stock'),
            ],
            'sports_request_source_fields' => [
                'source_type' => Schema::hasColumn('logistics_requests', 'source_type'),
                'source_id' => Schema::hasColumn('logistics_requests', 'source_id'),
                'idempotency_key' => Schema::hasColumn('logistics_requests', 'idempotency_key'),
            ],
        ];
    }

    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }

    private function sportsLinkedRequestCount(): int
    {
        if (! Schema::hasTable('logistics_requests') || ! Schema::hasColumn('logistics_requests', 'source_type')) {
            return 0;
        }

        return DB::table('logistics_requests')->whereNotNull('source_type')->count();
    }

    private function supplierPurchaseMissingMovementCount(): int
    {
        if (! Schema::hasTable('supplier_purchases') || ! Schema::hasColumn('supplier_purchases', 'financial_movement_id')) {
            return 0;
        }

        return DB::table('supplier_purchases')->whereNull('financial_movement_id')->count();
    }

    private function supplierPurchaseLegacyFinancialEntryCount(): int
    {
        if (! Schema::hasTable('supplier_purchases') || ! Schema::hasColumn('supplier_purchases', 'financial_entry_id')) {
            return 0;
        }

        return DB::table('supplier_purchases')->whereNotNull('financial_entry_id')->count();
    }

    private function invoicedRequestMissingInvoiceCount(): int
    {
        if (! Schema::hasTable('logistics_requests') || ! Schema::hasColumn('logistics_requests', 'financial_invoice_id')) {
            return 0;
        }

        return DB::table('logistics_requests')
            ->whereIn('status', ['invoiced', 'delivered'])
            ->whereNull('financial_invoice_id')
            ->count();
    }

    private function financialOriginMismatchCount(): int
    {
        $count = 0;

        if (Schema::hasTable('supplier_purchases') && Schema::hasTable('movements')) {
            $count += DB::table('supplier_purchases as purchases')
                ->join('movements', 'movements.id', '=', 'purchases.financial_movement_id')
                ->where(function ($query): void {
                    $query->where('movements.origem_tipo', '!=', 'supplier_purchase')
                        ->orWhereColumn('movements.origem_id', '!=', 'purchases.id');
                })
                ->count();
        }

        if (Schema::hasTable('logistics_requests') && Schema::hasTable('invoices')) {
            $count += DB::table('logistics_requests as requests')
                ->join('invoices', 'invoices.id', '=', 'requests.financial_invoice_id')
                ->where(function ($query): void {
                    $query->where('invoices.origem_tipo', '!=', 'logistics_request')
                        ->orWhereColumn('invoices.origem_id', '!=', 'requests.id');
                })
                ->count();
        }

        return $count;
    }

    /** @param array<string,mixed> ...$audits */
    private function sumMetric(string $metric, array ...$audits): int
    {
        return array_sum(array_map(
            static fn (array $audit): int => (int) data_get($audit, 'summary.'.$metric, 0),
            $audits,
        ));
    }
}
