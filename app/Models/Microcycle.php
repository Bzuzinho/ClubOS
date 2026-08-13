<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Microcycle extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id', 'mesociclo_id', 'semana', 'data_inicio', 'data_fim',
        'volume_previsto', 'objetivo_principal', 'objetivo_secundario',
        'is_recovery_week', 'notas', 'active', 'archived_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'volume_previsto' => 'integer',
        'is_recovery_week' => 'boolean',
        'active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function mesocycle(): BelongsTo { return $this->belongsTo(Mesocycle::class, 'mesociclo_id'); }
    public function trainings(): HasMany { return $this->hasMany(Training::class, 'microciclo_id')->orderBy('data')->orderBy('hora_inicio'); }
    public function recurrences(): HasMany { return $this->hasMany(TrainingRecurrence::class, 'microcycle_id'); }
    public function scopeForClub($query, string $clubId) { return $query->where('club_id', $clubId); }
}
