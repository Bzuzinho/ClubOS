<?php

namespace App\Http\Controllers;

use App\Models\TrainingAthlete;
use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Desportivo\SportsPortalProjectionService;
use App\Services\Desportivo\UpdateTrainingAthleteAction;
use App\Services\Family\FamilyService;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PortalTrainingController extends Controller
{
    public function index(
        Request $request,
        FamilyService $familyService,
        UserTypeAccessControlService $accessControlService,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $accessControl = $accessControlService->getCurrentUserAccess($user);
        $records = $this->trainingRecordsForUser($user);

        $upcoming = $records
            ->filter(fn (TrainingAthlete $record) => $record->training?->data?->isToday() || $record->training?->data?->isFuture())
            ->values();

        $completed = $records
            ->filter(fn (TrainingAthlete $record) => $record->training?->data?->isPast() || $record->training?->data?->isToday())
            ->values();

        $nextTraining = $upcoming->first();
        $latestTraining = $completed->sortByDesc(fn (TrainingAthlete $record) => $this->trainingSortKey($record))->first();
        $history = $completed->sortByDesc(fn (TrainingAthlete $record) => $this->trainingSortKey($record))->values()->take(5);
        $latestCoachNote = $completed
            ->filter(fn (TrainingAthlete $record) => filled($record->observacoes_tecnicas))
            ->sortByDesc(fn (TrainingAthlete $record) => $this->trainingSortKey($record))
            ->first();

        return Inertia::render('Portal/Trainings', [
            'user' => [
                'id' => $user->id,
                'name' => $this->displayName($user),
                'email' => $user->email,
            ],
            'perfil_tipos' => $this->resolveProfileLabels($user),
            'modulos_visiveis' => $accessControl['visibleMenuModules'] ?? [],
            'is_also_admin' => $familyService->userHasAdministratorProfile($user),
            'has_family' => $familyService->userHasFamily($user),
            'is_athlete' => $this->hasMemberType($user, 'atleta'),
            'summary' => $this->buildSummary($records),
            'next_training' => $nextTraining ? $this->mapTrainingRecord($nextTraining) : null,
            'upcoming_trainings' => $upcoming->take(6)->map(fn (TrainingAthlete $record) => $this->mapTrainingRecord($record))->values()->all(),
            'latest_training' => $latestTraining ? $this->mapTrainingRecord($latestTraining) : null,
            'history' => $history->map(fn (TrainingAthlete $record) => $this->mapTrainingRecord($record))->values()->all(),
            'latest_coach_note' => $latestCoachNote ? $this->mapCoachNote($latestCoachNote) : null,
        ]);
    }

    public function update(
        Request $request,
        string $trainingReference,
        UpdateTrainingAthleteAction $updateTrainingAthlete,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $action = $request->string('action')->trim()->value();
        $trainingAthlete = app(SportsPortalProjectionService::class)
            ->trainingAthleteForAction($user, $trainingReference, $action);

        $payload = match ($action) {
            'confirm_presence' => $this->confirmPresencePayload($trainingAthlete),
            'justify_absence' => $this->justifyAbsencePayload($trainingAthlete),
            'correct_volume' => $this->correctVolumePayload($request),
            default => abort(422, 'Ação de treino inválida.'),
        };

        $updateTrainingAthlete->execute($trainingAthlete, $payload, $user);

        return redirect()
            ->route('portal.trainings')
            ->with('success', 'Treino atualizado com sucesso.');
    }

    /**
     * @return Collection<int, TrainingAthlete>
     */
    private function trainingRecordsForUser(User $user): Collection
    {
        return app(SportsPortalProjectionService::class)
            ->trainingRecordsForUser($user);
    }

    private function buildSummary(Collection $records): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $monthRecords = $records->filter(function (TrainingAthlete $record) use ($monthStart, $monthEnd) {
            $trainingDate = $record->training?->data?->toDateString();

            return $trainingDate !== null && $trainingDate >= $monthStart && $trainingDate <= $monthEnd;
        });

        $confirmedCount = $monthRecords->filter(fn (TrainingAthlete $record) => $this->displayStatus($record)['key'] === 'confirmed')->count();
        $presentCount = $records->filter(fn (TrainingAthlete $record) => $this->displayStatus($record)['key'] === 'completed')->count();
        $eligibleAttendanceCount = $records->filter(fn (TrainingAthlete $record) => $this->displayStatus($record)['key'] !== 'pending')->count();
        $volumeMeters = (int) $records->sum(fn (TrainingAthlete $record) => $record->volume_real_m ?? 0);

        return [
            'trainings_this_month' => $monthRecords->count(),
            'confirmed_presence' => $confirmedCount,
            'volume_km' => number_format($volumeMeters / 1000, 1, ',', ' '),
            'attendance_rate' => $eligibleAttendanceCount > 0
                ? (int) round(($presentCount / $eligibleAttendanceCount) * 100)
                : 0,
        ];
    }

    private function mapTrainingRecord(TrainingAthlete $record): array
    {
        $training = $record->training;
        $status = $this->displayStatus($record);
        $plannedMeters = $training?->volume_planeado_m;
        $finalMeters = $record->volume_real_m;
        $completion = $plannedMeters > 0 && $finalMeters !== null
            ? max(0, min(100, (int) round(($finalMeters / $plannedMeters) * 100)))
            : null;

        return [
            'id' => $record->id,
            'training_id' => $record->treino_id,
            'date' => $training?->data?->toDateString(),
            'date_label' => $training?->data?->translatedFormat('j M') ?? 'Data por definir',
            'weekday_label' => $training?->data?->locale('pt_PT')->translatedFormat('l') ?? null,
            'time_label' => $this->formatTimeRange($training?->hora_inicio, $training?->hora_fim),
            'location' => $training?->local ?: 'Local por definir',
            'title' => $training?->descricao_treino ?: ($training?->tipo_treino ?: 'Treino agendado'),
            'description' => $training?->descricao_treino,
            'group_label' => $this->groupLabel($training?->ageGroups?->pluck('nome')->all() ?: $training?->escaloes),
            'planned_meters' => $plannedMeters,
            'planned_meters_label' => $this->formatMeters($plannedMeters),
            'final_meters' => $finalMeters,
            'final_meters_label' => $this->formatMeters($finalMeters),
            'completion_percent' => $completion,
            'coach_note' => $record->observacoes_tecnicas,
            'plan_note' => $training?->notas_gerais,
            'series' => $training?->series?->map(fn ($series) => [
                'id' => $series->id,
                'ordem' => $series->ordem,
                'descricao_texto' => $series->descricao_texto,
                'distancia_total_m' => $series->distancia_total_m,
                'zona_intensidade' => $series->zona_intensidade,
                'estilo' => $series->estilo,
                'repeticoes' => $series->repeticoes,
                'intervalo' => $series->intervalo,
                'observacoes' => $series->observacoes,
            ])->values()->all() ?? [],
            'status' => $status,
            'permissions' => [
                'can_confirm_presence' => $this->canConfirmPresence($record),
                'can_justify_absence' => $this->canJustifyAbsence($record),
                'can_correct_volume' => $this->canCorrectVolume($record),
            ],
        ];
    }

    private function mapCoachNote(TrainingAthlete $record): array
    {
        $training = $record->training;

        return [
            'id' => $record->id,
            'date_label' => $training?->data?->translatedFormat('j M Y') ?? 'Sem data',
            'context' => $training?->descricao_treino ?: ($training?->tipo_treino ?: 'Treino'),
            'note' => $record->observacoes_tecnicas,
        ];
    }

    private function displayStatus(TrainingAthlete $record): array
    {
        $status = strtolower(trim((string) ($record->estado ?? '')));
        $isPastOrToday = $record->training?->data?->isPast() || $record->training?->data?->isToday();
        $plannedMeters = (int) ($record->training?->volume_planeado_m ?? 0);
        $completedMeters = $record->volume_real_m;

        return match (true) {
            in_array($status, ['justificado', 'ausencia_justificada'], true) => [
                'key' => 'justified',
                'label' => 'Ausência justificada',
                'tone' => 'warning',
            ],
            $record->presente && $completedMeters !== null && $completedMeters === 0 => [
                'key' => 'not_completed',
                'label' => 'Não concluído',
                'tone' => 'danger',
            ],
            $record->presente && $completedMeters !== null && $plannedMeters > 0 && $completedMeters < $plannedMeters => [
                'key' => 'incomplete',
                'label' => 'Incompleto',
                'tone' => 'warning',
            ],
            $record->presente && $completedMeters !== null => [
                'key' => 'completed',
                'label' => 'Concluído',
                'tone' => 'success',
            ],
            in_array($status, ['presente', 'limitado'], true) || ($record->presente && ! $isPastOrToday) => [
                'key' => 'confirmed',
                'label' => 'Confirmado',
                'tone' => 'info',
            ],
            $isPastOrToday && ! $record->presente && in_array($status, ['ausente', 'doente', 'dispensado', 'lesionado'], true) => [
                'key' => 'absent',
                'label' => 'Ausente',
                'tone' => 'danger',
            ],
            default => [
                'key' => 'pending',
                'label' => 'Por confirmar',
                'tone' => 'neutral',
            ],
        };
    }

    private function canConfirmPresence(TrainingAthlete $record): bool
    {
        $trainingDate = $record->training?->data;

        if ($trainingDate === null || $trainingDate->isPast()) {
            return false;
        }

        return ! in_array($this->displayStatus($record)['key'], ['confirmed', 'completed', 'incomplete', 'not_completed'], true);
    }

    private function canJustifyAbsence(TrainingAthlete $record): bool
    {
        $trainingDate = $record->training?->data;

        if ($trainingDate === null || $trainingDate->isPast()) {
            return false;
        }

        return $this->displayStatus($record)['key'] !== 'justified';
    }

    private function canCorrectVolume(TrainingAthlete $record): bool
    {
        return $record->presente || in_array(strtolower((string) $record->estado), ['presente', 'limitado'], true);
    }

    private function confirmPresencePayload(TrainingAthlete $record): array
    {
        abort_unless($this->canConfirmPresence($record), 422, 'Não é possível confirmar presença neste treino.');

        return [
            'estado' => 'presente',
            'presente' => true,
        ];
    }

    private function justifyAbsencePayload(TrainingAthlete $record): array
    {
        abort_unless($this->canJustifyAbsence($record), 422, 'Não é possível justificar ausência neste treino.');

        return [
            'estado' => 'justificado',
            'presente' => false,
        ];
    }

    private function correctVolumePayload(Request $request): array
    {
        $validated = $request->validate([
            'volume_real_m' => ['required', 'integer', 'min:0', 'max:50000'],
        ]);

        return [
            'volume_real_m' => $validated['volume_real_m'],
            'estado' => 'presente',
            'presente' => true,
        ];
    }

    private function trainingSortKey(TrainingAthlete $record): string
    {
        $date = $record->training?->data?->toDateString() ?? '9999-12-31';
        $time = $record->training?->hora_inicio ?? '23:59';

        return sprintf('%s %s', $date, $time);
    }

    private function formatTimeRange(?string $startsAt, ?string $endsAt): string
    {
        if ($startsAt === null && $endsAt === null) {
            return 'Hora por definir';
        }

        if ($startsAt !== null && $endsAt !== null) {
            return sprintf('%s - %s', substr($startsAt, 0, 5), substr($endsAt, 0, 5));
        }

        return substr((string) ($startsAt ?? $endsAt), 0, 5);
    }

    private function groupLabel(mixed $ageGroups): string
    {
        $values = collect(is_array($ageGroups) ? $ageGroups : [$ageGroups])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->values();

        return $values->isNotEmpty() ? $values->join(' · ') : 'Grupo por definir';
    }

    private function formatMeters(?int $meters): string
    {
        if ($meters === null) {
            return 'Sem registo';
        }

        return number_format($meters, 0, ',', ' ').' m';
    }

    private function displayName(User $user): string
    {
        return app(MemberIdentityDisplayResolver::class)->displayNameOrFallback($user, $user->email ?: 'Utilizador');
    }

    /**
     * @return array<int, string>
     */
    private function resolveProfileLabels(User $user): array
    {
        return collect([
            $user->perfil,
        ])
            ->merge(is_array($user->tipo_membro) ? $user->tipo_membro : (array) $user->tipo_membro)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function hasMemberType(User $user, string $type): bool
    {
        $types = is_array($user->tipo_membro) ? $user->tipo_membro : (array) $user->tipo_membro;

        return collect($types)
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->contains(strtolower($type));
    }
}
