<?php

namespace App\Services\Loja;

use App\Models\LojaHeroItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LojaHeroService
{
    public function activeItems(): Collection
    {
        if (! Schema::hasTable('loja_hero_items')) {
            return new Collection();
        }

        return LojaHeroItem::query()
            ->with(['article', 'categoria'])
            ->active()
            ->visibleNow()
            ->ordered()
            ->get();
    }

    public function adminList(): Collection
    {
        if (! Schema::hasTable('loja_hero_items')) {
            return new Collection();
        }

        return LojaHeroItem::query()
            ->with(['article:id,nome,slug', 'categoria:id,nome'])
            ->ordered()
            ->get();
    }

    public function toggle(LojaHeroItem $item): LojaHeroItem
    {
        $item->update([
            'ativo' => ! $item->ativo,
        ]);

        return $item->fresh(['article', 'categoria']);
    }

    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $index => $id) {
                LojaHeroItem::query()
                    ->where('id', $id)
                    ->update(['ordem' => $index]);
            }
        });
    }
}