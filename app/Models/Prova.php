<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prova extends Model
{
    use HasUuids;


    protected $fillable = [
        'competicao_id',
        'estilo',
        'distancia_m',
        'genero',
        'escalao_id',
        'ordem_prova',
    ];

    protected $casts = [
        'distancia_m' => 'integer',
        'ordem_prova' => 'integer',
    ];

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class, 'competicao_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(CompetitionRegistration::class, 'prova_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class, 'prova_id');
    }
}
