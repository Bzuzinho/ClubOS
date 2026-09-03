<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class AthleteSportsData extends Model
{
    use HasUuids;

    protected $table = 'athlete_sports_data';

    protected $fillable = [
        'user_id',
        'num_federacao',
        'cartao_federacao',
        'numero_pmb',
        'data_inscricao',
        'inscricao_path',
        'escalao_id',
        'escalao_calculado_id',
        'escalao_manual_override',
        'data_atestado_medico',
        'arquivo_atestado_medico',
        'informacoes_medicas',
        'ativo',
    ];

    protected $casts = [
        'data_inscricao' => 'date',
        'data_atestado_medico' => 'date',
        'arquivo_atestado_medico' => 'array',
        'escalao_manual_override' => 'boolean',
        'ativo' => 'boolean',
    ];

    /**
     * `athlete_sports_data` remains a compatibility projection. The official
     * age group is owned by the current canonical season profile and must not
     * disappear from the Members fiche when this projection is stale.
     */
    public function getEscalaoIdAttribute(mixed $value): ?string
    {
        $fallback = $value !== null && trim((string) $value) !== ''
            ? (string) $value
            : null;

        if (
            ! $this->exists
            || empty($this->user_id)
            || ! Schema::hasTable('sports_athlete_participations')
            || ! Schema::hasTable('sports_athlete_season_profiles')
        ) {
            return $fallback;
        }

        $participations = SportsAthleteParticipation::query()
            ->where('user_id', $this->user_id)
            ->where('active', true)
            ->whereNull('ends_at')
            ->get(['club_id', 'sports_modality_id']);

        if ($participations->isEmpty()) {
            return $fallback;
        }

        $clubIds = $participations->pluck('club_id')->filter()->unique()->values();
        $modalityIds = $participations->pluck('sports_modality_id')->filter()->unique()->values();

        $baseQuery = SportsAthleteSeasonProfile::query()
            ->where('user_id', $this->user_id)
            ->whereIn('club_id', $clubIds)
            ->whereIn('sports_modality_id', $modalityIds)
            ->whereNotNull('official_age_group_id');

        $profile = (clone $baseQuery)
            ->whereHas('season', function ($query): void {
                $query->whereDate('data_inicio', '<=', today())
                    ->whereDate('data_fim', '>=', today());
            })
            ->orderByDesc('evaluated_at')
            ->first();

        $profile ??= $baseQuery
            ->orderByDesc('evaluated_at')
            ->first();

        return $profile?->official_age_group_id
            ? (string) $profile->official_age_group_id
            : $fallback;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function atleta(): BelongsTo
    {
        return $this->user();
    }

    public function escalao(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class, 'escalao_id');
    }

    public function escalaoCalculado(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class, 'escalao_calculado_id');
    }
}
