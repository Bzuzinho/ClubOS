<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

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
        // Legacy ingestion only. Canonical runtime ownership lives in
        // competition_event_projections; F7 services never write this field.
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

        // Conservative compatibility adapter for historical importers/tests
        // that still create a Competition with an explicit legacy Event id.
        // Active F7 application flows never use this direction.
        static::created(function (Competition $competition): void {
            $legacyEventId = $competition->getRawOriginal('evento_id');
            if (! filled($legacyEventId) || ! Schema::hasTable('events')) {
                return;
            }

            $event = Event::query()->find($legacyEventId);
            if (! $event) {
                return;
            }

            $safeLegacyEvent = true;

            if (Schema::hasTable('competition_event_projections')) {
                $claimed = CompetitionEventProjection::query()
                    ->where('event_id', $event->id)
                    ->where('competition_id', '!=', $competition->id)
                    ->exists();

                if ($claimed) {
                    $safeLegacyEvent = false;
                } else {
                    CompetitionEventProjection::query()->firstOrCreate(
                        [
                            'club_id' => (string) $competition->club_id,
                            'competition_id' => (string) $competition->id,
                        ],
                        [
                            'event_id' => (string) $event->id,
                            'legacy_event_id' => (string) $event->id,
                            'status' => 'linked',
                            'projected_at' => now(),
                        ]
                    );
                }
            }

            if ($safeLegacyEvent && Schema::hasTable('competition_finance_policies')) {
                $fee = $event->taxa_inscricao !== null ? max(0, round((float) $event->taxa_inscricao, 2)) : 0.0;

                CompetitionFinancePolicy::query()->firstOrCreate(
                    [
                        'club_id' => (string) $competition->club_id,
                        'competition_id' => (string) $competition->id,
                    ],
                    [
                        'payer_mode' => $fee > 0.009 ? 'athlete' : 'club',
                        'charge_mode' => $fee > 0.009 ? 'per_race' : 'none',
                        'per_race_amount' => $fee > 0.009 ? $fee : null,
                        'cost_center_id' => $event->centro_custo_id ?: null,
                        'active' => true,
                    ]
                );
            }
        });
    }

    public function scopeForClub(Builder $query, string $clubId): Builder
    {
        return $query->where('club_id', $clubId);
    }

    /**
     * Read-only compatibility alias. Prefer eventProjection at runtime.
     */
    public function getEventoIdAttribute(mixed $legacyValue): ?string
    {
        if (Schema::hasTable('competition_event_projections')) {
            $canonical = $this->relationLoaded('eventProjection')
                ? $this->eventProjection?->event_id
                : CompetitionEventProjection::query()
                    ->where('competition_id', $this->getKey())
                    ->value('event_id');

            if (filled($canonical)) {
                return (string) $canonical;
            }
        }

        return filled($legacyValue) ? (string) $legacyValue : null;
    }

    /** @deprecated Read-only compatibility relation; use eventProjection.event. */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function eventProjection(): HasOne
    {
        return $this->hasOne(CompetitionEventProjection::class);
    }

    public function financePolicy(): HasOne
    {
        return $this->hasOne(CompetitionFinancePolicy::class);
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
