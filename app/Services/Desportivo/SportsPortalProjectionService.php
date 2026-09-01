<?php

namespace App\Services\Desportivo;

use App\Models\Result;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class SportsPortalProjectionService
{
    public function __construct(private readonly SportsClubContext $clubContext) {}

    /**
     * Portal/dashboard projection over the canonical training spine.
     *
     * Existing attendance rows are returned as-is. Future eligible trainings
     * without an attendance row are represented by an unsaved TrainingAthlete
     * projection so a GET never materializes attendance state.
     *
     * @return Collection<int, TrainingAthlete>
     */
    public function trainingRecordsForUser(User $user): Collection
    {
        $existing = TrainingAthlete::query()
            ->with($this->trainingRelations())
            ->where('user_id', $user->id)
            ->whereHas('training', fn (Builder $query) => $query->where('club_id', $this->clubContext->id()))
            ->get()
            ->filter(fn (TrainingAthlete $record) => $record->training !== null)
            ->values();

        $ageGroupIds = $this->ageGroupIds($user);
        if ($ageGroupIds->isEmpty()) {
            return $this->sortTrainingRecords($existing);
        }

        $existingTrainingIds = $existing->pluck('treino_id')->filter()->map(fn ($id) => (string) $id)->all();

        $projected = $this->eligibleTrainingQuery($user)
            ->whereDate('data', '>=', now()->toDateString())
            ->when($existingTrainingIds !== [], fn (Builder $query) => $query->whereNotIn('id', $existingTrainingIds))
            ->with($this->trainingOnlyRelations())
            ->get()
            ->map(function (Training $training) use ($user): TrainingAthlete {
                $projection = new TrainingAthlete;
                $projection->forceFill([
                    'id' => (string) $training->id,
                    'treino_id' => (string) $training->id,
                    'user_id' => (string) $user->id,
                    'presente' => false,
                    'estado' => null,
                    'volume_real_m' => null,
                    'rpe' => null,
                    'observacoes_tecnicas' => null,
                ]);
                $projection->setRelation('training', $training);

                return $projection;
            });

        return $this->sortTrainingRecords($existing->concat($projected));
    }

    /**
     * Resolve the canonical attendance row targeted by an explicit portal write.
     * A row may be materialized only after the athlete explicitly confirms or
     * justifies a projected future training.
     */
    public function trainingAthleteForAction(User $user, string $reference, string $action): TrainingAthlete
    {
        $existing = TrainingAthlete::query()
            ->whereKey($reference)
            ->where('user_id', $user->id)
            ->whereHas('training', fn (Builder $query) => $query->where('club_id', $this->clubContext->id()))
            ->with($this->trainingRelations())
            ->first();

        if ($existing) {
            return $existing;
        }

        $belongsToAnotherAthleteInClub = TrainingAthlete::query()
            ->whereKey($reference)
            ->where('user_id', '!=', $user->id)
            ->whereHas('training', fn (Builder $query) => $query->where('club_id', $this->clubContext->id()))
            ->exists();

        abort_if($belongsToAnotherAthleteInClub, 403);
        abort_unless(in_array($action, ['confirm_presence', 'justify_absence'], true), 404);

        $training = $this->eligibleTrainingQuery($user)
            ->whereKey($reference)
            ->whereDate('data', '>=', now()->toDateString())
            ->firstOrFail();

        $record = TrainingAthlete::query()->firstOrCreate(
            [
                'treino_id' => $training->id,
                'user_id' => $user->id,
            ],
            [
                'presente' => false,
                'estado' => null,
                'volume_real_m' => null,
                'rpe' => null,
                'observacoes_tecnicas' => null,
                'registado_por' => $training->criado_por,
                'registado_em' => now(),
            ],
        );

        return $record->load($this->trainingRelations());
    }

    /**
     * @return Collection<int, Result>
     */
    public function resultsForUser(User $user): Collection
    {
        return Result::query()
            ->where('user_id', $user->id)
            ->whereHas('prova.competition', fn (Builder $query) => $query->where('club_id', $this->clubContext->id()))
            ->with(['prova.competition'])
            ->get();
    }

    /** @return Builder<Training> */
    private function eligibleTrainingQuery(User $user): Builder
    {
        $ageGroupIds = $this->ageGroupIds($user);

        return Training::query()
            ->where('club_id', $this->clubContext->id())
            ->where(function (Builder $query): void {
                $query->whereNull('session_status')
                    ->orWhere('session_status', '!=', 'cancelled');
            })
            ->whereHas('ageGroups', fn (Builder $query) => $query->whereIn('age_groups.id', $ageGroupIds->all()));
    }

    /** @return Collection<int, string> */
    private function ageGroupIds(User $user): Collection
    {
        return collect(is_array($user->escalao) ? $user->escalao : [$user->escalao])
            ->push($user->athleteSportsData?->escalao_id)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values();
    }

    /** @return array<int, string> */
    private function trainingRelations(): array
    {
        return [
            'training:id,numero_treino,data,hora_inicio,hora_fim,local,tipo_treino,volume_planeado_m,descricao_treino,notas_gerais,escaloes,club_id,criado_por,session_status',
            'training.ageGroups:id,nome',
            'training.series:id,treino_id,ordem,descricao_texto,distancia_total_m,zona_intensidade,estilo,repeticoes,intervalo,observacoes',
        ];
    }

    /** @return array<int, string> */
    private function trainingOnlyRelations(): array
    {
        return [
            'ageGroups:id,nome',
            'series:id,treino_id,ordem,descricao_texto,distancia_total_m,zona_intensidade,estilo,repeticoes,intervalo,observacoes',
        ];
    }

    /**
     * @param  Collection<int, TrainingAthlete>  $records
     * @return Collection<int, TrainingAthlete>
     */
    private function sortTrainingRecords(Collection $records): Collection
    {
        return $records
            ->sortBy(fn (TrainingAthlete $record) => sprintf(
                '%s %s',
                $record->training?->data?->toDateString() ?? '9999-12-31',
                $record->training?->hora_inicio ?? '23:59'
            ))
            ->values();
    }
}
