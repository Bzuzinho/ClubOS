<?php

namespace App\Services\Desportivo;

use App\Models\AgeGroup;
use App\Models\Macrocycle;
use App\Models\Mesocycle;
use App\Models\Microcycle;
use App\Models\Season;
use App\Models\SportsObjective;
use App\Models\Training;
use App\Models\TrainingRecurrence;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class SportsPlanningWorkspaceService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly CreateTrainingAction $createTrainingAction,
        private readonly UpdateTrainingScheduleAction $updateTrainingScheduleAction,
        private readonly TrainingRecurrenceService $recurrenceService,
        private readonly SportsObjectiveService $objectiveService,
    ) {}

    public function createMacrocycle(array $data, User $actor): Macrocycle
    {
        $season = $this->seasonForWrite((string) $data['epoca_id']);
        $this->assertDatesWithin($data['data_inicio'], $data['data_fim'], $season->data_inicio, $season->data_fim, 'macrociclo');
        return Macrocycle::query()->create([
            'club_id' => $this->clubContext->id(),
            'epoca_id' => $season->id,
            'nome' => trim((string) $data['nome']),
            'tipo' => $data['tipo'] ?? 'Preparação geral',
            'data_inicio' => $data['data_inicio'],
            'data_fim' => $data['data_fim'],
            'objetivo_principal' => $data['objetivo_principal'] ?? null,
            'objetivo_secundario' => $data['objetivo_secundario'] ?? null,
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    public function updateMacrocycle(Macrocycle $macro, array $data, User $actor): Macrocycle
    {
        $this->assertTenant($macro);
        $season = $this->seasonForWrite((string) ($data['epoca_id'] ?? $macro->epoca_id));
        $start = $data['data_inicio'] ?? $macro->data_inicio->toDateString();
        $end = $data['data_fim'] ?? $macro->data_fim->toDateString();
        $this->assertDatesWithin($start, $end, $season->data_inicio, $season->data_fim, 'macrociclo');
        $macro->fill($data)->forceFill([
            'club_id' => $this->clubContext->id(),
            'epoca_id' => $season->id,
            'updated_by' => $actor->id,
        ])->save();
        return $macro->refresh();
    }

    public function archiveMacrocycle(Macrocycle $macro, User $actor): void
    {
        $this->assertTenant($macro);
        if (! $macro->mesocycles()->exists() && ! $macro->trainings()->exists() && ! $macro->recurrences()->exists()) {
            $macro->delete();
            return;
        }
        $macro->forceFill(['active' => false, 'archived_at' => now(), 'updated_by' => $actor->id])->save();
    }

    public function createMesocycle(array $data, User $actor): Mesocycle
    {
        $macro = $this->macroForWrite((string) $data['macrociclo_id']);
        $this->assertDatesWithin($data['data_inicio'], $data['data_fim'], $macro->data_inicio, $macro->data_fim, 'mesociclo');
        return Mesocycle::query()->create([
            'club_id' => $this->clubContext->id(),
            'macrociclo_id' => $macro->id,
            'nome' => trim((string) $data['nome']),
            'foco' => trim((string) ($data['objetivo_principal'] ?? $data['foco'] ?? 'Planeamento')),
            'data_inicio' => $data['data_inicio'],
            'data_fim' => $data['data_fim'],
            'objetivo_principal' => $data['objetivo_principal'] ?? null,
            'objetivo_secundario' => $data['objetivo_secundario'] ?? null,
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    public function updateMesocycle(Mesocycle $meso, array $data, User $actor): Mesocycle
    {
        $this->assertTenant($meso);
        $macro = $this->macroForWrite((string) ($data['macrociclo_id'] ?? $meso->macrociclo_id));
        $start = $data['data_inicio'] ?? $meso->data_inicio->toDateString();
        $end = $data['data_fim'] ?? $meso->data_fim->toDateString();
        $this->assertDatesWithin($start, $end, $macro->data_inicio, $macro->data_fim, 'mesociclo');
        if (isset($data['objetivo_principal'])) $data['foco'] = $data['objetivo_principal'];
        $meso->fill($data)->forceFill([
            'club_id' => $this->clubContext->id(),
            'macrociclo_id' => $macro->id,
            'updated_by' => $actor->id,
        ])->save();
        return $meso->refresh();
    }

    public function archiveMesocycle(Mesocycle $meso, User $actor): void
    {
        $this->assertTenant($meso);
        if (! $meso->microcycles()->exists() && ! $meso->trainings()->exists() && ! $meso->recurrences()->exists()) {
            $meso->delete();
            return;
        }
        $meso->forceFill(['active' => false, 'archived_at' => now(), 'updated_by' => $actor->id])->save();
    }

    public function createMicrocycle(array $data, User $actor): Microcycle
    {
        $meso = $this->mesoForWrite((string) $data['mesociclo_id']);
        $this->assertDatesWithin($data['data_inicio'], $data['data_fim'], $meso->data_inicio, $meso->data_fim, 'microciclo');
        return Microcycle::query()->create([
            'club_id' => $this->clubContext->id(),
            'mesociclo_id' => $meso->id,
            'semana' => trim((string) $data['semana']),
            'data_inicio' => $data['data_inicio'],
            'data_fim' => $data['data_fim'],
            'volume_previsto' => $data['volume_previsto'] ?? null,
            'objetivo_principal' => $data['objetivo_principal'] ?? null,
            'objetivo_secundario' => $data['objetivo_secundario'] ?? null,
            'is_recovery_week' => (bool) ($data['is_recovery_week'] ?? false),
            'notas' => $data['notas'] ?? null,
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    public function updateMicrocycle(Microcycle $micro, array $data, User $actor): Microcycle
    {
        $this->assertTenant($micro);
        $meso = $this->mesoForWrite((string) ($data['mesociclo_id'] ?? $micro->mesociclo_id));
        $start = $data['data_inicio'] ?? $micro->data_inicio?->toDateString();
        $end = $data['data_fim'] ?? $micro->data_fim?->toDateString();
        if ($start && $end) $this->assertDatesWithin($start, $end, $meso->data_inicio, $meso->data_fim, 'microciclo');
        $micro->fill($data)->forceFill([
            'club_id' => $this->clubContext->id(),
            'mesociclo_id' => $meso->id,
            'updated_by' => $actor->id,
        ])->save();
        return $micro->refresh();
    }

    public function archiveMicrocycle(Microcycle $micro, User $actor): void
    {
        $this->assertTenant($micro);
        if (! $micro->trainings()->exists() && ! $micro->recurrences()->exists()) {
            $micro->delete();
            return;
        }
        $micro->forceFill(['active' => false, 'archived_at' => now(), 'updated_by' => $actor->id])->save();
    }

    public function createSession(array $data, User $actor): Training
    {
        return $this->createTrainingAction->execute($this->normalizeSessionContext($data), $actor);
    }

    public function updateSession(Training $training, array $data, User $actor): Training
    {
        return $this->updateTrainingScheduleAction->execute($training, $this->normalizeSessionContext($data, $training), $actor);
    }

    public function createRecurrence(array $data, User $actor): TrainingRecurrence
    {
        return $this->recurrenceService->create($this->normalizeRecurrenceContext($data), $actor);
    }

    public function updateRecurrence(TrainingRecurrence $recurrence, array $data, User $actor): TrainingRecurrence
    {
        return $this->recurrenceService->update($recurrence, $this->normalizeRecurrenceContext($data, $recurrence), $actor);
    }

    public function archiveRecurrence(TrainingRecurrence $recurrence, User $actor): void
    {
        $this->recurrenceService->archive($recurrence, $actor);
    }

    public function generateRecurrence(TrainingRecurrence $recurrence, string $until, User $actor): array
    {
        return $this->recurrenceService->generateUntil($recurrence, $until, $actor);
    }

    public function createObjective(array $data, User $actor): SportsObjective
    {
        $targetType = (string) $data['target_type'];
        $targetId = $data['target_id'] ?? null;

        if ($targetType === 'season') {
            $this->assertTenant(Season::query()->findOrFail($targetId));
        }
        if ($targetType === 'age_group') {
            $ageGroup = AgeGroup::query()
                ->where('club_id', $this->clubContext->id())
                ->findOrFail($targetId);
            $this->assertTenant($ageGroup);
        }

        return $this->objectiveService->create([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'modality' => $data['modality'] ?? null,
            'status' => 'active',
            'starts_at' => $data['starts_at'] ?? null,
            'due_at' => $data['due_at'] ?? null,
        ], [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'objective_type' => $data['objective_type'] ?? 'text',
            'target_value' => $data['target_value'] ?? null,
            'target_text' => $data['target_text'] ?? null,
            'target_unit' => $data['target_unit'] ?? null,
            'visibility' => ['staff'],
            'notes' => $data['notes'] ?? null,
        ], $actor);
    }

    public function reviseObjective(SportsObjective $objective, array $data, User $actor): SportsObjective
    {
        return $this->objectiveService->revise($objective, [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'objective_type' => $data['objective_type'] ?? 'text',
            'target_value' => $data['target_value'] ?? null,
            'target_text' => $data['target_text'] ?? null,
            'target_unit' => $data['target_unit'] ?? null,
            'visibility' => ['staff'],
            'notes' => $data['notes'] ?? null,
        ], $actor);
    }

    private function normalizeSessionContext(array $data, ?Training $existing = null): array
    {
        $microId = $data['microciclo_id'] ?? $existing?->microciclo_id;
        if (! $microId) {
            throw ValidationException::withMessages(['microciclo_id' => 'O Microciclo é obrigatório no Planeamento.']);
        }

        $micro = Microcycle::forClub($this->clubContext->id())
            ->with('mesocycle.macrocycle.season')
            ->findOrFail($microId);
        $meso = $micro->mesocycle;
        $macro = $meso?->macrocycle;
        $season = $macro?->season;

        if (! $meso || ! $macro || ! $season) {
            throw ValidationException::withMessages(['microciclo_id' => 'O Microciclo não possui uma cadeia de periodização válida.']);
        }
        if (! empty($data['season_id']) && (string) $data['season_id'] !== (string) $season->id) {
            throw ValidationException::withMessages(['season_id' => 'O Microciclo não pertence à Época selecionada.']);
        }
        $this->seasonForWrite((string) $season->id);

        $data['microciclo_id'] = $micro->id;
        $data['mesociclo_id'] = $meso->id;
        $data['macrocycle_id'] = $macro->id;
        $data['epoca_id'] = $season->id;
        unset($data['season_id']);

        return $data;
    }

    private function normalizeRecurrenceContext(array $data, ?TrainingRecurrence $existing = null): array
    {
        $microId = $data['microcycle_id'] ?? $existing?->microcycle_id;
        if (! $microId) {
            throw ValidationException::withMessages(['microcycle_id' => 'O Microciclo é obrigatório na recorrência de Planeamento.']);
        }

        $micro = Microcycle::forClub($this->clubContext->id())
            ->with('mesocycle.macrocycle.season')
            ->findOrFail($microId);
        $meso = $micro->mesocycle;
        $macro = $meso?->macrocycle;
        $season = $macro?->season;

        if (! $meso || ! $macro || ! $season) {
            throw ValidationException::withMessages(['microcycle_id' => 'O Microciclo não possui uma cadeia de periodização válida.']);
        }
        if (! empty($data['season_id']) && (string) $data['season_id'] !== (string) $season->id) {
            throw ValidationException::withMessages(['season_id' => 'O Microciclo não pertence à Época selecionada.']);
        }
        $this->seasonForWrite((string) $season->id);

        $data['microcycle_id'] = $micro->id;
        $data['mesocycle_id'] = $meso->id;
        $data['macrocycle_id'] = $macro->id;
        $data['season_id'] = $season->id;

        return $data;
    }

    private function seasonForWrite(string $id): Season
    {
        $season = Season::query()
            ->where('club_id', $this->clubContext->id())
            ->findOrFail($id);
        if (in_array($season->status, ['closed', 'archived'], true)) {
            throw ValidationException::withMessages([
                'season' => 'A época está encerrada/arquivada. Reabra-a na Estrutura antes de alterar o planeamento.',
            ]);
        }
        return $season;
    }

    private function macroForWrite(string $id): Macrocycle
    {
        $macro = Macrocycle::forClub($this->clubContext->id())->findOrFail($id);
        $this->seasonForWrite((string) $macro->epoca_id);
        return $macro;
    }

    private function mesoForWrite(string $id): Mesocycle
    {
        $meso = Mesocycle::forClub($this->clubContext->id())->with('macrocycle')->findOrFail($id);
        $this->macroForWrite((string) $meso->macrociclo_id);
        return $meso;
    }

    private function assertDatesWithin(string $start, string $end, mixed $parentStart, mixed $parentEnd, string $label): void
    {
        $parentStartDate = is_object($parentStart) && method_exists($parentStart, 'toDateString')
            ? $parentStart->toDateString()
            : (string) $parentStart;
        $parentEndDate = is_object($parentEnd) && method_exists($parentEnd, 'toDateString')
            ? $parentEnd->toDateString()
            : (string) $parentEnd;

        if ($end < $start) {
            throw ValidationException::withMessages(['data_fim' => "A data de fim do {$label} não pode ser anterior ao início."]);
        }
        if ($start < $parentStartDate || $end > $parentEndDate) {
            throw ValidationException::withMessages(['data_inicio' => "O {$label} tem de ficar integralmente dentro do período pai."]);
        }
    }

    private function assertTenant(object $model): void
    {
        if ((string) ($model->club_id ?? '') !== $this->clubContext->id()) abort(404);
    }
}
