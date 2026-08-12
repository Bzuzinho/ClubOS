<?php

namespace App\Services\Desportivo;

use App\Contracts\Members\MemberSportsIdentityProvider;
use App\Models\AgeGroup;
use App\Models\Competition;
use App\Models\Macrocycle;
use App\Models\Season;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsObjective;
use App\Models\SportsVenue;
use App\Models\Training;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupCoach;
use App\Models\TrainingPlanVersion;
use App\Models\TrainingRecurrence;
use App\Models\User;
use Illuminate\Http\Request;

final class SportsPlanningWorkspaceQueryService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly MemberSportsIdentityProvider $identityProvider,
    ) {}

    public function payload(Request $request): array
    {
        $clubId = $this->clubContext->id();
        $seasons = Season::query()
            ->where('club_id', $clubId)
            ->with(['modality', 'programs.program'])
            ->orderByDesc('data_inicio')
            ->get();
        $selected = $this->selectedSeason($seasons, $request->string('season_id')->toString());

        $macrocycles = collect();
        $sessions = collect();
        $recurrences = collect();
        $groups = collect();
        $athletes = [];
        $coaches = [];

        if ($selected) {
            $macrocycles = Macrocycle::query()
                ->where('club_id', $clubId)
                ->where('epoca_id', $selected->id)
                ->with([
                    'mesocycles' => fn ($q) => $q->orderBy('data_inicio'),
                    'mesocycles.microcycles' => fn ($q) => $q->orderBy('data_inicio'),
                ])
                ->orderBy('data_inicio')
                ->get();

            $sessions = Training::query()
                ->where('club_id', $clubId)
                ->where('epoca_id', $selected->id)
                ->with([
                    'macrocycle', 'mesocycle', 'microcycle', 'venue', 'pool',
                    'responsibleCoach', 'planVersion.plan',
                    'sessionGroups.group', 'sessionGroups.planVersion', 'sessionGroups.lanes.pool',
                    'athleteRecords:id,treino_id,user_id',
                ])
                ->withCount('athleteRecords')
                ->orderBy('data')
                ->orderBy('hora_inicio')
                ->get();

            $recurrences = TrainingRecurrence::query()
                ->where('club_id', $clubId)
                ->where('season_id', $selected->id)
                ->with([
                    'macrocycle', 'mesocycle', 'microcycle', 'venue', 'pool',
                    'responsibleCoach', 'planVersion.plan',
                    'groups.group', 'groups.planVersion', 'groups.lanes.pool',
                ])
                ->orderBy('starts_on')
                ->orderBy('start_time')
                ->get();

            $groups = TrainingGroup::query()
                ->where('club_id', $clubId)
                ->where('sports_modality_id', $selected->sports_modality_id)
                ->where('active', true)
                ->whereHas('seasonConfigurations', fn ($q) => $q
                    ->where('season_id', $selected->id)
                    ->where('active', true))
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'sports_modality_id']);

            $athletes = $this->athleteOptions((string) $selected->sports_modality_id);
            $coaches = $this->coachOptions((string) $selected->id);
        }

        $locations = SportsVenue::query()
            ->where('club_id', $clubId)
            ->where('active', true)
            ->with([
                'pools' => fn ($q) => $q->where('active', true)->orderBy('name'),
                'pools.lanes' => fn ($q) => $q->where('active', true)->orderBy('lane_number'),
            ])
            ->orderBy('name')
            ->get();

        $ageGroups = AgeGroup::query()
            ->where('club_id', $clubId)
            ->where('ativo', true)
            ->orderBy('idade_minima')
            ->orderBy('nome')
            ->get(['id', 'nome']);

        $plans = TrainingPlanVersion::query()
            ->where('club_id', $clubId)
            ->with('plan')
            ->whereHas('plan', fn ($q) => $q->where('estado', '!=', 'arquivado'))
            ->orderByDesc('version')
            ->get()
            ->unique('training_plan_id')
            ->values();

        $objectives = $selected
            ? SportsObjective::query()
                ->where('club_id', $clubId)
                ->where(function ($q) use ($selected): void {
                    $q->where(fn ($season) => $season
                        ->where('target_type', 'season')
                        ->where('target_id', $selected->id))
                      ->orWhere('target_type', 'age_group');
                })
                ->with('latestVersion')
                ->orderBy('due_at')
                ->get()
            : collect();

        $competitions = $selected
            ? Competition::forClub($clubId)
                ->whereDate('data_inicio', '>=', $selected->data_inicio)
                ->whereDate('data_inicio', '<=', $selected->data_fim)
                ->whereNotIn('status', ['cancelled', 'archived'])
                ->orderBy('data_inicio')
                ->get(['id', 'nome', 'data_inicio', 'data_fim', 'local', 'status'])
            : collect();

        return [
            'seasons' => $seasons,
            'selectedSeason' => $selected,
            'macrocycles' => $macrocycles,
            'sessions' => $sessions,
            'recurrences' => $recurrences,
            'groups' => $groups,
            'athletes' => $athletes,
            'coaches' => $coaches,
            'locations' => $locations,
            'planVersions' => $plans,
            'objectives' => $objectives,
            'competitions' => $competitions,
            'ageGroups' => $ageGroups,
        ];
    }

    private function selectedSeason($seasons, string $requested): ?Season
    {
        if ($requested !== '') {
            $found = $seasons->firstWhere('id', $requested);
            if ($found) return $found;
        }
        return $seasons->firstWhere('status', 'active') ?? $seasons->first();
    }

    private function athleteOptions(string $modalityId): array
    {
        $ids = SportsAthleteParticipation::query()
            ->where('club_id', $this->clubContext->id())
            ->where('sports_modality_id', $modalityId)
            ->active()
            ->pluck('user_id')
            ->unique()
            ->values();
        return $this->people($ids->all());
    }

    private function coachOptions(string $seasonId): array
    {
        $ids = TrainingGroupCoach::query()
            ->where('club_id', $this->clubContext->id())
            ->whereHas('seasonContext', fn ($q) => $q->where('season_id', $seasonId))
            ->pluck('user_id')
            ->unique()
            ->values();
        return $this->people($ids->all());
    }

    private function people(array $ids): array
    {
        if ($ids === []) return [];

        return User::query()
            ->whereIn('id', $ids)
            ->get()
            ->map(function (User $user): array {
                $identity = $this->identityProvider->forSports($user);
                return [
                    'id' => (string) $user->id,
                    'name' => (string) ($identity['display_name'] ?? $user->name ?? $user->nome_completo ?? $user->id),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }
}
