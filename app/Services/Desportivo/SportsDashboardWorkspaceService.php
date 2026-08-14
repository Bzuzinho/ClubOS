<?php

namespace App\Services\Desportivo;

use App\Models\Competition;
use App\Models\CompetitionRegistration;
use App\Models\Training;
use App\Models\TrainingAthlete;
use Illuminate\Support\Carbon;

final class SportsDashboardWorkspaceService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly SportsAthletesWorkspaceService $athletesWorkspace,
    ) {
    }

    public function workspace(): array
    {
        $clubId = $this->clubContext->id();
        $today = Carbon::today();
        $from7 = $today->copy()->subDays(6);
        $from30 = $today->copy()->subDays(29);
        $to30 = $today->copy()->addDays(30);

        $athletes = $this->athletesWorkspace->workspace();
        $activeAthletes = collect($athletes['athletes'])->where('state', 'active')->values();

        $trainings30 = Training::query()
            ->where('club_id', $clubId)
            ->whereBetween('data', [$from30->toDateString(), $today->toDateString()])
            ->where('session_status', '!=', 'cancelled')
            ->get();

        $trainings7 = $trainings30->filter(fn (Training $training): bool =>
            $training->data && $training->data->between($from7, $today, true)
        );

        $todayTrainings = Training::query()
            ->where('club_id', $clubId)
            ->whereDate('data', $today->toDateString())
            ->where('session_status', '!=', 'cancelled')
            ->orderBy('hora_inicio')
            ->get();

        $upcomingTrainings = Training::query()
            ->where('club_id', $clubId)
            ->whereBetween('data', [$today->toDateString(), $to30->toDateString()])
            ->where('session_status', '!=', 'cancelled')
            ->orderBy('data')
            ->orderBy('hora_inicio')
            ->limit(8)
            ->get();

        $attendanceRows = TrainingAthlete::query()
            ->whereHas('training', fn ($query) => $query
                ->where('club_id', $clubId)
                ->whereDate('data', '>=', $from30->toDateString())
                ->where('session_status', '!=', 'cancelled'))
            ->get();
        $presentRows = $attendanceRows->filter(fn (TrainingAthlete $row): bool =>
            (bool) $row->presente || in_array($row->estado, ['presente', 'atrasado'], true)
        );
        $attendanceRate = $attendanceRows->isNotEmpty()
            ? round(($presentRows->count() / $attendanceRows->count()) * 100, 1)
            : null;
        $executedVolume = (int) $presentRows->sum(fn (TrainingAthlete $row): int => (int) ($row->volume_real_m ?? 0));

        $competitions = Competition::query()
            ->forClub($clubId)
            ->whereNull('archived_at')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('data_inicio', [$today->toDateString(), $to30->toDateString()])
            ->orderBy('data_inicio')
            ->limit(6)
            ->get();

        $registrationCounts = CompetitionRegistration::query()
            ->selectRaw('provas.competicao_id as competition_id, COUNT(DISTINCT competition_registrations.user_id) as athlete_count')
            ->join('provas', 'provas.id', '=', 'competition_registrations.prova_id')
            ->whereIn('provas.competicao_id', $competitions->pluck('id')->all())
            ->groupBy('provas.competicao_id')
            ->pluck('athlete_count', 'competition_id');

        $alerts = collect();
        $athleteStats = $athletes['stats'];
        if (($athleteStats['low_attendance'] ?? 0) > 0) {
            $alerts->push($this->alert('warning', 'Baixa assiduidade', (int) $athleteStats['low_attendance'], 'Atletas abaixo de 50% nos últimos 30 dias', 'desportivo.atletas.index'));
        }
        if (($athleteStats['without_group'] ?? 0) > 0) {
            $alerts->push($this->alert('warning', 'Atletas sem grupo', (int) $athleteStats['without_group'], 'Atletas ativos sem grupo atual', 'desportivo.atletas.index'));
        }
        if (($athleteStats['medical_pending'] ?? 0) > 0) {
            $alerts->push($this->alert('info', 'Documentação médica pendente', (int) $athleteStats['medical_pending'], 'Estado documental proveniente de Membros', 'desportivo.atletas.index'));
        }
        $reviewRequired = $upcomingTrainings->where('schedule_review_required', true)->count();
        if ($reviewRequired > 0) {
            $alerts->push($this->alert('warning', 'Sessões a rever', $reviewRequired, 'Conflitos ou decisões de planeamento pendentes', 'desportivo.treinos'));
        }

        $topAthletes = $activeAthletes
            ->sortByDesc('volume_30d_m')
            ->take(5)
            ->map(fn (array $row): array => [
                'id' => $row['id'],
                'name' => $row['name'],
                'volume_30d_m' => (int) $row['volume_30d_m'],
                'attendance_30d' => $row['attendance_30d'],
            ])->values();

        return [
            'stats' => [
                'active_athletes' => $activeAthletes->count(),
                'trainings_7d' => $trainings7->count(),
                'trainings_30d' => $trainings30->count(),
                'attendance_30d' => $attendanceRate,
                'executed_volume_30d_m' => $executedVolume,
                'today_trainings' => $todayTrainings->count(),
                'review_required' => $reviewRequired,
            ],
            'today' => $todayTrainings->map(fn (Training $training): array => $this->trainingRow($training))->values()->all(),
            'upcoming_trainings' => $upcomingTrainings->map(fn (Training $training): array => $this->trainingRow($training))->values()->all(),
            'upcoming_competitions' => $competitions->map(fn (Competition $competition): array => [
                'id' => (string) $competition->id,
                'name' => (string) $competition->nome,
                'date' => optional($competition->data_inicio)->format('Y-m-d'),
                'location' => $competition->local,
                'status' => (string) $competition->status,
                'athlete_count' => (int) ($registrationCounts[(string) $competition->id] ?? 0),
            ])->values()->all(),
            'alerts' => $alerts->values()->all(),
            'top_athletes' => $topAthletes->all(),
            'quick_links' => [
                ['label' => 'Atletas', 'route' => 'desportivo.atletas.index'],
                ['label' => 'Planeamento', 'route' => 'desportivo.planeamento'],
                ['label' => 'Treinos', 'route' => 'desportivo.treinos'],
                ['label' => 'Cais', 'route' => 'desportivo.cais'],
                ['label' => 'Competições', 'route' => 'desportivo.competicoes'],
                ['label' => 'Análise', 'route' => 'desportivo.relatorios'],
            ],
            'principles' => [
                'canonical_athletes' => true,
                'cancelled_trainings_excluded' => true,
                'attendance_from_assignments' => true,
                'competition_title_date_matching' => false,
                'legacy_medical_fields_active' => false,
            ],
        ];
    }

    private function trainingRow(Training $training): array
    {
        return [
            'id' => (string) $training->id,
            'number' => $training->numero_treino,
            'date' => optional($training->data)->format('Y-m-d'),
            'start' => $training->hora_inicio,
            'end' => $training->hora_fim,
            'location' => $training->local,
            'type' => $training->tipo_treino,
            'status' => $training->session_status,
            'review_required' => (bool) $training->schedule_review_required,
        ];
    }

    private function alert(string $type, string $title, int $count, string $message, string $route): array
    {
        return compact('type', 'title', 'count', 'message', 'route');
    }
}
