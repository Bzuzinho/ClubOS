<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventario;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class AuditVariantStockReadinessCommand extends Command
{
    protected $signature = 'inventory:audit-variant-stock-readiness
        {--json : Devolve o relatório em JSON}
        {--report-path= : Guarda o relatório JSON no caminho indicado}
        {--fail-on-invalid-reference : Falha se existirem referências produto/variante incoerentes}';

    protected $description = 'Mede, sem alterar dados, a readiness produtiva para integrar stock de variantes no ledger';

    public function handle(): int
    {
        $schema = $this->schemaDetected();
        $summary = $this->summary($schema);
        $summary['ready_for_design'] = $schema['required_source_schema_present']
            && $summary['invalid_product_variant_reference_count'] === 0;

        $payload = [
            'version' => 'variant-stock-readiness-v1',
            'read_only' => true,
            'summary' => $summary,
            'schema_detected' => $schema,
            'interpretation' => [
                'aggregate_mismatch_is_diagnostic' => true,
                'historical_backfill_requires_exactly_one_matching_exit' => true,
                'no_backfill_or_schema_change_performed' => true,
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $reportPath = trim((string) $this->option('report-path'));

        if ($reportPath !== '') {
            File::ensureDirectoryExists(dirname($reportPath));
            File::put($reportPath, $json.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($json);
        } else {
            $this->table(['Métrica', 'Valor'], [
                ['variants', $summary['variant_count']],
                ['products_with_variants', $summary['product_with_variants_count']],
                ['aggregate_mismatches', $summary['product_variant_aggregate_mismatch_count']],
                ['variant_order_items', $summary['variant_order_item_count']],
                ['exactly_matched_exits', $summary['variant_order_item_exact_exit_count']],
                ['invalid_references', $summary['invalid_product_variant_reference_count']],
                ['ready_for_design', $summary['ready_for_design'] ? 'true' : 'false'],
            ]);
        }

        return (bool) $this->option('fail-on-invalid-reference')
            && $summary['invalid_product_variant_reference_count'] > 0
                ? self::FAILURE
                : self::SUCCESS;
    }

    /** @return array<string,bool> */
    private function schemaDetected(): array
    {
        $variants = Schema::hasTable('product_variants');
        $products = Schema::hasTable('products');
        $movements = Schema::hasTable('stock_movements');
        $orderItems = Schema::hasTable('loja_encomenda_itens');
        $orderItemVariant = $orderItems && Schema::hasColumn('loja_encomenda_itens', 'product_variant_id');

        return [
            'products_table_present' => $products,
            'product_variants_table_present' => $variants,
            'stock_movements_table_present' => $movements,
            'loja_order_items_table_present' => $orderItems,
            'loja_order_item_variant_column_present' => $orderItemVariant,
            'stock_movement_variant_column_present' => $movements && Schema::hasColumn('stock_movements', 'product_variant_id'),
            'required_source_schema_present' => $products && $variants && $movements && $orderItems && $orderItemVariant,
        ];
    }

    /**
     * @param array<string,bool> $schema
     * @return array<string,int|bool>
     */
    private function summary(array $schema): array
    {
        $variantCount = $schema['product_variants_table_present']
            ? DB::table('product_variants')->count()
            : 0;

        $variantMetrics = $schema['product_variants_table_present']
            ? DB::table('product_variants')->selectRaw(
                'SUM(CASE WHEN ativo = ? THEN 1 ELSE 0 END) as active_count,
                 SUM(CASE WHEN stock <> 0 THEN 1 ELSE 0 END) as nonzero_stock_count,
                 SUM(CASE WHEN stock_reservado <> 0 THEN 1 ELSE 0 END) as nonzero_reserved_count,
                 SUM(CASE WHEN stock < 0 OR stock_reservado < 0 OR stock_reservado > stock THEN 1 ELSE 0 END) as invalid_snapshot_count',
                [true],
            )->first()
            : null;

        $productWithVariantsCount = 0;
        $trackedProductWithVariantsCount = 0;
        $aggregateMismatchCount = 0;

        if ($schema['products_table_present'] && $schema['product_variants_table_present']) {
            $variantTotals = DB::table('product_variants')
                ->selectRaw('product_id, SUM(stock) as variant_stock, SUM(stock_reservado) as variant_reserved')
                ->groupBy('product_id');
            $productsWithVariants = DB::table('products')
                ->joinSub($variantTotals, 'variant_totals', fn ($join) => $join->on('products.id', '=', 'variant_totals.product_id'));

            $productWithVariantsCount = (clone $productsWithVariants)->count();
            $trackedProductWithVariantsCount = (clone $productsWithVariants)->where('products.track_stock', true)->count();
            $aggregateMismatchCount = (clone $productsWithVariants)
                ->where(function ($query): void {
                    $query->whereColumn('products.stock', '<>', 'variant_totals.variant_stock')
                        ->orWhereColumn('products.stock_reservado', '<>', 'variant_totals.variant_reserved');
                })
                ->count();
        }

        $orderMetrics = $this->variantOrderMetrics($schema);

        return [
            'variant_count' => (int) $variantCount,
            'active_variant_count' => (int) ($variantMetrics->active_count ?? 0),
            'variant_with_nonzero_stock_count' => (int) ($variantMetrics->nonzero_stock_count ?? 0),
            'variant_with_nonzero_reserved_count' => (int) ($variantMetrics->nonzero_reserved_count ?? 0),
            'invalid_variant_snapshot_count' => (int) ($variantMetrics->invalid_snapshot_count ?? 0),
            'product_with_variants_count' => $productWithVariantsCount,
            'tracked_product_with_variants_count' => $trackedProductWithVariantsCount,
            'product_variant_aggregate_mismatch_count' => $aggregateMismatchCount,
            ...$orderMetrics,
            'known_direct_variant_stock_writer_count' => $this->knownDirectWriterCount(),
        ];
    }

    /**
     * @param array<string,bool> $schema
     * @return array<string,int>
     */
    private function variantOrderMetrics(array $schema): array
    {
        $empty = [
            'variant_order_item_count' => 0,
            'variant_order_item_exact_exit_count' => 0,
            'variant_order_item_missing_exit_count' => 0,
            'variant_order_item_duplicate_exit_count' => 0,
            'variant_order_item_quantity_mismatch_count' => 0,
            'invalid_product_variant_reference_count' => 0,
        ];

        if (! $schema['required_source_schema_present']) {
            return $empty;
        }

        $items = DB::table('loja_encomenda_itens as item')
            ->join('product_variants as variant', 'variant.id', '=', 'item.product_variant_id')
            ->whereNotNull('item.product_variant_id')
            ->select(['item.id', 'item.article_id', 'item.product_variant_id', 'item.quantidade', 'variant.product_id'])
            ->get();

        $exact = 0;
        $missing = 0;
        $duplicate = 0;
        $quantityMismatch = 0;
        $invalidReference = 0;

        foreach ($items as $item) {
            if ((string) $item->article_id !== (string) $item->product_id) {
                $invalidReference++;
            }

            $exits = DB::table('stock_movements')
                ->where('article_id', $item->article_id)
                ->where('movement_type', 'exit')
                ->where('reference_type', 'store_order_item')
                ->where('reference_id', $item->id)
                ->get(['quantity']);

            if ($exits->isEmpty()) {
                $missing++;
            } elseif ($exits->count() > 1) {
                $duplicate++;
            } elseif ((int) $exits->first()->quantity !== (int) $item->quantidade) {
                $quantityMismatch++;
            } else {
                $exact++;
            }
        }

        return [
            'variant_order_item_count' => $items->count(),
            'variant_order_item_exact_exit_count' => $exact,
            'variant_order_item_missing_exit_count' => $missing,
            'variant_order_item_duplicate_exit_count' => $duplicate,
            'variant_order_item_quantity_mismatch_count' => $quantityMismatch,
            'invalid_product_variant_reference_count' => $invalidReference,
        ];
    }

    private function knownDirectWriterCount(): int
    {
        $boundaries = [
            [app_path('Services/Catalog/CanonicalProductStockService.php'), '/\$variant->decrement\s*\(\s*[\'\"]stock/'],
            [app_path('Http/Controllers/AdminLojaProdutoController.php'), '/[\'\"]stock[\'\"]\s*=>\s*\$payload\[[\'\"]stock_atual[\'\"]\]/'],
        ];

        return collect($boundaries)->filter(
            static fn (array $boundary): bool => File::exists($boundary[0])
                && preg_match($boundary[1], File::get($boundary[0])) === 1,
        )->count();
    }
}
