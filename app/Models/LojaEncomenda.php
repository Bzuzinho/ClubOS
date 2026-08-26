<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LojaEncomenda extends Model
{
    use HasUuids;

    public const ESTADO_PENDENTE = 'pendente';
    public const ESTADO_APROVADO = 'aprovado';
    public const ESTADO_PREPARADO = 'preparado';
    public const ESTADO_ENTREGUE = 'entregue';
    public const ESTADO_CANCELADO = 'cancelado';

    protected $table = 'loja_encomendas';

    protected $fillable = [
        'numero',
        'user_id',
        'target_user_id',
        'estado',
        'subtotal',
        'total',
        'observacoes',
        'origem',
        'fatura_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->latest();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(LojaEncomendaItem::class, 'loja_encomenda_id');
    }
}