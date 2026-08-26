<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAttendance extends Model
{
    use HasUuids;

    protected $table = 'event_attendances';

    protected $fillable = [
        'evento_id',
        'user_id',
        'estado',
        'hora_chegada',
        'observacoes',
        'synced_from_training',
        'training_athlete_id',
        'registado_por',
        'registado_em',
        'provas',
    ];

    protected $casts = [
        'hora_chegada' => 'datetime',
        'synced_from_training' => 'boolean',
        'registado_em' => 'datetime',
        'provas' => 'array',
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

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registado_por');
    }
}
