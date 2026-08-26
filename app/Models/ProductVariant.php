<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use HasUuids;

    protected $table = 'product_variants';

    protected $fillable = [
        'product_id',
        'nome',
        'sku',
        'tamanho',
        'cor',
        'atributos_json',
        'preco_extra',
        'stock',
        'stock_reservado',
        'ativo',
    ];

    protected $casts = [
        'atributos_json' => 'array',
        'preco_extra' => 'decimal:2',
        'stock' => 'integer',
        'stock_reservado' => 'integer',
        'ativo' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function getAvailableStockAttribute(): int
    {
        return (int) $this->stock - (int) ($this->stock_reservado ?? 0);
    }

    public function getLabelAttribute(): string
    {
        return collect([$this->nome, $this->tamanho, $this->cor])
            ->filter(fn (?string $value) => filled($value))
            ->implode(' / ');
    }
}