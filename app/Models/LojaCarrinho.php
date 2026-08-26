<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LojaCarrinho extends Model
{
    use HasUuids;

    public const ESTADO_ABERTO = 'aberto';
    public const ESTADO_CONVERTIDO = 'convertido';
    public const ESTADO_ABANDONADO = 'abandonado';

    protected $table = 'loja_carrinhos';

    protected $fillable = [
        'user_id',
        'estado',
        'observacoes',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('estado', self::ESTADO_ABERTO);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(LojaCarrinhoItem::class, 'loja_carrinho_id');
    }
}