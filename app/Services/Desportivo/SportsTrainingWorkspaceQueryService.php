<?php

namespace App\Services\Desportivo;

use App\Contracts\Members\MemberSportsIdentityProvider;
use App\Models\Season;
use App\Models\Training;
use App\Models\TrainingPlanVersion;
use App\Models\TrainingSeries;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class SportsTrainingWorkspaceQueryService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly TrainingSessionReadinessService $readiness,
        private readonly MemberSportsIdentityProvider $identityProvider,
    ) {}

    public function payload(Request $request): array
    {
        $clubId = $this->clubContext->id();
        $seasons = Season::query()->where('club_id', $clubId)->orderByDesc('data_inicio')->get(['id','nome','ano_temporada','data_inicio','data_fim','status']);
        $season = $this->selectedSeason($seasons, $request->string('season_id')->toString());
        $sessions = collect();

        if ($season) {
            $sessions = Training::query()
                ->where('club_id', $clubId)->where('epoca_id', $season->id)->whereNotNull('data')
                ->with(['season','macrocycle','mesocycle','microcycle','responsibleCoach','venue','pool','recurrence','planVersion.plan','series.zone','series.stroke','sessionGroups.group','sessionGroups.planVersion.plan','sessionGroups.lanes.pool','athleteRecords.atleta','scheduleExceptions','contentRevisions.creator','cancelledBy','contentOverrideBy'])
                ->orderBy('data')->orderBy('hora_inicio')->get();
        }

        $latestVersions = $this->latestVersions($sessions);
        $people = $this->peopleNames($sessions);
        $rows = $sessions->map(fn (Training $training): array => $this->sessionRow($training, $latestVersions, $people))->values();
        $today = now()->toDateString(); $nextWeek = now()->addDays(7)->toDateString();

        return [
            'activeSeason' => $season ? ['id' => (string) $season->id,'name' => $season->nome,'year' => $season->ano_temporada] : null,
            'seasons' => $seasons->map(fn (Season $item): array => ['id' => (string) $item->id,'name' => $item->nome,'year' => $item->ano_temporada,'status' => $item->status])->values(),
            'sessions' => $rows,
            'stats' => [
                'today' => $rows->filter(fn (array $row): bool => $row['date'] === $today && $row['status'] !== 'cancelled')->count(),
                'attention' => $rows->where('readiness.status', 'attention')->count(),
                'decisions' => $rows->where('readiness.status', 'decision')->count(),
                'next7days' => $rows->filter(fn (array $row): bool => $row['date'] >= $today && $row['date'] <= $nextWeek && ! in_array($row['status'], ['cancelled','completed'], true))->count(),
            ],
        ];
    }

    private function latestVersions(Collection $sessions): array
    {
        $planIds = $sessions->flatMap(function (Training $training): array {
            $ids = [];
            if ($training->planVersion?->training_plan_id) $ids[] = (string) $training->planVersion->training_plan_id;
            foreach ($training->sessionGroups as $assignment) if ($assignment->planVersion?->training_plan_id) $ids[] = (string) $assignment->planVersion->training_plan_id;
            return $ids;
        })->unique()->values();
        if ($planIds->isEmpty()) return [];

        return TrainingPlanVersion::query()->where('club_id', $this->clubContext->id())->whereIn('training_plan_id', $planIds->all())->with('plan')->orderByDesc('version')->get()->unique('training_plan_id')->mapWithKeys(fn (TrainingPlanVersion $version): array => [(string) $version->training_plan_id => $version])->all();
    }

    private function peopleNames(Collection $sessions): array
    {
        $ids = $sessions->flatMap(function (Training $training): array {
            $ids = [];
            foreach (['responsavel_id','cancelled_by','content_override_by','plan_applied_by'] as $field) if ($training->{$field}) $ids[] = (string) $training->{$field};
            foreach ($training->athleteRecords as $record) $ids[] = (string) $record->user_id;
            foreach ($training->contentRevisions as $revision) if ($revision->created_by) $ids[] = (string) $revision->created_by;
            foreach ($training->scheduleExceptions as $exception) if ($exception->recorded_by) $ids[] = (string) $exception->recorded_by;
            return $ids;
        })->unique()->filter()->values();
        if ($ids->isEmpty()) return [];

        return User::query()->whereIn('id', $ids->all())->get()->mapWithKeys(function (User $user): array {
            $identity = $this->identityProvider->forSports($user); $name = trim((string) ($identity['display_name'] ?? ''));
            return [(string) $user->id => $name !== '' ? $name : (string) $user->id];
        })->all();
    }

    private function sessionRow(Training $training, array $latestVersions, array $people): array
    {
        $readiness = $this->readiness->evaluate($training, $latestVersions);
        $currentPlan = $training->planVersion;
        $latestGlobal = $currentPlan ? ($latestVersions[(string) $currentPlan->training_plan_id] ?? null) : null;

        return [
            'id' => (string) $training->id,'number' => $training->numero_treino,'date' => $training->data?->toDateString(),
            'start_time' => $this->time($training->hora_inicio),'end_time' => $this->time($training->hora_fim),'status' => $training->session_status,
            'training_type' => $training->tipo_treino,'volume_m' => (int) ($training->volume_planeado_m ?? 0),'instruction' => $training->instrucao,
            'description' => $training->descricao_treino,'notes' => $training->notas_gerais,'schedule_review_required' => (bool) $training->schedule_review_required,
            'schedule_conflicts' => $training->schedule_conflicts_snapshot ?? [],'readiness' => $readiness,
            'periodization' => ['season_id' => $training->epoca_id ? (string) $training->epoca_id : null,'season' => $training->season?->nome,'macro' => $training->macrocycle?->nome,'meso' => $training->mesocycle?->nome,'micro' => $training->microcycle?->semana,'micro_id' => $training->microciclo_id ? (string) $training->microciclo_id : null],
            'coach' => $training->responsavel_id ? ['id' => (string) $training->responsavel_id,'name' => $people[(string) $training->responsavel_id] ?? (string) $training->responsavel_id] : null,
            'location' => ['venue_id' => $training->sports_venue_id ? (string) $training->sports_venue_id : null,'venue' => $training->venue?->name ?? $training->local,'pool_id' => $training->sports_pool_id ? (string) $training->sports_pool_id : null,'pool' => $training->pool?->name,'pool_length_m' => $training->pool?->length_m],
            'recurrence' => $training->recurrence ? ['id' => (string) $training->recurrence->id,'name' => $training->recurrence->name,'occurrence_key' => $training->recurrence_occurrence_key] : null,
            'plan' => $currentPlan ? ['id' => (string) $currentPlan->id,'training_plan_id' => (string) $currentPlan->training_plan_id,'name' => $currentPlan->plan?->nome ?? $currentPlan->nome_snapshot,'version' => (int) $currentPlan->version,'latest' => $latestGlobal ? ['id' => (string) $latestGlobal->id,'version' => (int) $latestGlobal->version,'name' => $latestGlobal->plan?->nome ?? $latestGlobal->nome_snapshot] : null] : null,
            'content_overridden' => $training->content_override_at !== null,
            'content_override' => $training->content_override_at ? ['at' => $training->content_override_at?->toIso8601String(),'by' => $training->content_override_by ? ($people[(string) $training->content_override_by] ?? null) : null,'reason' => $training->content_override_reason] : null,
            'blocks' => $this->blocks($training->series),
            'groups' => $training->sessionGroups->map(function ($assignment) use ($latestVersions): array {
                $current = $assignment->planVersion; $latest = $current ? ($latestVersions[(string) $current->training_plan_id] ?? null) : null;
                return ['id' => (string) $assignment->id,'group_id' => (string) $assignment->training_group_id,'group_name' => $assignment->group?->name,'instruction' => $assignment->instruction,
                    'plan' => $current ? ['id' => (string) $current->id,'name' => $current->plan?->nome ?? $current->nome_snapshot,'version' => (int) $current->version,'latest_version' => $latest ? (int) $latest->version : null] : null,
                    'lanes' => $assignment->lanes->map(fn ($lane): array => ['id' => (string) $lane->id,'number' => $lane->lane_number,'name' => $lane->name,'capacity' => $lane->capacity,'planned_capacity' => $lane->pivot?->planned_capacity])->values()];
            })->values(),
            'athletes' => $training->athleteRecords->map(fn ($record): array => ['id' => (string) $record->user_id,'name' => $people[(string) $record->user_id] ?? (string) $record->user_id,'state' => $record->estado])->sortBy('name')->values(),
            'cancellation' => $training->cancelled_at ? ['at' => $training->cancelled_at?->toIso8601String(),'by' => $training->cancelled_by ? ($people[(string) $training->cancelled_by] ?? null) : null,'reason' => $training->cancellation_reason] : null,
            'history' => $this->history($training, $people),
        ];
    }

    private function blocks(Collection $series): array
    {
        return $series->groupBy(fn (TrainingSeries $line): string => ((int) ($line->block_order ?? 0)) . '|' . (string) ($line->block_name ?? $line->bloco ?? 'Treino'))
            ->map(function (Collection $lines): array {
                $first = $lines->first();
                return ['name' => $first->block_name ?? $first->bloco ?? 'Treino','order' => (int) ($first->block_order ?? 0),'rounds' => max(1, (int) ($first->block_rounds ?? 1)),
                    'series' => $lines->map(fn (TrainingSeries $line): array => ['id' => (string) $line->id,'repeticoes' => (int) ($line->repeticoes ?? 1),'distancia_m' => (int) ($line->distancia_m ?? 0),'distancia_total_m' => (int) ($line->distancia_total_m ?? 0),'exercicio' => $line->descricao_texto,'zona' => $line->zona_intensidade,'training_zone_config_id' => $line->training_zone_config_id ? (string) $line->training_zone_config_id : null,'estilo' => $line->estilo,'sports_stroke_id' => $line->sports_stroke_id ? (string) $line->sports_stroke_id : null,'intervalo' => $line->intervalo,'saida' => $line->saida,'timing_mode' => $line->timing_mode ?: 'none','material' => $line->material ?? [],'observacoes' => $line->observacoes,'source' => $line->source])->values()->all()];
            })->sortBy('order')->values()->all();
    }

    private function history(Training $training, array $people): array
    {
        $events = [['type' => 'created','at' => $training->created_at?->toIso8601String(),'title' => 'Sessão criada','detail' => $training->recurrence ? 'Ocorrência gerada/planeada.' : 'Sessão criada no Planeamento.','by' => null]];
        if ($training->plan_applied_at) $events[] = ['type' => 'plan_applied','at' => $training->plan_applied_at?->toIso8601String(),'title' => 'Plano aplicado à sessão','detail' => $training->planVersion ? sprintf('%s · v%d', $training->planVersion->plan?->nome ?? $training->planVersion->nome_snapshot, $training->planVersion->version) : 'Versão de plano aplicada.','by' => $training->plan_applied_by ? ($people[(string) $training->plan_applied_by] ?? null) : null];
        foreach ($training->contentRevisions as $revision) $events[] = ['type' => $revision->revision_type,'at' => $revision->created_at?->toIso8601String(),'title' => $revision->revision_type === 'plan_version' ? 'Versão do plano atualizada' : 'Snapshot técnico adaptado','detail' => $revision->reason,'by' => $revision->created_by ? ($people[(string) $revision->created_by] ?? null) : null];
        foreach ($training->scheduleExceptions as $exception) $events[] = ['type' => 'schedule_exception','at' => $exception->recorded_at?->toIso8601String(),'title' => 'Exceção operacional: ' . $exception->exception_type,'detail' => $exception->reason,'by' => $exception->recorded_by ? ($people[(string) $exception->recorded_by] ?? null) : null];
        if ($training->cancelled_at) $events[] = ['type' => 'cancelled','at' => $training->cancelled_at?->toIso8601String(),'title' => 'Sessão cancelada','detail' => $training->cancellation_reason,'by' => $training->cancelled_by ? ($people[(string) $training->cancelled_by] ?? null) : null];
        if ($training->completed_at) $events[] = ['type' => 'completed','at' => $training->completed_at?->toIso8601String(),'title' => 'Sessão concluída','detail' => 'Sessão fechada para alterações de preparação.','by' => null];
        return collect($events)->filter(fn (array $event): bool => ! empty($event['at']))->sortByDesc('at')->values()->all();
    }

    private function selectedSeason(Collection $seasons, string $requested): ?Season
    {
        if ($requested !== '') { $found = $seasons->firstWhere('id', $requested); if ($found) return $found; }
        return $seasons->firstWhere('status', 'active') ?? $seasons->first();
    }
    private function time(mixed $value): ?string { $value = trim((string) $value); return $value === '' ? null : substr($value, 0, 5); }
}
