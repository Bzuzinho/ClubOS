<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class OperationalDataResetService
{
    public const VERSION = 'operational-data-reset-2026-09-29-v1';

    /** @var list<string> */
    private const DELETE_ORDER = [
        // Store / returns and payment reversal restrictions.
        'payment_reversals',
        'loja_encomenda_devolucoes',
        'loja_encomenda_itens',
        'loja_encomendas',
        'store_order_items',
        'store_orders',

        // Receipt import / settlement graph.
        'bank_transaction_allocations',
        'receipt_import_items',
        'receipt_import_batches',
        'account_credit_usages',
        'account_credits',
        'payment_allocations',
        'mapa_conciliacao',
        'bank_reconciliation_suggestions',
        'fiscal_document_requests',

        // Bank learning/history.
        'bank_reconciliation_aliases',
        'bank_reconciliation_repositories',
        'bank_statements',
        'payments',

        // Invoicing and finance transactions.
        'invoice_items',
        'invoices',
        'movement_documents',
        'movement_items',
        'supplier_purchase_items',
        'supplier_purchases',
        'supplier_purchase_deletion_audits',
        'financial_entries',
        'movements',
        'transactions',

        // Logistics operational data / stock ledger.
        'logistics_request_items',
        'logistics_requests',
        'equipment_loans',
        'stock_movements',

        // Competition operational data.
        'result_splits',
        'results',
        'competition_results',
        'team_results',
        'competition_registrations',
        'competition_financial_obligations',
        'competition_finance_policies',
        'competition_event_projections',
        'provas',
        'competitions',
    ];

    /** @var list<string> */
    private const PRESERVED_TABLES = [
        'users',
        'dados_pessoais',
        'athlete_sports_data',
        'familias',
        'familia_user',
        'user_guardian',
        'monthly_fees',
        'dados_financeiros',
        'products',
        'product_variants',
        'suppliers',
        'cost_centers',
        'financial_categories',
        'payment_methods',
        'invoice_types',
        'trainings',
    ];

    /**
     * @return array<string,mixed>
     */
    public function preview(): array
    {
        return [
            'version' => self::VERSION,
            'mode' => 'preview',
            'delete_counts' => $this->counts(self::DELETE_ORDER),
            'preserved_counts' => $this->counts(self::PRESERVED_TABLES),
            'stock_snapshot' => $this->stockSnapshot(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function execute(): array
    {
        $before = $this->preview();

        $competitionEventIds = $this->competitionEventIds();

        DB::transaction(function () use ($competitionEventIds): void {
            foreach (self::DELETE_ORDER as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                DB::table($table)->delete();
            }

            if ($competitionEventIds !== [] && Schema::hasTable('events')) {
                DB::table('events')->whereIn('id', $competitionEventIds)->delete();
            }

            if (Schema::hasTable('products')) {
                $update = [];
                foreach (['stock', 'stock_reservado'] as $column) {
                    if (Schema::hasColumn('products', $column)) {
                        $update[$column] = 0;
                    }
                }
                if ($update !== []) {
                    DB::table('products')->update($update);
                }
            }

            if (Schema::hasTable('product_variants')) {
                $update = [];
                foreach (['stock', 'stock_reservado'] as $column) {
                    if (Schema::hasColumn('product_variants', $column)) {
                        $update[$column] = 0;
                    }
                }
                if ($update !== []) {
                    DB::table('product_variants')->update($update);
                }
            }

            // Preserve member financial configuration but clear only the
            // manually carried balance so finance restarts from zero.
            if (Schema::hasTable('dados_financeiros') && Schema::hasColumn('dados_financeiros', 'conta_corrente_manual')) {
                DB::table('dados_financeiros')->update(['conta_corrente_manual' => 0]);
            }

            $remaining = $this->counts(self::DELETE_ORDER);
            $notEmpty = array_filter($remaining, static fn (int $count): bool => $count !== 0);
            if ($notEmpty !== []) {
                throw new RuntimeException('Operational reset incomplete: '.json_encode($notEmpty, JSON_THROW_ON_ERROR));
            }
        }, 3);

        $after = $this->preview();

        foreach ($before['preserved_counts'] as $table => $count) {
            if (($after['preserved_counts'][$table] ?? null) !== $count) {
                throw new RuntimeException("Preserved table count changed unexpectedly: {$table}");
            }
        }

        return [
            'version' => self::VERSION,
            'mode' => 'executed',
            'before' => $before,
            'after' => $after,
            'preserved_invariants_ok' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function competitionEventIds(): array
    {
        $ids = collect();

        if (Schema::hasTable('competition_event_projections') && Schema::hasColumn('competition_event_projections', 'event_id')) {
            $ids = $ids->merge(
                DB::table('competition_event_projections')->whereNotNull('event_id')->pluck('event_id')
            );
        }

        if (Schema::hasTable('competitions') && Schema::hasColumn('competitions', 'evento_id')) {
            $ids = $ids->merge(
                DB::table('competitions')->whereNotNull('evento_id')->pluck('evento_id')
            );
        }

        return $ids
            ->filter(fn ($id): bool => is_string($id) && trim($id) !== '')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param list<string> $tables
     * @return array<string,int>
     */
    private function counts(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $result[$table] = DB::table($table)->count();
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * @return array<string,int>
     */
    private function stockSnapshot(): array
    {
        return [
            'products_stock' => Schema::hasTable('products') && Schema::hasColumn('products', 'stock')
                ? (int) DB::table('products')->sum('stock')
                : 0,
            'products_reserved' => Schema::hasTable('products') && Schema::hasColumn('products', 'stock_reservado')
                ? (int) DB::table('products')->sum('stock_reservado')
                : 0,
            'variants_stock' => Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'stock')
                ? (int) DB::table('product_variants')->sum('stock')
                : 0,
            'variants_reserved' => Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'stock_reservado')
                ? (int) DB::table('product_variants')->sum('stock_reservado')
                : 0,
        ];
    }
}
