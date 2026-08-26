<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasUuids, HasFactory;

    protected $fillable = [
        'nome',
        'descricao',
        'codigo',
        'slug',
        'categoria',
        'categoria_id',
        'preco',
        'preco_venda',
        'ultimo_custo',
        'stock',
        'stock_reservado',
        'stock_minimo',
        'area_armazenamento',
        'supplier_id',
        'imagem',
        'ativo',
        'visible_in_store',
        'destaque',
        'allow_sale',
        'allow_request',
        'allow_loan',
        'track_stock',
        'ordem',
        'variant_options',
    ];

    protected $casts = [
        'preco' => 'decimal:2',
        'preco_venda' => 'decimal:2',
        'ultimo_custo' => 'decimal:2',
        'stock' => 'integer',
        'stock_reservado' => 'integer',
        'stock_minimo' => 'integer',
        'ativo' => 'boolean',
        'visible_in_store' => 'boolean',
        'destaque' => 'boolean',
        'allow_sale' => 'boolean',
        'allow_request' => 'boolean',
        'allow_loan' => 'boolean',
        'track_stock' => 'boolean',
        'ordem' => 'integer',
        'variant_options' => 'array',
    ];

    protected $appends = [
        'is_low_stock',
        'available_stock',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $product) {
            if (! filled($product->slug)) {
                $product->slug = Str::slug($product->nome ?: $product->codigo ?: Str::random(8));
            }
        });
    }

    // Accessor: is_low_stock
    public function getIsLowStockAttribute(): bool
    {
        return $this->available_stock <= $this->stock_minimo;
    }

    public function getAvailableStockAttribute(): int
    {
        return (int) $this->stock - (int) ($this->stock_reservado ?? 0);
    }

    public function getSalePriceAttribute(): float
    {
        return (float) ($this->preco_venda ?? $this->preco ?? 0);
    }

    public function getTracksStockAttribute(): bool
    {
        return (bool) ($this->track_stock ?? true);
    }

    // Scope: active products
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    public function scopeVisibleInStore(Builder $query): Builder
    {
        return $query->where('visible_in_store', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('destaque', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('COALESCE(ordem, 999999) asc')->orderBy('nome');
    }

    public function scopeAllowSale(Builder $query): Builder
    {
        return $query->where('allow_sale', true);
    }

    public function scopeAllowRequest(Builder $query): Builder
    {
        return $query->where('allow_request', true);
    }

    public function scopeAllowLoan(Builder $query): Builder
    {
        return $query->where('allow_loan', true);
    }

    // Scope: low stock products
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('stock', '<=', 'stock_minimo');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'product_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'categoria_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'article_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    public function sponsorshipGoodsItems(): HasMany
    {
        return $this->hasMany(SponsorshipGoodsItem::class, 'item_id');
    }
}
