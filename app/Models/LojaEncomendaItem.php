<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LojaEncomendaItem extends Model
{
    use HasUuids;

    protected $table = 'loja_encomenda_itens';

    protected $fillable = [
        'loja_encomenda_id',
        'article_id',
        'product_variant_id',
        'descricao',
        'quantidade',
        'preco_unitario',
        'total_linha',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'preco_unitario' => 'decimal:2',
        'total_linha' => 'decimal:2',
    ];

    public function encomenda(): BelongsTo
    {
        return $this->belongsTo(LojaEncomenda::class, 'loja_encomenda_id');
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