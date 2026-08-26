<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Communication extends Model
{
    use HasUuids;

    protected $fillable = [
        'assunto',
        'mensagem',
        'tipo',
        'destinatarios',
        'estado',
        'agendado_para',
        'enviado_em',
        'total_enviados',
        'total_falhados',
    ];

    protected $casts = [
        'destinatarios' => 'array',
        'agendado_para' => 'datetime',
        'enviado_em' => 'datetime',
    ];

    /**
     * Scope to get sent communications
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->where('estado', 'enviada');
    }

    /**
     * Scope to get scheduled communications
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('estado', 'agendada');
    }

    /**
     * Scope to get draft communications
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('estado', 'rascunho');
    }

    /**
     * Scope to get failed communications
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('estado', 'falhou');
    }
}
