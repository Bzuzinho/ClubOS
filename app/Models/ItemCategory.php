<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemCategory extends Model
{
    use HasUuids;

    protected $table = 'item_categories';

    protected $fillable = [
        'codigo',
        'nome',
        'contexto',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    public function scopeForContext(Builder $query, ?string $contexto): Builder
    {
        if ($contexto === null || $contexto === '') {
            return $query;
        }

        return $query->where(function (Builder $subQuery) use ($contexto) {
            $subQuery->where('contexto', $contexto)
                ->orWhereNull('contexto');
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'categoria_id');
    }

    public function lojaHeroItems(): HasMany
    {
        return $this->hasMany(LojaHeroItem::class, 'categoria_id');
    }
}
