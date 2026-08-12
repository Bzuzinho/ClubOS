<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Contracts\Desportivo\SportsAudienceProvider;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsAthleteSeasonProfile;
use App\Models\TrainingGroupCoach;
use App\Models\TrainingGroupMembership;
use Illuminate\Support\Carbon;

final class CanonicalSportsAudienceService implements SportsAudienceProvider
{
    public function __construct(private readonly SportsClubContext $clubContext)
    {
    }

    public function activeAthleteIds(): array
    {
        return SportsAthleteParticipation::query()
            ->forClub($this->clubContext->id())
            ->active()
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map('strval')
            ->values()
            ->all();
    }

    public function activeCoachIds(): array
    {
        $today = Carbon::today();

        return TrainingGroupCoach::query()
            ->where('club_id', $this->clubContext->id())
            ->whereDate('starts_at', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today);
            })
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map('strval')
            ->values()
            ->all();
    }

    public function trainingGroupMemberIds(string $trainingGroupId): array
    {
        $today = Carbon::today();

        return TrainingGroupMembership::query()
            ->where('club_id', $this->clubContext->id())
            ->where('training_group_id', $trainingGroupId)
            ->activeOn($today)
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map('strval')
            ->values()
            ->all();
    }

    public function officialAgeGroupMemberIds(array $ageGroupIds, ?string $seasonId = null): array
    {
        $ids = collect($ageGroupIds)
            ->filter(fn ($id): bool => is_string($id) && trim($id) !== '')
            ->map(fn (string $id): string => trim($id))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $today = Carbon::today();
        $query = SportsAthleteSeasonProfile::query()
            ->where('club_id', $this->clubContext->id())
            ->whereIn('official_age_group_id', $ids->all());

        if ($seasonId !== null && trim($seasonId) !== '') {
            $query->where('season_id', trim($seasonId));
        } else {
            $query->whereHas('season', function ($seasonQuery) use ($today): void {
                $seasonQuery->where(function ($current) use ($today): void {
                    $current
                        ->where(function ($dated) use ($today): void {
                            $dated->whereDate('data_inicio', '<=', $today)
                                ->whereDate('data_fim', '>=', $today);
                        })
                        ->orWhere('status', 'active')
                        ->orWhere('estado', 'Em curso');
                });
            });
        }

        return $query
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map('strval')
            ->values()
            ->all();
    }
}
