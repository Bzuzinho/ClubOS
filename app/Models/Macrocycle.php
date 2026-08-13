<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Macrocycle extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id', 'epoca_id', 'nome', 'tipo', 'data_inicio', 'data_fim',
        'objetivo_principal', 'objetivo_secundario', 'escalao', 'active',
        'archived_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function season(): BelongsTo { return $this->belongsTo(Season::class, 'epoca_id'); }
    public function mesocycles(): HasMany { return $this->hasMany(Mesocycle::class, 'macrociclo_id')->orderBy('data_inicio'); }
    public function trainings(): HasMany { return $this->hasMany(Training::class, 'macrocycle_id'); }
    public function recurrences(): HasMany { return $this->hasMany(TrainingRecurrence::class, 'macrocycle_id'); }
    public function scopeForClub($query, string $clubId) { return $query->where('club_id', $clubId); }
}
