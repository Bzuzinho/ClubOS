<?php

declare(strict_types=1);

namespace App\Services\Desportivo\Queries;

use App\Models\TrainingAthlete;
use App\Services\Desportivo\SportsClubContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class GetAthleteTrainingHistory
{
    public function __construct(private readonly SportsClubContext $clubContext) {}

    public function __invoke(string $athleteId, int $page = 1, ?string $seasonId = null): LengthAwarePaginator
    {
        $club = $this->clubContext->id();

        return TrainingAthlete::query()
            ->where('user_id', $athleteId)
            ->whereHas('training', fn ($query) => $query->where('club_id', $club))
            ->when($seasonId, fn ($query) => $query->whereHas('training', fn ($training) => $training->where('epoca_id', $seasonId)->whereHas('season', fn ($season) => $season->where('club_id', $club))))
            ->with(['training.season', 'training.ageGroups'])
            ->join('trainings', 'trainings.id', '=', 'training_athletes.treino_id')
            ->select('training_athletes.*')
            ->orderByDesc('trainings.data')
            ->orderByDesc('training_athletes.id')
            ->paginate(25, ['training_athletes.*'], 'page', $page)
            ->through(function (TrainingAthlete $participation) use ($club): array {
                $training = $participation->training;
                $season = $training->season;

                return [
                    'id' => (string) $participation->id,
                    'training_id' => (string) $training->id,
                    'numero_treino' => $training->numero_treino,
                    'data' => $training->data?->toDateString(),
                    'tipo_treino' => $training->tipo_treino,
                    'descricao_treino' => $training->descricao_treino,
                    'session_status' => $training->session_status,
                    'season' => $season && (string) $season->club_id === $club ? $season->nome : null,
                    'age_groups' => $training->ageGroups->where('club_id', $club)->pluck('nome')->values()->all(),
                    'attendance_status' => $participation->estado ?: ($participation->presente ? 'presente' : 'ausente'),
                ];
            });
    }
}
