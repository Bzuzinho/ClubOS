<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('item_categories')
            || ! Schema::hasColumn('products', 'categoria_id')) {
            return;
        }

        DB::table('products')
            ->select(['id', 'categoria'])
            ->whereNull('categoria_id')
            ->whereNotNull('categoria')
            ->orderBy('id')
            ->chunk(200, function ($products): void {
                foreach ($products as $product) {
                    $categoryName = trim((string) $product->categoria);
                    if ($categoryName === '') {
                        continue;
                    }

                    $categoryId = DB::table('item_categories')
                        ->whereRaw('LOWER(nome) = ?', [mb_strtolower($categoryName)])
                        ->value('id');

                    if (! $categoryId) {
                        $categoryId = (string) Str::uuid();
                        DB::table('item_categories')->insert([
                            'id' => $categoryId,
                            'codigo' => $this->uniqueCategoryCode($categoryName),
                            'nome' => $categoryName,
                            'contexto' => null,
                            'ativo' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('products')->where('id', $product->id)->update([
                        'categoria_id' => $categoryId,
                        'updated_at' => now(),
                    ]);
                }
            });

        if (Schema::hasTable('supplier_purchase_items')
            && Schema::hasTable('supplier_purchases')
            && Schema::hasColumn('products', 'ultimo_custo')) {
            DB::table('products')
                ->select('id')
                ->whereNull('ultimo_custo')
                ->orderBy('id')
                ->chunk(200, function ($products): void {
                    foreach ($products as $product) {
                        $latestCost = DB::table('supplier_purchase_items')
                            ->join('supplier_purchases', 'supplier_purchases.id', '=', 'supplier_purchase_items.supplier_purchase_id')
                            ->where('supplier_purchase_items.article_id', $product->id)
                            ->orderByDesc('supplier_purchases.invoice_date')
                            ->orderByDesc('supplier_purchase_items.created_at')
                            ->value('supplier_purchase_items.unit_cost');

                        if ($latestCost !== null) {
                            DB::table('products')->where('id', $product->id)->update([
                                'ultimo_custo' => $latestCost,
                                'updated_at' => now(),
                            ]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // Data backfill is intentionally preserved on rollback.
    }

    private function uniqueCategoryCode(string $name): string
    {
        $base = substr(Str::upper(Str::slug($name, '-')) ?: 'CAT', 0, 42);
        $candidate = $base;
        $suffix = 2;

        while (DB::table('item_categories')->where('codigo', $candidate)->exists()) {
            $candidate = substr($base, 0, 42).'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
};
