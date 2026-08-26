<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConvocationGroup extends Model
{
    use HasUuids;

    protected $table = 'convocation_groups';

    protected $fillable = [
        'id',
        'evento_id',
        'data_criacao',
        'criado_por',
        'atletas_ids',
        'hora_encontro',
        'local_encontro',
        'observacoes',
        'tipo_custo',
        'valor_por_salto',
        'valor_por_estafeta',
        'valor_inscricao_unitaria',
        'valor_inscricao_calculado',
        'movimento_id',
        'centro_custo_id',
        'publication_status',
        'publication_version',
        'published_at',
        'published_by',
        'published_fingerprint',
    ];

    protected $casts = [
        'atletas_ids' => 'array',
        'data_criacao' => 'datetime',
        'valor_por_salto' => 'decimal:2',
        'valor_por_estafeta' => 'decimal:2',
        'valor_inscricao_unitaria' => 'decimal:2',
        'valor_inscricao_calculado' => 'decimal:2',
        'publication_version' => 'integer',
        'published_at' => 'datetime',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function convocationAthletes(): HasMany
    {
        return $this->hasMany(ConvocationAthlete::class, 'convocatoria_grupo_id');
    }

    public function convocationMovements(): HasMany
    {
        return $this->hasMany(ConvocationMovement::class, 'convocatoria_grupo_id');
    }
}
