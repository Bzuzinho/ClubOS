<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Models\AthleteSportsData;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsAthleteSeasonProfile;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class SportsMemberStatusResolver
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    public function sportsProfile(User $user): ?AthleteSportsData
    {
        if ($user->relationLoaded('athleteSportsData')) {
            $profile = $user->getRelation('athleteSportsData');

            return $profile instanceof AthleteSportsData ? $profile : null;
        }

        return $user->athleteSportsData()->first();
    }

    public function sportsActivityActive(User $user): bool
    {
        if (Schema::hasTable('sports_athlete_participations')) {
            $query = SportsAthleteParticipation::query()
                ->where('club_id', $this->clubContext->id())
                ->where('user_id', $user->id);

            if ((clone $query)->exists()) {
                return (clone $query)->where('active', true)->whereNull('ends_at')->exists();
            }
        }

        $profile = $this->sportsProfile($user);

        if ($profile !== null) {
            return (bool) $profile->ativo;
        }

        return (bool) $user->ativo_desportivo;
    }

    public function officialAgeGroupId(User $user): ?string
    {
        if (Schema::hasTable('sports_athlete_season_profiles') && Schema::hasTable('sports_athlete_participations')) {
            $activeModalityIds = SportsAthleteParticipation::query()
                ->where('club_id', $this->clubContext->id())
                ->where('user_id', $user->id)
                ->where('active', true)
                ->whereNull('ends_at')
                ->pluck('sports_modality_id');

            if ($activeModalityIds->isNotEmpty()) {
                $profile = SportsAthleteSeasonProfile::query()
                    ->where('club_id', $this->clubContext->id())
                    ->where('user_id', $user->id)
                    ->whereIn('sports_modality_id', $activeModalityIds)
                    ->whereNotNull('official_age_group_id')
                    ->whereHas('season', function ($query): void {
                        $query->whereDate('data_inicio', '<=', today())
                            ->whereDate('data_fim', '>=', today());
                    })
                    ->orderByDesc('evaluated_at')
                    ->first();

                $profile ??= SportsAthleteSeasonProfile::query()
                    ->where('club_id', $this->clubContext->id())
                    ->where('user_id', $user->id)
                    ->whereIn('sports_modality_id', $activeModalityIds)
                    ->whereNotNull('official_age_group_id')
                    ->orderByDesc('evaluated_at')
                    ->first();

                if ($profile?->official_age_group_id) {
                    return (string) $profile->official_age_group_id;
                }
            }
        }

        $profile = $this->sportsProfile($user);

        if ($profile?->escalao_id) {
            return (string) $profile->escalao_id;
        }

        return collect($user->escalao ?? [])
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->first(static fn (string $value): bool => $value !== '') ?: null;
    }

    public function isActiveAthlete(User $user): bool
    {
        return (string) $user->estado === 'ativo'
            && $this->hasAthleteType($user)
            && $this->sportsActivityActive($user);
    }

    private function hasAthleteType(User $user): bool
    {
        $legacy = collect($user->tipo_membro ?? [])
            ->map(static fn (mixed $value): string => mb_strtolower(trim((string) $value)));

        if ($legacy->contains('atleta') || $legacy->contains('athlete')) {
            return true;
        }

        $user->loadMissing('userTypes:id,codigo,nome');

        return $user->userTypes->contains(function ($type): bool {
            $code = mb_strtolower(trim((string) ($type->codigo ?? '')));
            $name = mb_strtolower(trim((string) ($type->nome ?? '')));

            return in_array($code, ['atleta', 'athlete'], true)
                || in_array($name, ['atleta', 'athlete'], true);
        });
    }
}
