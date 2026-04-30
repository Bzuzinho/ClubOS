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
        $this->backfillCanonicalArticleId();

        $missingCanonicalProducts = DB::table('loja_hero_items')
            ->where('tipo_destino', 'produto')
            ->whereNull('article_id')
            ->count();

        if ($missingCanonicalProducts > 0) {
            throw new RuntimeException(sprintf(
                'Cannot drop loja_hero_items.produto_id because %d product hero items are still missing article_id.',
                $missingCanonicalProducts,
            ));
        }

        if (! Schema::hasColumn('loja_hero_items', 'produto_id')) {
            return;
        }

        Schema::table('loja_hero_items', function (Blueprint $table) {
            $table->dropForeign(['produto_id']);
            $table->dropColumn('produto_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('loja_hero_items', 'produto_id')) {
            Schema::table('loja_hero_items', function (Blueprint $table) {
                $table->uuid('produto_id')->nullable()->after('article_id');
                $table->foreign('produto_id')->references('id')->on('loja_produtos')->nullOnDelete();
            });
        }

        DB::table('loja_hero_items')
            ->whereNull('produto_id')
            ->whereNotNull('article_id')
            ->orderBy('created_at')
            ->get()
            ->each(function ($item) {
                $legacyProductId = DB::table('product_catalog_migrations')
                    ->where('legacy_source', 'loja_produtos')
                    ->where('product_id', $item->article_id)
                    ->value('legacy_id');

                if (! $legacyProductId) {
                    return;
                }

                DB::table('loja_hero_items')
                    ->where('id', $item->id)
                    ->update(['produto_id' => $legacyProductId]);
            });
    }

    private function backfillCanonicalArticleId(): void
    {
        if (! Schema::hasColumn('loja_hero_items', 'produto_id')) {
            return;
        }

        DB::table('loja_hero_items')
            ->where('tipo_destino', 'produto')
            ->whereNull('article_id')
            ->whereNotNull('produto_id')
            ->orderBy('created_at')
            ->get()
            ->each(function ($item) {
                $articleId = DB::table('product_catalog_migrations')
                    ->where('legacy_source', 'loja_produtos')
                    ->where('legacy_id', $item->produto_id)
                    ->value('product_id');

                if (! $articleId) {
                    return;
                }

                DB::table('loja_hero_items')
                    ->where('id', $item->id)
                    ->update(['article_id' => $articleId]);
            });
    }
};