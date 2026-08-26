<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Result extends Model
{
    use HasUuids;

    protected $fillable = [
        'prova_id',
        'user_id',
        'tempo_oficial',
        'posicao',
        'pontos_fina',
        'status',
        'desclassificado',
        'observacoes',
    ];

    protected $casts = [
        'tempo_oficial' => 'decimal:2',
        'posicao' => 'integer',
        'pontos_fina' => 'integer',
        'desclassificado' => 'boolean',
    ];

    public function prova(): BelongsTo
    {
        return $this->belongsTo(Prova::class, 'prova_id');
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(ResultSplit::class, 'resultado_id');
    }
}
