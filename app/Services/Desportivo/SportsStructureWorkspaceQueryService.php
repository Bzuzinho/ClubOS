<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Contracts\Members\MemberSportsIdentityProvider;
use App\Models\AgeGroup;
use App\Models\AthleteAgeGroupOverride;
use App\Models\Season;
use App\Models\SeasonAgeGroupRule;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsCoachRole;
use App\Models\SportsModality;
use App\Models\SportsProgram;
use App\Models\SportsVenue;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupCoach;
use App\Models\TrainingGroupMembership;
use App\Models\TrainingGroupSeason;
use App\Models\User;
use Illuminate\Support\Collection;

final class SportsStructureWorkspaceQueryService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly MemberSportsIdentityProvider $memberIdentity,
    ) {
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        $clubId = $this->clubContext->id();

        $modalities = SportsModality::forClub($clubId)->with('programs')->orderByDesc('active')->orderBy('name')->get();
        $programs = SportsProgram::forClub($clubId)->with('modality')->orderByDesc('active')->orderBy('name')->get();
        $seasons = Season::query()->where('club_id', $clubId)
            ->with(['modality', 'programs.program'])
            ->orderByDesc('data_inicio')->get();
        $ageGroups = AgeGroup::query()->where('club_id', $clubId)
            ->orderByDesc('ativo')->orderBy('idade_minima')->orderBy('nome')->get();
        $ageGroupRules = SeasonAgeGroupRule::query()->where('club_id', $clubId)
            ->with(['season', 'modality', 'ageGroup'])
            ->orderByDesc('active')->orderByDesc('priority')->get();
        $overrides = AthleteAgeGroupOverride::query()->where('club_id', $clubId)
            ->where('active', true)->count();

        $groups = TrainingGroup::forClub($clubId)
            ->with(['modalityDefinition', 'ageGroups', 'seasonConfigurations.program', 'seasonConfigurations.season'])
            ->orderByDesc('active')->orderBy('name')->get();
        $groupSeasons = TrainingGroupSeason::query()->where('club_id', $clubId)
            ->with(['group', 'season', 'program'])->orderByDesc('active')->get();

        $memberships = TrainingGroupMembership::query()->where('club_id', $clubId)
            ->with(['group', 'seasonContext.season', 'seasonContext.program', 'athlete.dadosPessoais'])
            ->orderByDesc('starts_at')->get();
        $coachAssignments = TrainingGroupCoach::query()->where('club_id', $clubId)
            ->with(['group', 'seasonContext.season', 'roleDefinition', 'coach.dadosPessoais'])
            ->orderByDesc('starts_at')->get();

        $athleteUsers = SportsAthleteParticipation::query()
            ->where('club_id', $clubId)
            ->active()
            ->with(['athlete.dadosPessoais', 'modality'])
            ->get()
            ->pluck('athlete')
            ->filter()
            ->unique('id')
            ->values();

        $coachIds = TrainingGroupCoach::query()->where('club_id', $clubId)->pluck('user_id');
        $coachUsers = User::query()
            ->where(function ($query) use ($coachIds): void {
                $query->whereHas('userTypes', fn ($type) => $type->whereIn('codigo', ['treinador', 'coach', 'tecnico']))
                    ->orWhereIn('id', $coachIds);
            })
            ->with('dadosPessoais')
            ->orderBy('id')
            ->get();

        return [
            'modalities' => $modalities,
            'programs' => $programs,
            'seasons' => $seasons,
            'ageGroups' => $ageGroups,
            'ageGroupRules' => $ageGroupRules,
            'activeAgeGroupOverridesCount' => $overrides,
            'groups' => $groups,
            'groupSeasons' => $groupSeasons,
            'memberships' => $memberships->map(fn (TrainingGroupMembership $row) => [
                'id' => (string) $row->id,
                'training_group_id' => (string) $row->training_group_id,
                'training_group_season_id' => $row->training_group_season_id,
                'user_id' => (string) $row->user_id,
                'athlete_name' => $row->athlete ? $this->memberIdentity->forSports($row->athlete)['display_name'] : 'Atleta',
                'group_name' => $row->group?->name,
                'season_name' => $row->seasonContext?->season?->nome,
                'program_name' => $row->seasonContext?->program?->name,
                'is_primary' => (bool) $row->is_primary,
                'starts_at' => optional($row->starts_at)->toDateString(),
                'ends_at' => optional($row->ends_at)->toDateString(),
                'notes' => $row->notes,
                'active' => $row->starts_at?->lte(today()) && ($row->ends_at === null || $row->ends_at->gte(today())),
            ])->values(),
            'coachRoles' => SportsCoachRole::forClub($clubId)->orderByDesc('active')->orderBy('name')->get(),
            'coachAssignments' => $coachAssignments->map(fn (TrainingGroupCoach $row) => [
                'id' => (string) $row->id,
                'training_group_id' => (string) $row->training_group_id,
                'training_group_season_id' => $row->training_group_season_id,
                'user_id' => (string) $row->user_id,
                'coach_name' => $row->coach ? $this->memberIdentity->forSports($row->coach)['display_name'] : 'Técnico',
                'group_name' => $row->group?->name,
                'season_name' => $row->seasonContext?->season?->nome,
                'sports_coach_role_id' => $row->sports_coach_role_id,
                'role_name' => $row->roleDefinition?->name ?? $row->role,
                'starts_at' => optional($row->starts_at)->toDateString(),
                'ends_at' => optional($row->ends_at)->toDateString(),
                'active' => $row->starts_at?->lte(today()) && ($row->ends_at === null || $row->ends_at->gte(today())),
            ])->values(),
            'athletes' => $this->peoplePayload($athleteUsers),
            'coaches' => $this->peoplePayload($coachUsers),
            'locations' => SportsVenue::forClub($clubId)
                ->with(['pools.lanes'])
                ->orderByDesc('active')->orderBy('name')->get(),
        ];
    }

    /** @param Collection<int,User> $users */
    private function peoplePayload(Collection $users): array
    {
        return $users->map(fn (User $user) => [
            'id' => (string) $user->id,
            'name' => $this->memberIdentity->forSports($user)['display_name'],
        ])->values()->all();
    }
}
