<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LojaHeroItem extends Model
{
    use HasUuids;

    public const DESTINO_PRODUTO = 'produto';
    public const DESTINO_CATEGORIA = 'categoria';
    public const DESTINO_URL = 'url';
    public const DESTINO_NENHUM = 'nenhum';

    protected $table = 'loja_hero_items';

    protected $fillable = [
        'titulo_curto',
        'titulo_principal',
        'descricao',
        'texto_botao',
        'tipo_destino',
        'article_id',
        'categoria_id',
        'url_destino',
        'imagem_desktop_path',
        'imagem_tablet_path',
        'imagem_mobile_path',
        'cor_fundo',
        'ativo',
        'ordem',
        'data_inicio',
        'data_fim',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'ordem' => 'integer',
        'data_inicio' => 'datetime',
        'data_fim' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    public function scopeVisibleNow(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $subQuery) {
                $subQuery->whereNull('data_inicio')->orWhere('data_inicio', '<=', now());
            })
            ->where(function (Builder $subQuery) {
                $subQuery->whereNull('data_fim')->orWhere('data_fim', '>=', now());
            });
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('ordem')->orderByDesc('created_at');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'article_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'categoria_id');
    }
}