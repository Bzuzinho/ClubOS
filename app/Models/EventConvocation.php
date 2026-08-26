<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventConvocation extends Model
{
    use HasUuids;

    protected $table = 'event_convocations';

    protected $fillable = [
        'evento_id',
        'user_id',
        'data_convocatoria',
        'estado_confirmacao',
        'data_resposta',
        'justificacao',
        'transporte_clube',
        'observacoes',
    ];

    protected $casts = [
        'data_convocatoria' => 'date',
        'data_resposta' => 'datetime',
        'transporte_clube' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
