<?php

namespace App\Services\Desportivo;

use App\Models\Result;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsAthleteSeasonProfile;
use App\Models\SportsEvaluation;
use App\Models\TrainingAthlete;
use App\Models\TrainingGroupMembership;
use App\Models\User;
use App\Services\Members\MemberDocumentDataResolver;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Support\Carbon;

final class SportsAthletesWorkspaceService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly MemberIdentityDisplayResolver $identity,
        private readonly MemberDocumentDataResolver $documents,
        private readonly SportsMemberProfileQueryService $memberProfile,
        private readonly SportsAnalysisWorkspaceService $analysis,
    ) {
    }

    public function workspace(): array
    {
        $clubId = $this->clubContext->id();
        $today = Carbon::today();
        $from30 = $today->copy()->subDays(30);
        $fromYear = $today->copy()->subYear();

        $participations = SportsAthleteParticipation::query()
            ->forClub($clubId)
            ->with('modality')
            ->orderByDesc('active')
            ->orderByDesc('starts_at')
            ->get();

        $athleteIds = $participations->pluck('user_id')
            ->map('strval')
            ->filter()
            ->unique()
            ->values();

        if ($athleteIds->isEmpty()) {
            return $this->emptyWorkspace();
        }

        $users = User::query()
            ->whereIn('id', $athleteIds->all())
            ->get()
            ->keyBy(fn (User $user): string => (string) $user->id);
        $displayNames = $this->identity->mapDisplayNames($users);

        $participationsByAthlete = $participations->groupBy(fn (SportsAthleteParticipation $row): string => (string) $row->user_id);

        $membershipsByAthlete = TrainingGroupMembership::query()
            ->where('club_id', $clubId)
            ->whereIn('user_id', $athleteIds->all())
            ->activeOn($today)
            ->with(['group.modalityDefinition'])
            ->get()
            ->groupBy(fn (TrainingGroupMembership $row): string => (string) $row->user_id);

        $profilesByAthlete = SportsAthleteSeasonProfile::query()
            ->where('club_id', $clubId)
            ->whereIn('user_id', $athleteIds->all())
            ->with(['officialAgeGroup', 'season', 'modality'])
            ->whereHas('season', function ($query) use ($today): void {
                $query->where(function ($current) use ($today): void {
                    $current
                        ->where(function ($dated) use ($today): void {
                            $dated->whereDate('data_inicio', '<=', $today)
                                ->whereDate('data_fim', '>=', $today);
                        })
                        ->orWhere('status', 'active')
                        ->orWhere('estado', 'Em curso');
                });
            })
            ->orderByDesc('evaluated_at')
            ->get()
            ->groupBy(fn (SportsAthleteSeasonProfile $row): string => (string) $row->user_id);

        $trainingByAthlete = TrainingAthlete::query()
            ->whereIn('user_id', $athleteIds->all())
            ->whereHas('training', fn ($query) => $query
                ->where('club_id', $clubId)
                ->whereDate('data', '>=', $from30->toDateString())
                ->where('session_status', '!=', 'cancelled'))
            ->get()
            ->groupBy(fn (TrainingAthlete $row): string => (string) $row->user_id);

        $resultsByAthlete = Result::query()
            ->whereIn('user_id', $athleteIds->all())
            ->whereHas('prova.competition', fn ($query) => $query
                ->forClub($clubId)
                ->whereDate('data_inicio', '>=', $fromYear->toDateString()))
            ->get()
            ->groupBy(fn (Result $row): string => (string) $row->user_id);

        $evaluationsByAthlete = SportsEvaluation::query()
            ->whereIn('athlete_user_id', $athleteIds->all())
            ->where('state', 'completed')
            ->whereHas('campaign', fn ($query) => $query->where('club_id', $clubId))
            ->orderByDesc('completed_at')
            ->get()
            ->groupBy(fn (SportsEvaluation $row): string => (string) $row->athlete_user_id);

        $rows = $athleteIds->map(function (string $athleteId) use (
            $users,
            $displayNames,
            $participationsByAthlete,
            $membershipsByAthlete,
            $profilesByAthlete,
            $trainingByAthlete,
            $resultsByAthlete,
            $evaluationsByAthlete,
        ): ?array {
            $user = $users->get($athleteId);
            if (! $user) {
                return null;
            }

            $participationRows = $participationsByAthlete->get($athleteId, collect());
            $active = $participationRows->contains(fn (SportsAthleteParticipation $row): bool =>
                $row->active && $row->current_slot === 'current' && $row->ends_at === null
            );

            $activeModalities = $participationRows
                ->filter(fn (SportsAthleteParticipation $row): bool =>
                    $row->active && $row->current_slot === 'current' && $row->ends_at === null
                )
                ->map(fn (SportsAthleteParticipation $row): array => [
                    'id' => (string) $row->sports_modality_id,
                    'name' => (string) ($row->modality?->name ?? 'Modalidade'),
                ])
                ->unique('id')
                ->values();

            if ($activeModalities->isEmpty()) {
                $activeModalities = $participationRows
                    ->take(1)
                    ->map(fn (SportsAthleteParticipation $row): array => [
                        'id' => (string) $row->sports_modality_id,
                        'name' => (string) ($row->modality?->name ?? 'Modalidade'),
                    ]);
            }

            $groups = $membershipsByAthlete->get($athleteId, collect())
                ->map(fn (TrainingGroupMembership $row): array => [
                    'id' => (string) $row->training_group_id,
                    'name' => (string) ($row->group?->name ?? 'Grupo'),
                    'primary' => (bool) $row->is_primary,
                ])
                ->unique('id')
                ->sortByDesc('primary')
                ->values();

            $ageGroups = $profilesByAthlete->get($athleteId, collect())
                ->filter(fn (SportsAthleteSeasonProfile $row): bool => $row->official_age_group_id !== null)
                ->map(fn (SportsAthleteSeasonProfile $row): array => [
                    'id' => (string) $row->official_age_group_id,
                    'name' => (string) ($row->officialAgeGroup?->nome ?? 'Escalão'),
                    'modality_id' => (string) $row->sports_modality_id,
                ])
                ->unique(fn (array $row): string => $row['id'].'|'.$row['modality_id'])
                ->values();

            $trainingRows = $trainingByAthlete->get($athleteId, collect());
            $scheduled = $trainingRows->count();
            $presentRows = $trainingRows->filter(fn (TrainingAthlete $row): bool =>
                (bool) $row->presente || in_array($row->estado, ['presente', 'atrasado'], true)
            );
            $attendance = $scheduled > 0 ? round(($presentRows->count() / $scheduled) * 100, 1) : null;
            $volume = (int) $presentRows->sum(fn (TrainingAthlete $row): int => (int) ($row->volume_real_m ?? 0));
            $rpeRows = $trainingRows->filter(fn (TrainingAthlete $row): bool => $row->rpe !== null);
            $averageRpe = $rpeRows->isNotEmpty() ? round((float) $rpeRows->avg('rpe'), 2) : null;

            $resultRows = $resultsByAthlete->get($athleteId, collect());
            $podiums = $resultRows->filter(fn (Result $row): bool => in_array((int) $row->posicao, [1, 2, 3], true))->count();
            $latestEvaluation = $evaluationsByAthlete->get($athleteId, collect())->first();
            $medical = $this->documents->profileDocuments($user)['atestado'];

            return [
                'id' => $athleteId,
                'name' => $displayNames[$athleteId] ?? $this->identity->displayName($user),
                'member_number' => $user->numero_socio,
                'state' => $active ? 'active' : 'inactive',
                'modalities' => $activeModalities->all(),
                'groups' => $groups->all(),
                'age_groups' => $ageGroups->all(),
                'attendance_30d' => $attendance,
                'scheduled_30d' => $scheduled,
                'volume_30d_m' => $volume,
                'avg_rpe_30d' => $averageRpe,
                'podiums_12m' => $podiums,
                'latest_evaluation_score' => $latestEvaluation?->overall_score !== null
                    ? (float) $latestEvaluation->overall_score
                    : null,
                'medical_document' => [
                    'status' => ($medical['is_validated'] ?? false) ? 'validated' : 'pending',
                    'validated_at' => $this->dateString($medical['validated_at'] ?? null),
                ],
            ];
        })->filter()->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $activeRows = $rows->where('state', 'active');

        return [
            'athletes' => $rows->all(),
            'stats' => [
                'total' => $rows->count(),
                'active' => $activeRows->count(),
                'without_group' => $activeRows->filter(fn (array $row): bool => count($row['groups']) === 0)->count(),
                'low_attendance' => $activeRows->filter(fn (array $row): bool =>
                    $row['attendance_30d'] !== null && $row['attendance_30d'] < 50
                )->count(),
                'medical_pending' => $activeRows->where('medical_document.status', 'pending')->count(),
            ],
            'filters' => [
                'modalities' => $rows->flatMap(fn (array $row) => $row['modalities'])->unique('id')->sortBy('name')->values()->all(),
                'groups' => $rows->flatMap(fn (array $row) => $row['groups'])->unique('id')->sortBy('name')->values()->all(),
                'age_groups' => $rows->flatMap(fn (array $row) => $row['age_groups'])->unique('id')->sortBy('name')->values()->all(),
                'states' => [
                    ['id' => 'active', 'name' => 'Ativos'],
                    ['id' => 'inactive', 'name' => 'Inativos / históricos'],
                ],
            ],
            'principles' => [
                'canonical_participation' => true,
                'medical_document_owner' => 'membros',
                'legacy_medical_json_active' => false,
                'attendance_is_real_training_assignment' => true,
            ],
        ];
    }

    public function athlete(User $athlete): array
    {
        $clubId = $this->clubContext->id();
        $participations = SportsAthleteParticipation::query()
            ->forClub($clubId)
            ->where('user_id', $athlete->id)
            ->get();

        abort_if($participations->isEmpty(), 404);

        $active = $participations->contains(fn (SportsAthleteParticipation $row): bool =>
            $row->active && $row->current_slot === 'current' && $row->ends_at === null
        );
        $medical = $this->documents->profileDocuments($athlete)['atestado'];

        return [
            'athlete' => [
                'id' => (string) $athlete->id,
                'name' => $this->identity->displayName($athlete),
                'member_number' => $athlete->numero_socio,
                'state' => $active ? 'active' : 'inactive',
            ],
            'sports_profile' => $this->memberProfile->forMember($athlete),
            'analysis' => $active ? $this->analysis->athlete($athlete, 12) : null,
            'medical_document' => [
                'status' => ($medical['is_validated'] ?? false) ? 'validated' : 'pending',
                'validated_at' => $this->dateString($medical['validated_at'] ?? null),
            ],
        ];
    }

    private function emptyWorkspace(): array
    {
        return [
            'athletes' => [],
            'stats' => ['total' => 0, 'active' => 0, 'without_group' => 0, 'low_attendance' => 0, 'medical_pending' => 0],
            'filters' => ['modalities' => [], 'groups' => [], 'age_groups' => [], 'states' => [
                ['id' => 'active', 'name' => 'Ativos'],
                ['id' => 'inactive', 'name' => 'Inativos / históricos'],
            ]],
            'principles' => [
                'canonical_participation' => true,
                'medical_document_owner' => 'membros',
                'legacy_medical_json_active' => false,
                'attendance_is_real_training_assignment' => true,
            ],
        ];
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
