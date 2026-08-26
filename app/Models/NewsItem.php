<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsItem extends Model
{
    use HasUuids;

    protected $table = 'news_items';

    protected $fillable = [
        'titulo',
        'conteudo',
        'imagem',
        'destaque',
        'autor',
        'data_publicacao',
        'categorias',
    ];

    protected $casts = [
        'data_publicacao' => 'datetime',
        'destaque' => 'boolean',
        'categorias' => 'array',
    ];

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor');
    }
}
