<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Motivos de Lesão (Configuração Desportiva)
 * 
 * Catálogo técnico para tipos/motivos de lesão de atletas
 * Ex: muscular, articular, tendinite, ombro, joelho, fadiga, outros
 */
class InjuryReasonConfig extends Model
{
    use HasUuids;

    protected $table = 'injury_reason_configs';

    protected $fillable = [
        'codigo',
        'nome',
        'nome_en',
        'descricao',
        'gravidade',
        'ativo',
        'ordem',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'ordem' => 'integer',
    ];

    public function scopeAtivo($query)
    {
        return $query->where('ativo', true);
    }

    public function scopeOrdenado($query)
    {
        return $query->orderBy('ordem');
    }

    /**
     * Scope por gravidade
     */
    public function scopePorGravidade($query, string $gravidade)
    {
        return $query->where('gravidade', $gravidade);
    }

    /**
     * Verifica se é lesão grave
     */
    public function isGrave(): bool
    {
        return $this->gravidade === 'grave';
    }

    /**
     * Verifica se é lesão leve
     */
    public function isLeve(): bool
    {
        return $this->gravidade === 'leve';
    }

    /**
     * Cor baseada na gravidade
     */
    public function getGravidadeColor(): string
    {
        return match ($this->gravidade) {
            'leve' => '#FCD34D',      // Amarelo
            'media' => '#F59E0B',     // Laranja
            'grave' => '#DC2626',     // Vermelho
            default => '#6B7280',     // Cinza
        };
    }
}
