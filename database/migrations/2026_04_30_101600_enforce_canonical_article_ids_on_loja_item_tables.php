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
        $this->backfillCanonicalArticleIds('loja_carrinho_itens');
        $this->backfillCanonicalArticleIds('loja_encomenda_itens');

        $this->assertNoMissingCanonicalArticleIds('loja_carrinho_itens');
        $this->assertNoMissingCanonicalArticleIds('loja_encomenda_itens');

        $this->enforceCanonicalArticleId('loja_carrinho_itens');
        $this->enforceCanonicalArticleId('loja_encomenda_itens');
    }

    public function down(): void
    {
        $this->relaxCanonicalArticleId('loja_carrinho_itens');
        $this->relaxCanonicalArticleId('loja_encomenda_itens');
    }

    private function backfillCanonicalArticleIds(string $table): void
    {
        DB::table($table)
            ->whereNull('article_id')
            ->whereNotNull('loja_produto_id')
            ->orderBy('created_at')
            ->get()
            ->each(function ($item) use ($table) {
                $articleId = DB::table('product_catalog_migrations')
                    ->where('legacy_source', 'loja_produtos')
                    ->where('legacy_id', $item->loja_produto_id)
                    ->value('product_id');

                if (! $articleId) {
                    return;
                }

                DB::table($table)
                    ->where('id', $item->id)
                    ->update(['article_id' => $articleId]);
            });
    }

    private function assertNoMissingCanonicalArticleIds(string $table): void
    {
        $missingCount = DB::table($table)
            ->whereNull('article_id')
            ->count();

        if ($missingCount === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Cannot enforce canonical article_id on %s because %d rows are still missing article_id.',
            $table,
            $missingCount,
        ));
    }

    private function enforceCanonicalArticleId(string $table): void
    {
        Schema::table($table, function (Blueprint $table) {
            $table->dropForeign(['article_id']);
        });

        Schema::table($table, function (Blueprint $table) {
            $table->uuid('article_id')->nullable(false)->change();
        });

        Schema::table($table, function (Blueprint $table) {
            $table->foreign('article_id')->references('id')->on('products')->restrictOnDelete();
        });
    }

    private function relaxCanonicalArticleId(string $table): void
    {
        Schema::table($table, function (Blueprint $table) {
            $table->dropForeign(['article_id']);
        });

        Schema::table($table, function (Blueprint $table) {
            $table->uuid('article_id')->nullable()->change();
        });

        Schema::table($table, function (Blueprint $table) {
            $table->foreign('article_id')->references('id')->on('products')->nullOnDelete();
        });
    }
};