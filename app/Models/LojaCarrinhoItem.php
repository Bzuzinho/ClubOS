<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LojaCarrinhoItem extends Model
{
    use HasUuids;

    protected $table = 'loja_carrinho_itens';

    protected $fillable = [
        'loja_carrinho_id',
        'article_id',
        'product_variant_id',
        'quantidade',
        'preco_unitario',
        'total_linha',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'preco_unitario' => 'decimal:2',
        'total_linha' => 'decimal:2',
    ];

    public function carrinho(): BelongsTo
    {
        return $this->belongsTo(LojaCarrinho::class, 'loja_carrinho_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'article_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}