<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->assertNoLegacyIds('loja_carrinho_itens');
        $this->assertNoLegacyIds('loja_encomenda_itens');

        $this->dropLegacyColumns('loja_carrinho_itens', true);
        $this->dropLegacyColumns('loja_encomenda_itens', false);
    }

    public function down(): void
    {
        $this->restoreLegacyColumns('loja_carrinho_itens', true);
        $this->restoreLegacyColumns('loja_encomenda_itens', false);

        $this->backfillLegacyIds('loja_carrinho_itens');
        $this->backfillLegacyIds('loja_encomenda_itens');
    }

    private function assertNoLegacyIds(string $table): void
    {
        if (! Schema::hasColumn($table, 'loja_produto_id')) {
            return;
        }

        $legacyProductCount = DB::table($table)->whereNotNull('loja_produto_id')->count();
        $legacyVariantCount = DB::table($table)->whereNotNull('loja_produto_variante_id')->count();

        if ($legacyProductCount === 0 && $legacyVariantCount === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Cannot drop legacy store ids from %s because %d product refs and %d variant refs still exist.',
            $table,
            $legacyProductCount,
            $legacyVariantCount,
        ));
    }

    private function dropLegacyColumns(string $table, bool $dropLegacyUnique): void
    {
        if (! Schema::hasColumn($table, 'loja_produto_id')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->rebuildSqliteTableWithoutLegacyColumns($table, $dropLegacyUnique);

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($dropLegacyUnique) {
            $table->dropForeign(['loja_produto_id']);
            $table->dropForeign(['loja_produto_variante_id']);

            if ($dropLegacyUnique) {
                $table->dropUnique('loja_carrinho_itens_unique_item');
            }
        });

        Schema::table($table, function (Blueprint $table) {
            $table->dropColumn(['loja_produto_id', 'loja_produto_variante_id']);
        });

        if ($dropLegacyUnique) {
            Schema::table($table, function (Blueprint $table) {
                $table->unique(['loja_carrinho_id', 'article_id', 'product_variant_id'], 'loja_carrinho_itens_unique_article_item');
            });
        }
    }

    private function restoreLegacyColumns(string $table, bool $restoreLegacyUnique): void
    {
        if (Schema::hasColumn($table, 'loja_produto_id')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTableWithLegacyColumns($table, $restoreLegacyUnique);

            return;
        }

        if ($restoreLegacyUnique) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropUnique('loja_carrinho_itens_unique_article_item');
            });
        }

        Schema::table($table, function (Blueprint $table) {
            $table->uuid('loja_produto_id')->nullable()->after('product_variant_id');
            $table->uuid('loja_produto_variante_id')->nullable()->after('loja_produto_id');
        });

        Schema::table($table, function (Blueprint $table) use ($restoreLegacyUnique) {
            $table->foreign('loja_produto_id')->references('id')->on('loja_produtos')->nullOnDelete();
            $table->foreign('loja_produto_variante_id')->references('id')->on('loja_produto_variantes')->nullOnDelete();

            if ($restoreLegacyUnique) {
                $table->unique(['loja_carrinho_id', 'loja_produto_id', 'loja_produto_variante_id'], 'loja_carrinho_itens_unique_item');
            }
        });
    }

    private function backfillLegacyIds(string $table): void
    {
        DB::table($table)
            ->whereNull('loja_produto_id')
            ->whereNotNull('article_id')
            ->orderBy('created_at')
            ->get()
            ->each(function ($item) use ($table) {
                $legacyProductId = DB::table('product_catalog_migrations')
                    ->where('legacy_source', 'loja_produtos')
                    ->where('product_id', $item->article_id)
                    ->value('legacy_id');

                $legacyVariantId = null;

                if ($item->product_variant_id) {
                    $legacyVariantId = DB::table('product_catalog_migrations')
                        ->where('legacy_source', 'loja_produto_variantes')
                        ->where('product_variant_id', $item->product_variant_id)
                        ->value('legacy_id');
                }

                DB::table($table)
                    ->where('id', $item->id)
                    ->update([
                        'loja_produto_id' => $legacyProductId,
                        'loja_produto_variante_id' => $legacyVariantId,
                    ]);
            });
    }

    private function rebuildSqliteTableWithoutLegacyColumns(string $table, bool $addCanonicalUnique): void
    {
        $temporaryTable = $table . '_new';

        DB::statement('PRAGMA foreign_keys=OFF');

        Schema::create($temporaryTable, function (Blueprint $tableBlueprint) use ($table, $addCanonicalUnique) {
            $tableBlueprint->uuid('id')->primary();

            if ($table === 'loja_carrinho_itens') {
                $tableBlueprint->uuid('loja_carrinho_id');
            } else {
                $tableBlueprint->uuid('loja_encomenda_id');
                $tableBlueprint->string('descricao');
            }

            $tableBlueprint->uuid('article_id');
            $tableBlueprint->uuid('product_variant_id')->nullable();
            $tableBlueprint->integer('quantidade');
            $tableBlueprint->decimal('preco_unitario', 10, 2);
            $tableBlueprint->decimal('total_linha', 10, 2);
            $tableBlueprint->timestamps();

            if ($table === 'loja_carrinho_itens') {
                $tableBlueprint->foreign('loja_carrinho_id')->references('id')->on('loja_carrinhos')->cascadeOnDelete();
                if ($addCanonicalUnique) {
                    $tableBlueprint->unique(['loja_carrinho_id', 'article_id', 'product_variant_id'], 'loja_carrinho_itens_unique_article_item');
                }
            } else {
                $tableBlueprint->foreign('loja_encomenda_id')->references('id')->on('loja_encomendas')->cascadeOnDelete();
            }

            $tableBlueprint->foreign('article_id')->references('id')->on('products')->restrictOnDelete();
            $tableBlueprint->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
        });

        DB::table($temporaryTable)->insertUsing(
            $table === 'loja_carrinho_itens'
                ? ['id', 'loja_carrinho_id', 'article_id', 'product_variant_id', 'quantidade', 'preco_unitario', 'total_linha', 'created_at', 'updated_at']
                : ['id', 'loja_encomenda_id', 'descricao', 'article_id', 'product_variant_id', 'quantidade', 'preco_unitario', 'total_linha', 'created_at', 'updated_at'],
            DB::table($table)->select(
                $table === 'loja_carrinho_itens'
                    ? ['id', 'loja_carrinho_id', 'article_id', 'product_variant_id', 'quantidade', 'preco_unitario', 'total_linha', 'created_at', 'updated_at']
                    : ['id', 'loja_encomenda_id', 'descricao', 'article_id', 'product_variant_id', 'quantidade', 'preco_unitario', 'total_linha', 'created_at', 'updated_at']
            )
        );

        Schema::drop($table);
        Schema::rename($temporaryTable, $table);

        DB::statement('PRAGMA foreign_keys=ON');
    }

    private function rebuildSqliteTableWithLegacyColumns(string $table, bool $restoreLegacyUnique): void
    {
        $temporaryTable = $table . '_legacy_restore';

        DB::statement('PRAGMA foreign_keys=OFF');

        Schema::create($temporaryTable, function (Blueprint $tableBlueprint) use ($table, $restoreLegacyUnique) {
            $tableBlueprint->uuid('id')->primary();

            if ($table === 'loja_carrinho_itens') {
                $tableBlueprint->uuid('loja_carrinho_id');
            } else {
                $tableBlueprint->uuid('loja_encomenda_id');
            }

            $tableBlueprint->uuid('article_id');
            $tableBlueprint->uuid('product_variant_id')->nullable();
            $tableBlueprint->uuid('loja_produto_id')->nullable();
            $tableBlueprint->uuid('loja_produto_variante_id')->nullable();

            if ($table === 'loja_encomenda_itens') {
                $tableBlueprint->string('descricao');
            }

            $tableBlueprint->integer('quantidade');
            $tableBlueprint->decimal('preco_unitario', 10, 2);
            $tableBlueprint->decimal('total_linha', 10, 2);
            $tableBlueprint->timestamps();

            if ($table === 'loja_carrinho_itens') {
                $tableBlueprint->foreign('loja_carrinho_id')->references('id')->on('loja_carrinhos')->cascadeOnDelete();
                if ($restoreLegacyUnique) {
                    $tableBlueprint->unique(['loja_carrinho_id', 'loja_produto_id', 'loja_produto_variante_id'], 'loja_carrinho_itens_unique_item');
                }
            } else {
                $tableBlueprint->foreign('loja_encomenda_id')->references('id')->on('loja_encomendas')->cascadeOnDelete();
            }

            $tableBlueprint->foreign('article_id')->references('id')->on('products')->restrictOnDelete();
            $tableBlueprint->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $tableBlueprint->foreign('loja_produto_id')->references('id')->on('loja_produtos')->nullOnDelete();
            $tableBlueprint->foreign('loja_produto_variante_id')->references('id')->on('loja_produto_variantes')->nullOnDelete();
        });

        DB::table($temporaryTable)->insertUsing(
            $table === 'loja_carrinho_itens'
                ? ['id', 'loja_carrinho_id', 'article_id', 'product_variant_id', 'loja_produto_id', 'loja_produto_variante_id', 'quantidade', 'preco_unitario', 'total_linha', 'created_at', 'updated_at']
                : ['id', 'loja_encomenda_id', 'article_id', 'product_variant_id', 'loja_produto_id', 'loja_produto_variante_id', 'descricao', 'quantidade', 'preco_unitario', 'total_linha', 'created_at', 'updated_at'],
            DB::table($table)->select(
                $table === 'loja_carrinho_itens'
                    ? ['id', 'loja_carrinho_id', 'article_id', 'product_variant_id', DB::raw('NULL as loja_produto_id'), DB::raw('NULL as loja_produto_variante_id'), 'quantidade', 'preco_unitario', 'total_linha', 'created_at', 'updated_at']
                    : ['id', 'loja_encomenda_id', 'article_id', 'product_variant_id', DB::raw('NULL as loja_produto_id'), DB::raw('NULL as loja_produto_variante_id'), 'descricao', 'quantidade', 'preco_unitario', 'total_linha', 'created_at', 'updated_at']
            )
        );

        Schema::drop($table);
        Schema::rename($temporaryTable, $table);

        DB::statement('PRAGMA foreign_keys=ON');
    }
};