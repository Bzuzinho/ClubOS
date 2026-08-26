<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mesocycle extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id', 'macrociclo_id', 'nome', 'foco', 'data_inicio', 'data_fim',
        'objetivo_principal', 'objetivo_secundario', 'active', 'archived_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function macrocycle(): BelongsTo { return $this->belongsTo(Macrocycle::class, 'macrociclo_id'); }
    public function microcycles(): HasMany { return $this->hasMany(Microcycle::class, 'mesociclo_id')->orderBy('data_inicio'); }
    public function trainings(): HasMany { return $this->hasMany(Training::class, 'mesociclo_id'); }
    public function recurrences(): HasMany { return $this->hasMany(TrainingRecurrence::class, 'mesocycle_id'); }
    public function scopeForClub($query, string $clubId) { return $query->where('club_id', $clubId); }
}
