<?php

namespace App\Services\Desportivo\Queries;

use App\Models\Training;
use App\Services\Desportivo\SportsClubContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class GetTrainingCalendarView
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function __invoke(mixed $from, mixed $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'to' => 'A data final do calendário não pode ser anterior à data inicial.',
            ]);
        }

        if ((int) $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages([
                'to' => 'A vista de calendário está limitada a 366 dias por consulta.',
            ]);
        }

        return Training::query()
            ->where('club_id', $this->clubContext->id())
            ->whereBetween('data', [$start->toDateString(), $end->toDateString()])
            ->with([
                'venue:id,name,venue_type',
                'recurrence:id,name',
                'responsibleCoach:id,nome_completo',
                'sessionGroups.group:id,name,code',
                'sessionGroups.planVersion:id,training_plan_id,version,nome_snapshot',
                'sessionGroups.lanes:id,sports_venue_id,name,lane_number,capacity',
            ])
            ->withCount('athleteRecords')
            ->orderBy('data')
            ->orderBy('hora_inicio')
            ->get()
            ->map(function (Training $training): array {
                return [
                    'id' => (string) $training->id,
                    'numero_treino' => $training->numero_treino,
                    'data' => $training->data?->toDateString(),
                    'hora_inicio' => $training->hora_inicio,
                    'hora_fim' => $training->hora_fim,
                    'session_status' => $training->session_status,
                    'tipo_treino' => $training->tipo_treino,
                    'venue' => $training->venue,
                    'local' => $training->local,
                    'responsible_coach' => $training->responsibleCoach,
                    'recurrence' => $training->recurrence,
                    'groups' => $training->sessionGroups,
                    'athlete_count' => (int) $training->athlete_records_count,
                    'schedule_review_required' => (bool) $training->schedule_review_required,
                    'schedule_conflicts' => $training->schedule_conflicts_snapshot ?? [],
                ];
            })
            ->values()
            ->all();
    }
}
