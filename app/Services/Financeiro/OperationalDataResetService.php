<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Services\Inventario\StockMovementSemantics;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class OperationalDataResetService
{
    public const VERSION = 'finance-operational-reset-v1';
    public const RESET_ID = 'finance-reset-2026-09-28';
    public const ACKNOWLEDGEMENT = 'RESET-FINANCE-2026-09-28';

    /**
     * Tabelas cujo conteúdo operacional é integralmente descartável neste reset.
     *
     * Catálogos/configuração como monthly_fees, cost_centers, financial_categories,
     * payment_methods, invoice_types, suppliers, products e dados_financeiros são preservados.
     *
     * @var list<string>
     */
    private const WHOLE_RESET_TABLES = [
        'loja_encomenda_devolucoes',
        'loja_encomenda_itens',
        'loja_encomendas',
        'loja_carrinho_itens',
        'loja_carrinhos',
        'store_order_items',
        'store_orders',
        'store_cart_items',
        'logistics_request_items',
        'logistics_requests',
        'supplier_purchase_items',
        'supplier_purchases',
        'supplier_purchase_deletion_audits',
        'competition_financial_obligations',
        'competition_finance_policies',
        'competition_event_projections',
        'team_results',
        'competition_results',
        'result_splits',
        'results',
        'competition_registrations',
        'provas',
        'competitions',
        'payment_reversals',
        'bank_transaction_allocations',
        'account_credit_usages',
        'account_credits',
        'payment_allocations',
        'fiscal_document_requests',
        'receipt_import_items',
        'receipt_import_batches',
        'mapa_conciliacao',
        'bank_reconciliation_suggestions',
        'bank_reconciliation_aliases',
        'bank_reconciliation_repositories',
        'invoice_items',
        'financial_entries',
        'invoices',
        'movement_documents',
        'movement_items',
        'movements',
        'payments',
        'transactions',
        'bank_statements',
        'convocation_movements',
        'sales',
    ];

    /**
     * @var list<string>
     */
    private const CORE_PRESERVED_TABLES = [
        'users',
        'dados_pessoais',
        'athlete_sports_data',
        'familias',
        'familia_user',
        'user_guardian',
        'monthly_fees',
        'dados_financeiros',
        'seasons',
        'trainings',
        'products',
        'product_variants',
        'suppliers',
        'cost_centers',
        'financial_categories',
        'payment_methods',
        'invoice_types',
    ];

    public function __construct(
        private readonly StockMovementSemantics $stockSemantics,
    ) {
    }

    public function markerPath(): string
    {
        return storage_path('app/operations/'.self::RESET_ID.'.json');
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(): array
    {
        $context = $this->buildContext();

        return [
            'version' => self::VERSION,
            'reset_id' => self::RESET_ID,
            'generated_at' => now()->toIso8601String(),
            'executed' => false,
            'already_executed' => File::exists($this->markerPath()),
            'before' => $this->operationalSnapshot($context),
            'preserved' => $this->preservedSnapshot(),
            'interpretation' => [
                'dry_run' => true,
                'athlete_identity_data_preserved' => true,
                'monthly_fee_catalog_preserved' => true,
                'monthly_fee_assignments_preserved' => true,
                'manual_current_account_will_reset_to_zero' => true,
                'financial_fiscal_bank_history_will_be_deleted' => true,
                'fictional_store_logistics_competition_data_will_be_deleted' => true,
                'unrelated_events_trainings_and_sports_history_preserved' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function execute(): array
    {
        if (File::exists($this->markerPath())) {
            $payload = json_decode((string) File::get($this->markerPath()), true);

            return is_array($payload)
                ? [...$payload, 'already_executed' => true]
                : [
                    'version' => self::VERSION,
                    'reset_id' => self::RESET_ID,
                    'executed' => true,
                    'already_executed' => true,
                ];
        }

        $report = DB::transaction(function (): array {
            $context = $this->buildContext();
            $before = $this->operationalSnapshot($context);
            $preservedBefore = $this->preservedSnapshot();

            $this->applyReset($context);

            $afterContext = $this->buildContext();
            $after = $this->operationalSnapshot($afterContext);
            $preservedAfter = $this->preservedSnapshot();
            $assertions = $this->assertions($before, $after, $preservedBefore, $preservedAfter);

            if (in_array(false, $assertions, true)) {
                throw new RuntimeException('Operational finance reset validation failed; transaction rolled back.');
            }

            return [
                'version' => self::VERSION,
                'reset_id' => self::RESET_ID,
                'generated_at' => now()->toIso8601String(),
                'executed' => true,
                'already_executed' => false,
                'before' => $before,
                'after' => $after,
                'preserved_before' => $preservedBefore,
                'preserved_after' => $preservedAfter,
                'assertions' => $assertions,
                'summary' => [
                    'reset_tables_empty' => $assertions['reset_tables_empty'],
                    'core_counts_preserved' => $assertions['core_counts_preserved'],
                    'monthly_fee_catalog_preserved' => $assertions['monthly_fee_catalog_preserved'],
                    'monthly_fee_assignments_preserved' => $assertions['monthly_fee_assignments_preserved'],
                    'manual_current_accounts_zero' => $assertions['manual_current_accounts_zero'],
                    'targeted_stock_movements_removed' => $assertions['targeted_stock_movements_removed'],
                    'competition_linked_events_removed' => $assertions['competition_linked_events_removed'],
                    'product_catalog_preserved' => $assertions['product_catalog_preserved'],
                ],
            ];
        }, 3);

        File::ensureDirectoryExists(dirname($this->markerPath()), 0700, true);
        File::put(
            $this->markerPath(),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL
        );

        return $report;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function applyReset(array $context): void
    {
        if (Schema::hasTable('convocation_groups') && Schema::hasColumn('convocation_groups', 'movimento_id')) {
            DB::table('convocation_groups')->whereNotNull('movimento_id')->update(['movimento_id' => null]);
        }

        $targetStockMovementIds = $context['target_stock_movement_ids'] ?? [];
        $affectedProductIds = $context['affected_product_ids'] ?? [];
        $affectedVariantIds = $context['affected_variant_ids'] ?? [];

        if ($targetStockMovementIds !== [] && Schema::hasTable('stock_movements')) {
            DB::table('stock_movements')->whereIn('id', $targetStockMovementIds)->delete();
            $this->recalculateStockSnapshots($affectedProductIds, $affectedVariantIds);
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'ultimo_custo')) {
            DB::table('products')->update(['ultimo_custo' => null]);
        }

        if (Schema::hasTable('in_app_alerts') && Schema::hasColumn('in_app_alerts', 'link')) {
            DB::table('in_app_alerts')
                ->where(function (Builder $query): void {
                    $query->where('link', 'like', '/admin/loja/encomendas/%')
                        ->orWhere('link', 'like', '/logistica/%');
                })
                ->delete();
        }

        $this->deleteAll('loja_encomenda_devolucoes');
        $this->deleteAll('loja_encomenda_itens');
        $this->deleteAll('loja_encomendas');
        $this->deleteAll('loja_carrinho_itens');
        $this->deleteAll('loja_carrinhos');
        $this->deleteAll('store_order_items');
        $this->deleteAll('store_orders');
        $this->deleteAll('store_cart_items');

        $this->deleteAll('logistics_request_items');
        $this->deleteAll('logistics_requests');

        $this->deleteAll('supplier_purchase_items');
        $this->deleteAll('supplier_purchases');
        $this->deleteAll('supplier_purchase_deletion_audits');

        $competitionEventIds = $context['competition_event_ids'] ?? [];
        if ($competitionEventIds !== [] && Schema::hasTable('result_provas') && Schema::hasColumn('result_provas', 'evento_id')) {
            DB::table('result_provas')->whereIn('evento_id', $competitionEventIds)->delete();
        }

        $this->deleteAll('competition_financial_obligations');
        $this->deleteAll('competition_finance_policies');
        $this->deleteAll('competition_event_projections');
        $this->deleteAll('team_results');
        $this->deleteAll('competition_results');
        $this->deleteAll('result_splits');
        $this->deleteAll('results');
        $this->deleteAll('competition_registrations');
        $this->deleteAll('provas');
        $this->deleteAll('competitions');

        if ($competitionEventIds !== [] && Schema::hasTable('events')) {
            DB::table('events')->whereIn('id', $competitionEventIds)->delete();
        }

        $this->deleteAll('payment_reversals');
        $this->deleteAll('bank_transaction_allocations');
        $this->deleteAll('account_credit_usages');
        $this->deleteAll('account_credits');
        $this->deleteAll('payment_allocations');
        $this->deleteAll('fiscal_document_requests');
        $this->deleteAll('receipt_import_items');
        $this->deleteAll('receipt_import_batches');
        $this->deleteAll('mapa_conciliacao');
        $this->deleteAll('bank_reconciliation_suggestions');
        $this->deleteAll('bank_reconciliation_aliases');
        $this->deleteAll('bank_reconciliation_repositories');
        $this->deleteAll('invoice_items');
        $this->deleteAll('payments');
        $this->deleteAll('financial_entries');
        $this->deleteAll('invoices');
        $this->deleteAll('movement_documents');
        $this->deleteAll('movement_items');
        $this->deleteAll('movements');
        $this->deleteAll('transactions');
        $this->deleteAll('bank_statements');
        $this->deleteAll('convocation_movements');
        $this->deleteAll('sales');

        if (Schema::hasTable('dados_financeiros') && Schema::hasColumn('dados_financeiros', 'conta_corrente_manual')) {
            DB::table('dados_financeiros')->update(['conta_corrente_manual' => 0]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildContext(): array
    {
        $supplierPurchaseIds = $this->ids('supplier_purchases');
        $supplierPurchaseItemIds = $this->ids('supplier_purchase_items');
        $logisticsRequestIds = $this->ids('logistics_requests');
        $storeOrderItemIds = $this->ids('loja_encomenda_itens');

        $competitionEventIds = collect();

        if (Schema::hasTable('competition_event_projections')) {
            if (Schema::hasColumn('competition_event_projections', 'event_id')) {
                $competitionEventIds->push(...DB::table('competition_event_projections')->whereNotNull('event_id')->pluck('event_id')->all());
            }
            if (Schema::hasColumn('competition_event_projections', 'legacy_event_id')) {
                $competitionEventIds->push(...DB::table('competition_event_projections')->whereNotNull('legacy_event_id')->pluck('legacy_event_id')->all());
            }
        }

        if (Schema::hasTable('competitions') && Schema::hasColumn('competitions', 'evento_id')) {
            $competitionEventIds->push(...DB::table('competitions')->whereNotNull('evento_id')->pluck('evento_id')->all());
        }

        $competitionEventIds = $competitionEventIds
            ->filter(fn (mixed $id): bool => filled($id))
            ->map('strval')
            ->unique()
            ->values()
            ->all();

        $targetStockMovementIds = $this->targetStockMovementIds([
            'supplier_purchase_ids' => $supplierPurchaseIds,
            'supplier_purchase_item_ids' => $supplierPurchaseItemIds,
            'logistics_request_ids' => $logisticsRequestIds,
            'store_order_item_ids' => $storeOrderItemIds,
        ]);

        $affectedProductIds = [];
        $affectedVariantIds = [];

        if ($targetStockMovementIds !== [] && Schema::hasTable('stock_movements')) {
            $affectedProductIds = DB::table('stock_movements')
                ->whereIn('id', $targetStockMovementIds)
                ->pluck('article_id')
                ->filter()
                ->map('strval')
                ->unique()
                ->values()
                ->all();

            if (Schema::hasColumn('stock_movements', 'product_variant_id')) {
                $affectedVariantIds = DB::table('stock_movements')
                    ->whereIn('id', $targetStockMovementIds)
                    ->whereNotNull('product_variant_id')
                    ->pluck('product_variant_id')
                    ->filter()
                    ->map('strval')
                    ->unique()
                    ->values()
                    ->all();
            }
        }

        return [
            'supplier_purchase_ids' => $supplierPurchaseIds,
            'supplier_purchase_item_ids' => $supplierPurchaseItemIds,
            'logistics_request_ids' => $logisticsRequestIds,
            'store_order_item_ids' => $storeOrderItemIds,
            'competition_event_ids' => $competitionEventIds,
            'target_stock_movement_ids' => $targetStockMovementIds,
            'affected_product_ids' => $affectedProductIds,
            'affected_variant_ids' => $affectedVariantIds,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,int>
     */
    private function operationalSnapshot(array $context): array
    {
        $snapshot = [];

        foreach (self::WHOLE_RESET_TABLES as $table) {
            $snapshot[$table] = $this->count($table);
        }

        $competitionEventIds = $context['competition_event_ids'] ?? [];
        $snapshot['competition_linked_events'] = $competitionEventIds === [] || ! Schema::hasTable('events')
            ? 0
            : DB::table('events')->whereIn('id', $competitionEventIds)->count();

        $snapshot['competition_linked_result_provas'] = $competitionEventIds === []
            || ! Schema::hasTable('result_provas')
            || ! Schema::hasColumn('result_provas', 'evento_id')
            ? 0
            : DB::table('result_provas')->whereIn('evento_id', $competitionEventIds)->count();

        $snapshot['targeted_stock_movements'] = count($context['target_stock_movement_ids'] ?? []);

        $snapshot['manual_current_accounts_nonzero'] = Schema::hasTable('dados_financeiros')
            && Schema::hasColumn('dados_financeiros', 'conta_corrente_manual')
            ? DB::table('dados_financeiros')->where('conta_corrente_manual', '!=', 0)->count()
            : 0;

        $snapshot['products_with_last_cost'] = Schema::hasTable('products')
            && Schema::hasColumn('products', 'ultimo_custo')
            ? DB::table('products')->whereNotNull('ultimo_custo')->count()
            : 0;

        return $snapshot;
    }

    /**
     * @return array<string,mixed>
     */
    private function preservedSnapshot(): array
    {
        $counts = [];
        foreach (self::CORE_PRESERVED_TABLES as $table) {
            $counts[$table] = $this->count($table);
        }

        return [
            'counts' => $counts,
            'monthly_fees_hash' => $this->hashRows('monthly_fees', [
                'id', 'designacao', 'valor', 'age_group_id', 'ativo',
            ]),
            'member_finance_config_hash' => $this->hashRows('dados_financeiros', [
                'user_id',
                'mensalidade_id',
                'discount_type',
                'discount_value',
                'discount_reason',
            ]),
        ];
    }

    /**
     * @param array<string,int> $before
     * @param array<string,int> $after
     * @param array<string,mixed> $preservedBefore
     * @param array<string,mixed> $preservedAfter
     * @return array<string,bool>
     */
    private function assertions(array $before, array $after, array $preservedBefore, array $preservedAfter): array
    {
        $resetTablesEmpty = true;
        foreach (self::WHOLE_RESET_TABLES as $table) {
            if (($after[$table] ?? 0) !== 0) {
                $resetTablesEmpty = false;
                break;
            }
        }

        $countsBefore = is_array($preservedBefore['counts'] ?? null) ? $preservedBefore['counts'] : [];
        $countsAfter = is_array($preservedAfter['counts'] ?? null) ? $preservedAfter['counts'] : [];

        return [
            'reset_tables_empty' => $resetTablesEmpty,
            'core_counts_preserved' => $countsBefore === $countsAfter,
            'monthly_fee_catalog_preserved' => ($preservedBefore['monthly_fees_hash'] ?? null) === ($preservedAfter['monthly_fees_hash'] ?? null),
            'monthly_fee_assignments_preserved' => ($preservedBefore['member_finance_config_hash'] ?? null) === ($preservedAfter['member_finance_config_hash'] ?? null),
            'manual_current_accounts_zero' => ($after['manual_current_accounts_nonzero'] ?? -1) === 0,
            'targeted_stock_movements_removed' => ($after['targeted_stock_movements'] ?? -1) === 0,
            'competition_linked_events_removed' => ($after['competition_linked_events'] ?? -1) === 0
                && ($after['competition_linked_result_provas'] ?? -1) === 0,
            'product_catalog_preserved' => ($countsBefore['products'] ?? null) === ($countsAfter['products'] ?? null)
                && ($countsBefore['product_variants'] ?? null) === ($countsAfter['product_variants'] ?? null),
            'reset_scope_was_nonexpanding' => array_sum($after) <= array_sum($before),
        ];
    }

    /**
     * @param list<string> $productIds
     * @param list<string> $variantIds
     */
    private function recalculateStockSnapshots(array $productIds, array $variantIds): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        if (Schema::hasTable('product_variants')) {
            foreach ($variantIds as $variantId) {
                $physical = 0;
                $reserved = 0;

                DB::table('stock_movements')
                    ->where('product_variant_id', $variantId)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get()
                    ->each(function (object $movement) use (&$physical, &$reserved): void {
                        if ((string) $movement->movement_type === 'variant_opening_snapshot') {
                            $physical += (int) $movement->quantity;

                            return;
                        }

                        if ((string) $movement->movement_type === 'variant_opening_reservation') {
                            $reserved += (int) $movement->quantity;

                            return;
                        }

                        $delta = $this->stockSemantics->deltas($movement);
                        $physical += $delta['physical'];
                        $reserved += $delta['reserved'];
                    });

                DB::table('product_variants')->where('id', $variantId)->update([
                    'stock' => $physical,
                    'stock_reservado' => $reserved,
                ]);
            }
        }

        if (Schema::hasTable('products')) {
            foreach ($productIds as $productId) {
                $physical = 0;
                $reserved = 0;

                DB::table('stock_movements')
                    ->where('article_id', $productId)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get()
                    ->each(function (object $movement) use (&$physical, &$reserved): void {
                        $delta = $this->stockSemantics->deltas($movement);
                        $physical += $delta['physical'];
                        $reserved += $delta['reserved'];
                    });

                DB::table('products')->where('id', $productId)->update([
                    'stock' => $physical,
                    'stock_reservado' => $reserved,
                ]);
            }
        }
    }

    /**
     * Todos os movimentos destas origens pertencem aos fluxos operacionais
     * fictícios autorizados para reset. O filtro por tipo de origem apanha
     * também movimentos cujos itens-fonte já foram substituídos/apagados.
     *
     * @param array<string,list<string>> $context
     * @return list<string>
     */
    private function targetStockMovementIds(array $context): array
    {
        if (! Schema::hasTable('stock_movements') || ! Schema::hasColumn('stock_movements', 'reference_type')) {
            return [];
        }

        return DB::table('stock_movements')
            ->whereIn('reference_type', [
                'supplier_purchase',
                'supplier_purchase_update_entry',
                'supplier_purchase_update_reversal',
                'supplier_purchase_delete',
                'logistics_request',
                'store_order_item',
                'loja_encomenda_item',
            ])
            ->pluck('id')
            ->filter(fn (mixed $id): bool => filled($id))
            ->map('strval')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function ids(string $table): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return [];
        }

        return DB::table($table)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => filled($id))
            ->map('strval')
            ->values()
            ->all();
    }

    private function deleteAll(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->delete() : 0;
    }

    private function count(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }

    /**
     * @param list<string> $columns
     */
    private function hashRows(string $table, array $columns): string
    {
        if (! Schema::hasTable($table)) {
            return 'table-missing';
        }

        $existing = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($existing === []) {
            return 'columns-missing';
        }

        $orderColumn = in_array('id', $existing, true)
            ? 'id'
            : (in_array('user_id', $existing, true) ? 'user_id' : $existing[0]);

        $rows = DB::table($table)
            ->select($existing)
            ->orderBy($orderColumn)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        return hash(
            'sha256',
            json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }
}
