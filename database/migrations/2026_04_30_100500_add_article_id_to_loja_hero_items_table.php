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
        Schema::table('loja_hero_items', function (Blueprint $table) {
            if (! Schema::hasColumn('loja_hero_items', 'article_id')) {
                $table->uuid('article_id')->nullable()->after('tipo_destino');
                $table->foreign('article_id')->references('id')->on('products')->nullOnDelete();
                $table->index('article_id');
            }
        });

        DB::table('loja_hero_items')
            ->orderBy('created_at')
            ->get()
            ->each(function ($item) {
                $articleId = null;

                if ($item->produto_id) {
                    $articleId = DB::table('product_catalog_migrations')
                        ->where('legacy_source', 'loja_produtos')
                        ->where('legacy_id', $item->produto_id)
                        ->value('product_id');
                }

                DB::table('loja_hero_items')
                    ->where('id', $item->id)
                    ->update(['article_id' => $articleId]);
            });
    }

    public function down(): void
    {
        DB::table('loja_hero_items')
            ->orderBy('created_at')
            ->get()
            ->each(function ($item) {
                $legacyId = $item->produto_id;

                if (! $legacyId && $item->article_id) {
                    $legacyId = DB::table('product_catalog_migrations')
                        ->where('legacy_source', 'loja_produtos')
                        ->where('product_id', $item->article_id)
                        ->value('legacy_id');
                }

                DB::table('loja_hero_items')
                    ->where('id', $item->id)
                    ->update(['produto_id' => $legacyId]);
            });

        Schema::table('loja_hero_items', function (Blueprint $table) {
            $table->dropForeign(['article_id']);
            $table->dropIndex(['article_id']);
            $table->dropColumn('article_id');
        });
    }
};