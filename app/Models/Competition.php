<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Competition extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'nome',
        'local',
        'data_inicio',
        'data_fim',
        'tipo',
        'status',
        'cancelled_at',
        'archived_at',
        'cancellation_reason',
        'created_by',
        'updated_by',
        // Compatibility projection pointer. Canonical ownership lives in
        // competition_event_projections.
        'evento_id',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'cancelled_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Competition $competition): void {
            $competition->club_id ??= trim((string) config('sports.club_id', 'bscn')) ?: 'bscn';
            $competition->status ??= 'scheduled';
        });
    }

    public function scopeForClub(Builder $query, string $clubId): Builder
    {
        return $query->where('club_id', $clubId);
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function eventProjection(): HasOne
    {
        return $this->hasOne(CompetitionEventProjection::class);
    }

    public function provas(): HasMany
    {
        return $this->hasMany(Prova::class, 'competicao_id');
    }

    public function results(): HasManyThrough
    {
        return $this->hasManyThrough(
            Result::class,
            Prova::class,
            'competicao_id',
            'prova_id',
            'id',
            'id'
        );
    }

    public function teamResults(): HasMany
    {
        return $this->hasMany(TeamResult::class, 'competicao_id');
    }
}
