<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\LojaHeroItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductObserver
{
    public function saved(Product $product): void
    {
        if ((! $product->ativo || ! $product->visible_in_store || ! $product->allow_sale)
            && Schema::hasTable('loja_hero_items')) {
            LojaHeroItem::query()
                ->where('article_id', $product->id)
                ->where('ativo', true)
                ->update(['ativo' => false]);
        }

        $this->forgetCanonicalProductCachesAfterCommit();
    }

    public function deleted(Product $product): void
    {
        $this->forgetCanonicalProductCachesAfterCommit();
    }

    private function forgetCanonicalProductCachesAfterCommit(): void
    {
        DB::afterCommit(function (): void {
            Cache::forget('configuracoes:logistica');
            Cache::forget('configuracoes:index:eager');
            Cache::forget('financeiro:products');
            Cache::forget('dashboard:stats');
        });
    }
}
