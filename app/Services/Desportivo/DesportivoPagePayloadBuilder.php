<?php

namespace App\Services\Desportivo;

use App\Models\AgeGroup;
use App\Models\AthleteStatusConfig;
use App\Models\Competition;
use App\Models\ConvocationAthlete;
use App\Models\ConvocationGroup;
use App\Models\CostCenter;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Macrocycle;
use App\Models\Mesocycle;
use App\Models\Microcycle;
use App\Models\ProvaTipo;
use App\Models\Result;
use App\Models\Season;
use App\Models\TeamResult;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingTypeConfig;
use App\Models\TrainingZoneConfig;
use App\Models\User;
use App\Services\Members\MemberDocumentDataResolver;
use App\Services\Members\MemberIdentityDisplayResolver;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DesportivoPagePayloadBuilder
{
    private const CACHE_TTL_SECONDS = 60;

    private Request $request;

    /**
     * @var array<string, mixed>
     */
    private array $runtime = [];

    public function __construct(
        private CacheRepository $cache,
        private MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
        private MemberDocumentDataResolver $memberDocumentDataResolver,
    )
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $tab, Request $request): array
    {
        $this->boot($request);

        return ['tab' => $tab] + match ($tab) {
            'dashboard' => $this->buildDashboardPayload(),
            'atletas' => $this->buildAthletesPayload(),
            'treinos' => $this->buildTrainingsPayload(),
            'planeamento' => $this->buildPlanningPayload(),
            'cais' => $this->buildPoolDeckPayload(),
            'competicoes' => $this->buildCompetitionsPayload(),
            'performance' => $this->buildPerformancePayload(),
            default => $this->buildDashboardPayload(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPlanningPartial(Request $request): array
    {
        $this->boot($request);

        return [
            'tab' => 'planeamento',
            'selectedSeason' => $this->selectedSeason(),
            'macrocycles' => $this->planningMacrocycles(),
            'mesocycles' => $this->planningMesocycles(),
        ];
    }

    private function boot(Request $request): void
    {
        $this->request = $request;
        $this->runtime = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardPayload(): array
    {
        $upcomingCompetitions = $this->upcomingCompetitions();
        $nextTrainings = $this->nextTrainings();

        return [
            'stats' => $this->dashboardStats(),
            'alerts' => $this->dashboardAlerts(),
            'upcomingCompetitions' => $upcomingCompetitions,
            'nextTrainings' => $nextTrainings,
            'trainings' => [
                'data' => $nextTrainings,
            ],
            'competitions' => collect($upcomingCompetitions)
                ->map(fn (array $item) => [
                    'id' => $item['id'],
                    'titulo' => $item['nome'],
                    'data_inicio' => $item['data_inicio'],
                    'local' => $item['local'] ?? null,
                    'tipo' => 'prova',
                    'evento_id' => $item['evento_id'] ?? null,
                ])
                ->values(),
            'eventos' => collect($upcomingCompetitions)
                ->map(fn (array $item) => [
                    'id' => $item['evento_id'] ?? $item['id'],
                    'titulo' => $item['nome'],
                    'data_inicio' => $item['data_inicio'],
                    'tipo' => 'prova',
                    'estado' => 'agendado',
                    'local' => $item['local'] ?? null,
                    'escaloes_elegiveis' => [],
                    'age_groups' => [],
                ])
                ->values(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildDashboardVolumePayload(Request $request): array
    {
        $this->boot($request);

        return $this->volumeByAthlete(limit: 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAthletesPayload(): array
    {
        return [
            'users' => $this->athleteUsers(includeMedical: true),
            'ageGroups' => $this->ageGroups(),
            'volumeByAthlete' => $this->volumeByAthlete(),
            'athleteOperationalRows' => $this->athleteOperationalRows(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTrainingsPayload(): array
    {
        return [
            'seasons' => $this->planningSeasons(),
            'selectedSeason' => $this->selectedSeason(),
            'macrocycles' => $this->planningMacrocycles(),
            'macrocycleOptions' => $this->macrocycleOptions(),
            'mesocycles' => $this->planningMesocycles(),
            'mesocycleOptions' => $this->mesocycleOptions(),
            'microcycles' => $this->planningMicrocycles(),
            'microcycleOptions' => $this->microcycleOptions(),
            'ageGroups' => $this->ageGroups(),
            'tiposEpoca' => ['Principal', 'Secundária', 'Época de Verão', 'Preparação', 'Pré-Época'],
            'estadosEpoca' => ['Planeada', 'Em curso', 'Concluída', 'Arquivada'],
            'tiposMacrociclo' => ['Preparação geral', 'Preparação específica', 'Competição', 'Taper', 'Transição'],
            'trainingTypeOptions' => $this->trainingTypeOptions(),
            'trainingZoneOptions' => $this->trainingZoneOptions(),
            'trainings' => $this->trainingsPage(),
            'calendarTrainings' => $this->calendarTrainings(),
            'trainingOptions' => $this->trainingOptions(daysBack: 90),
            'selectedTraining' => $this->selectedTraining(),
            'presences' => $this->selectedTrainingPresences(),
            'users' => $this->athleteUsers(includeMedical: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlanningPayload(): array
    {
        return [
            'seasons' => $this->planningSeasons(),
            'selectedSeason' => $this->selectedSeason(),
            'macrocycles' => $this->planningMacrocycles(),
            'macrocycleOptions' => $this->macrocycleOptions(),
            'mesocycles' => $this->planningMesocycles(),
            'mesocycleOptions' => $this->mesocycleOptions(),
            'microcycles' => $this->planningMicrocycles(),
            'microcycleOptions' => $this->microcycleOptions(),
            'ageGroups' => $this->ageGroups(),
            'tiposEpoca' => ['Principal', 'Secundária', 'Época de Verão', 'Preparação', 'Pré-Época'],
            'estadosEpoca' => ['Planeada', 'Em curso', 'Concluída', 'Arquivada'],
            'tiposMacrociclo' => ['Preparação geral', 'Preparação específica', 'Competição', 'Taper', 'Transição'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPoolDeckPayload(): array
    {
        return [
            'trainings' => [
                'data' => $this->poolDeckTrainings(),
            ],
            'selectedTraining' => $this->selectedTrainingFromPoolDeck(),
            'presences' => $this->selectedTrainingPresences(),
            'trainingOptions' => $this->trainingOptions(daysBack: 90),
            'users' => $this->athleteUsers(includeMedical: false),
            'ageGroups' => $this->ageGroups(),
            'statusOptions' => $this->statusOptions(),
            'classificacaoOptions' => ['Excelente', 'Bom', 'Satisfatório', 'Fraco'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCompetitionsPayload(): array
    {
        return [
            'competitions' => $this->competitionList(),
            'results' => $this->competitionResults(),
            'teamResults' => $this->teamResults(),
            'provaTipos' => $this->provaTipos(),
            'eventos' => $this->competitionEvents(),
            'users' => $this->athleteUsers(includeMedical: false),
            'ageGroups' => $this->ageGroups(),
            'costCenters' => $this->costCenters(),
            'eventTypes' => $this->eventTypes(),
            'convocations' => $this->convocations(),
            'convocationGroups' => $this->convocationGroups(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPerformancePayload(): array
    {
        return [
            'users' => $this->athleteUsers(includeMedical: false),
            'volumeByAthlete' => $this->volumeByAthlete(),
            'athleteOperationalRows' => $this->athleteOperationalRows(),
            'reportAttendanceByGroup' => $this->attendanceByGroup(last30Days: false),
            'competitionStats' => $this->competitionStats(),
            'financeVsSport' => $this->financeVsSport(),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function dashboardStats(): array
    {
        return $this->cacheSection('desportivo:dashboard:stats', function (): array {
            $now = Carbon::now();
            $sevenDaysAgo = $now->copy()->subDays(7)->toDateString();
            $thirtyDaysAgo = $now->copy()->subDays(30)->toDateString();

            $trainingSummary = Training::query()
                ->selectRaw('SUM(CASE WHEN data BETWEEN ? AND ? THEN 1 ELSE 0 END) as trainings_7_days', [$sevenDaysAgo, $now->toDateString()])
                ->selectRaw('SUM(CASE WHEN data BETWEEN ? AND ? THEN 1 ELSE 0 END) as trainings_30_days', [$thirtyDaysAgo, $now->toDateString()])
                ->selectRaw('COALESCE(SUM(CASE WHEN data BETWEEN ? AND ? THEN volume_planeado_m ELSE 0 END), 0) as km_7_days', [$sevenDaysAgo, $now->toDateString()])
                ->selectRaw('COALESCE(SUM(CASE WHEN data BETWEEN ? AND ? THEN volume_planeado_m ELSE 0 END), 0) as km_30_days', [$thirtyDaysAgo, $now->toDateString()])
                ->first();

            $athletesCount = User::query()
                ->whereJsonContains('tipo_membro', 'atleta')
                ->where('estado', 'ativo')
                ->count();

            $attendanceRate = DB::table('training_athletes')
                ->join('trainings', 'training_athletes.treino_id', '=', 'trainings.id')
                ->where('trainings.data', '>=', $thirtyDaysAgo)
                ->selectRaw("ROUND(COUNT(CASE WHEN training_athletes.estado = 'presente' THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0), 2) as rate")
                ->value('rate');

            return [
                'athletesCount' => $athletesCount,
                'trainings7Days' => (int) ($trainingSummary?->trainings_7_days ?? 0),
                'trainings30Days' => (int) ($trainingSummary?->trainings_30_days ?? 0),
                'km7Days' => round(((float) ($trainingSummary?->km_7_days ?? 0)) / 1000, 2),
                'km30Days' => round(((float) ($trainingSummary?->km_30_days ?? 0)) / 1000, 2),
                'attendanceRate' => $attendanceRate !== null ? (float) $attendanceRate : null,
            ];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dashboardAlerts(): array
    {
        return $this->cacheSection('desportivo:dashboard:alerts', function (): array {
            $alerts = [];
            $thirtyDaysAgo = Carbon::now()->subDays(30)->toDateString();
            $userAlertSummary = User::query()
                ->whereJsonContains('tipo_membro', 'atleta')
                ->selectRaw("SUM(CASE WHEN estado = 'ativo' AND data_atestado_medico IS NOT NULL AND data_atestado_medico <= ? THEN 1 ELSE 0 END) as medical_certs_count", [Carbon::now()->subDays(335)->toDateString()])
                ->selectRaw("SUM(CASE WHEN estado = 'inativo' THEN 1 ELSE 0 END) as inactive_athletes")
                ->first();

            $medicalCertsCount = (int) ($userAlertSummary?->medical_certs_count ?? 0);

            if ($medicalCertsCount > 0) {
                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'Atestados Médicos a Caducar',
                    'message' => $medicalCertsCount . ' atletas com atestado a caducar em 30 dias',
                    'count' => $medicalCertsCount,
                ];
            }

            $trainingsLast30Days = Training::query()
                ->where('data', '>=', $thirtyDaysAgo)
                ->count();

            if ($trainingsLast30Days > 0) {
                $presenceByAthlete = DB::table('training_athletes')
                    ->join('trainings', 'training_athletes.treino_id', '=', 'trainings.id')
                    ->where('trainings.data', '>=', $thirtyDaysAgo)
                    ->where('training_athletes.estado', 'presente')
                    ->select('training_athletes.user_id', DB::raw('COUNT(*) as presentes'))
                    ->groupBy('training_athletes.user_id');

                $lowAttendanceCount = User::query()
                    ->whereJsonContains('tipo_membro', 'atleta')
                    ->where('estado', 'ativo')
                    ->leftJoinSub($presenceByAthlete, 'presence_by_athlete', 'presence_by_athlete.user_id', '=', 'users.id')
                    ->whereRaw('COALESCE(presence_by_athlete.presentes, 0) < ?', [$trainingsLast30Days * 0.5])
                    ->count();

                if ($lowAttendanceCount > 0) {
                    $alerts[] = [
                        'type' => 'warning',
                        'title' => 'Atletas com Baixa Presença',
                        'message' => $lowAttendanceCount . ' atletas com presença inferior a 50%',
                        'count' => $lowAttendanceCount,
                    ];
                }
            }

            $inactiveAthletes = (int) ($userAlertSummary?->inactive_athletes ?? 0);

            if ($inactiveAthletes > 0) {
                $alerts[] = [
                    'type' => 'info',
                    'title' => 'Atletas Inativos',
                    'message' => $inactiveAthletes . ' atletas marcados como inativos',
                    'count' => $inactiveAthletes,
                ];
            }

            return $alerts;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function upcomingCompetitions(): array
    {
        return $this->cacheSection('desportivo:dashboard:upcoming-competitions', function (): array {
            $now = Carbon::now();
            $thirtyDaysAhead = $now->copy()->addDays(30)->toDateString();

            return Competition::query()
                ->whereBetween('data_inicio', [$now->toDateString(), $thirtyDaysAhead])
                ->orderBy('data_inicio')
                ->limit(8)
                ->get(['id', 'nome', 'data_inicio', 'local', 'tipo', 'evento_id'])
                ->map(fn (Competition $competition) => [
                    'id' => $competition->id,
                    'nome' => $competition->nome,
                    'data_inicio' => $this->formatDate($competition->data_inicio),
                    'num_atletas_inscritos' => 0,
                    'local' => $competition->local,
                    'evento_id' => $competition->evento_id,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nextTrainings(): array
    {
        return $this->cacheSection('desportivo:dashboard:next-trainings', function (): array {
            $now = Carbon::now();
            $thirtyDaysAhead = $now->copy()->addDays(30)->toDateString();

            return Training::query()
                ->whereBetween('data', [$now->toDateString(), $thirtyDaysAhead])
                ->orderBy('data')
                ->orderBy('hora_inicio')
                ->limit(10)
                ->get([
                    'id',
                    'numero_treino',
                    'data',
                    'hora_inicio',
                    'hora_fim',
                    'local',
                    'tipo_treino',
                    'volume_planeado_m',
                ])
                ->map(fn (Training $training) => $this->mapTraining($training))
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ageGroups(): array
    {
        return $this->cacheSection('desportivo:shared:age-groups', function (): array {
            return AgeGroup::query()
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(fn (AgeGroup $group) => [
                    'id' => $group->id,
                    'nome' => $group->nome,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeSeason(): ?array
    {
        return $this->once('active-season', fn (): ?array => $this->cacheSection('desportivo:shared:active-season', function (): ?array {
            $season = Season::query()
                ->where('estado', 'Em curso')
                ->first(['id', 'nome', 'ano_temporada', 'estado', 'tipo', 'data_inicio', 'data_fim']);

            return $season ? $this->mapSeason($season) : null;
        }));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectedSeason(): ?array
    {
        return $this->once('selected-season:' . ($this->request->input('season_id') ?? 'active'), function (): ?array {
            $season = $this->request->filled('season_id')
                ? Season::query()->find($this->request->string('season_id')->toString(), ['id', 'nome', 'ano_temporada', 'estado', 'tipo', 'data_inicio', 'data_fim'])
                : null;

            if (!$season) {
                return $this->activeSeason() ?: $this->planningSeasons()[0] ?? null;
            }

            return $this->mapSeason($season);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function planningSeasons(): array
    {
        return $this->cacheSection('desportivo:planeamento:seasons', function (): array {
            return Season::query()
                ->orderByDesc('data_inicio')
                ->get(['id', 'nome', 'ano_temporada', 'estado', 'tipo', 'data_inicio', 'data_fim'])
                ->map(fn (Season $season) => $this->mapSeason($season))
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function planningMacrocycles(): array
    {
        $seasonId = $this->selectedSeason()['id'] ?? 'none';

        return $this->cacheSection('desportivo:planeamento:macrocycles:season:' . $seasonId, function (): array {
            $seasonId = $this->selectedSeason()['id'] ?? null;
            if (!$seasonId) {
                return [];
            }

            return Macrocycle::query()
                ->where('epoca_id', $seasonId)
                ->orderBy('data_inicio')
                ->get(['id', 'epoca_id', 'nome', 'tipo', 'data_inicio', 'data_fim', 'objetivo_principal', 'objetivo_secundario', 'escalao'])
                ->map(fn (Macrocycle $macrocycle) => $this->mapMacrocycle($macrocycle))
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function macrocycleOptions(): array
    {
        return $this->cacheSection('desportivo:planeamento:macrocycle-options', function (): array {
            return Macrocycle::query()
                ->orderBy('data_inicio')
                ->get(['id', 'epoca_id', 'nome', 'data_inicio', 'data_fim'])
                ->map(fn (Macrocycle $macrocycle) => [
                    'id' => $macrocycle->id,
                    'epoca_id' => $macrocycle->epoca_id,
                    'nome' => $macrocycle->nome,
                    'data_inicio' => $this->formatDate($macrocycle->data_inicio),
                    'data_fim' => $this->formatDate($macrocycle->data_fim),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function planningMesocycles(): array
    {
        $macrocycleIds = collect($this->planningMacrocycles())->pluck('id')->filter()->values();
        if ($macrocycleIds->isEmpty()) {
            return [];
        }

        return $this->cacheSection('desportivo:planeamento:mesocycles:season:' . ($this->selectedSeason()['id'] ?? 'none'), function () use ($macrocycleIds): array {
            $hasObjectiveColumns = $this->hasMesocycleObjectiveColumns();
            $columns = ['id', 'macrociclo_id', 'nome', 'foco', 'data_inicio', 'data_fim'];
            if ($hasObjectiveColumns) {
                $columns[] = 'objetivo_principal';
                $columns[] = 'objetivo_secundario';
            }

            return Mesocycle::query()
                ->whereIn('macrociclo_id', $macrocycleIds)
                ->orderBy('data_inicio')
                ->get($columns)
                ->map(function (Mesocycle $mesocycle) use ($hasObjectiveColumns) {
                    return [
                        'id' => $mesocycle->id,
                        'macrociclo_id' => $mesocycle->macrociclo_id,
                        'nome' => $mesocycle->nome,
                        'data_inicio' => $this->formatDate($mesocycle->data_inicio),
                        'data_fim' => $this->formatDate($mesocycle->data_fim),
                        'objetivo_principal' => ($hasObjectiveColumns ? $mesocycle->objetivo_principal : null) ?: $mesocycle->foco,
                        'objetivo_secundario' => $hasObjectiveColumns ? $mesocycle->objetivo_secundario : null,
                    ];
                })
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mesocycleOptions(): array
    {
        return $this->cacheSection('desportivo:planeamento:mesocycle-options', function (): array {
            return Mesocycle::query()
                ->select('mesocycles.id', 'mesocycles.macrociclo_id', 'mesocycles.nome', 'mesocycles.data_inicio', 'mesocycles.data_fim', 'macrocycles.epoca_id')
                ->join('macrocycles', 'macrocycles.id', '=', 'mesocycles.macrociclo_id')
                ->orderBy('mesocycles.data_inicio')
                ->get()
                ->map(fn (Mesocycle $mesocycle) => [
                    'id' => $mesocycle->id,
                    'macrociclo_id' => $mesocycle->macrociclo_id,
                    'nome' => $mesocycle->nome,
                    'epoca_id' => $mesocycle->epoca_id,
                    'data_inicio' => $this->formatDate($mesocycle->data_inicio),
                    'data_fim' => $this->formatDate($mesocycle->data_fim),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function planningMicrocycles(): array
    {
        $macrocycleIds = collect($this->planningMacrocycles())->pluck('id')->filter()->values();
        if ($macrocycleIds->isEmpty()) {
            return [];
        }

        return $this->cacheSection('desportivo:planeamento:microcycles:season:' . ($this->selectedSeason()['id'] ?? 'none'), function () use ($macrocycleIds): array {
            return Microcycle::query()
                ->select('microcycles.id', 'microcycles.semana', 'microcycles.mesociclo_id', 'mesocycles.macrociclo_id')
                ->join('mesocycles', 'mesocycles.id', '=', 'microcycles.mesociclo_id')
                ->whereIn('mesocycles.macrociclo_id', $macrocycleIds)
                ->orderBy('microcycles.semana')
                ->get()
                ->map(fn (Microcycle $microcycle) => [
                    'id' => $microcycle->id,
                    'nome' => $microcycle->semana,
                    'mesociclo_id' => $microcycle->mesociclo_id,
                    'macrocycle_id' => $microcycle->macrociclo_id,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function microcycleOptions(): array
    {
        return $this->cacheSection('desportivo:planeamento:microcycle-options', function (): array {
            return Microcycle::query()
                ->select('microcycles.id', 'microcycles.semana', 'microcycles.mesociclo_id', 'mesocycles.macrociclo_id', 'macrocycles.epoca_id')
                ->join('mesocycles', 'mesocycles.id', '=', 'microcycles.mesociclo_id')
                ->join('macrocycles', 'macrocycles.id', '=', 'mesocycles.macrociclo_id')
                ->orderBy('microcycles.semana')
                ->get()
                ->map(fn (Microcycle $microcycle) => [
                    'id' => $microcycle->id,
                    'nome' => $microcycle->semana,
                    'mesociclo_id' => $microcycle->mesociclo_id,
                    'macrocycle_id' => $microcycle->macrociclo_id,
                    'epoca_id' => $microcycle->epoca_id,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function trainingsPage(): array
    {
        $page = max((int) $this->request->integer('page', 1), 1);

        return $this->cacheSection('desportivo:treinos:list:page:' . $page, function () use ($page): array {
            $query = Training::query()->with([
                'series',
                'athleteRecords.atleta:id,nome_completo',
            ]);

            if ($this->hasTrainingAgeGroupPivot()) {
                $query->with('ageGroups:id');
            }

            /** @var LengthAwarePaginator $paginator */
            $paginator = $query
                ->orderByDesc('data')
                ->orderByDesc('created_at')
                ->paginate(25, ['*'], 'page', $page);

            return [
                'data' => collect($paginator->items())
                    ->map(fn (Training $training) => $this->mapTraining($training, includePresencesGroup: true, includeSeries: true))
                    ->values()
                    ->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function calendarTrainings(): array
    {
        [$startDate, $endDate, $rangeKey] = $this->calendarRange();

        return $this->cacheSection('desportivo:treinos:calendar:' . $rangeKey, function () use ($startDate, $endDate): array {
            return Training::query()
                ->whereBetween('data', [$startDate, $endDate])
                ->orderBy('data')
                ->orderBy('hora_inicio')
                ->get(['id', 'numero_treino', 'data', 'hora_inicio', 'hora_fim', 'macrocycle_id', 'mesociclo_id', 'microciclo_id'])
                ->map(fn (Training $training) => [
                    'id' => $training->id,
                    'numero_treino' => $training->numero_treino,
                    'data' => $this->formatDate($training->data),
                    'hora_inicio' => $training->hora_inicio,
                    'hora_fim' => $training->hora_fim,
                    'macrocycle_id' => $training->macrocycle_id,
                    'mesociclo_id' => $training->mesociclo_id,
                    'microciclo_id' => $training->microciclo_id,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function trainingOptions(int $daysBack): array
    {
        $from = Carbon::now()->subDays($daysBack)->toDateString();

        return $this->cacheSection('desportivo:treinos:options:from:' . $from, function () use ($from): array {
            return Training::query()
                ->where('data', '>=', $from)
                ->orderByDesc('data')
                ->orderByDesc('hora_inicio')
                ->get(['id', 'numero_treino', 'data'])
                ->map(fn (Training $training) => [
                    'id' => $training->id,
                    'numero_treino' => $training->numero_treino,
                    'data' => $this->formatDate($training->data),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectedTraining(): ?array
    {
        $trainingId = $this->request->input('training_id');
        if (!$trainingId) {
            return null;
        }

        return $this->cacheSection('desportivo:treinos:selected-training:' . $trainingId, function () use ($trainingId): ?array {
            $query = Training::query()->with('series');
            if ($this->hasTrainingAgeGroupPivot()) {
                $query->with('ageGroups:id');
            }

            $training = $query->find($trainingId);

            return $training ? $this->mapTraining($training, includePresencesGroup: false, includeSeries: true) : null;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectedTrainingPresences(): array
    {
        $trainingId = $this->request->input('training_id');
        if (!$trainingId) {
            return [];
        }

        return $this->cacheSection('desportivo:treinos:presences:' . $trainingId, function () use ($trainingId): array {
            $training = Training::query()->find($trainingId, ['id', 'data']);
            if (!$training) {
                return [];
            }

            return $training->athleteRecords()
                ->with('atleta:id,nome_completo')
                ->get()
                ->map(function (TrainingAthlete $presence) use ($training) {
                    return [
                        'id' => $presence->id,
                        'legacy_presence_id' => null,
                        'user_id' => $presence->user_id,
                        'nome_atleta' => $presence->atleta?->nome_completo,
                        'data' => $this->formatDate($training->data),
                        'status' => $presence->estado,
                        'distancia_realizada_m' => $presence->volume_real_m,
                        'classificacao' => null,
                        'notas' => $presence->observacoes_tecnicas,
                    ];
                })
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function poolDeckTrainings(): array
    {
        [$startDate, $endDate, $rangeKey] = $this->calendarRange();

        return $this->cacheSection('desportivo:cais:trainings:' . $rangeKey, function () use ($startDate, $endDate): array {
            $query = Training::query()
                ->with([
                    'series',
                    'athleteRecords.atleta:id,nome_completo',
                ])
                ->whereBetween('data', [$startDate, $endDate])
                ->orderBy('data')
                ->orderBy('hora_inicio');

            if ($this->hasTrainingAgeGroupPivot()) {
                $query->with('ageGroups:id');
            }

            return $query->get()
                ->map(fn (Training $training) => $this->mapTraining($training, includePresencesGroup: true, includeSeries: true))
                ->values()
                ->all();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectedTrainingFromPoolDeck(): ?array
    {
        $trainingId = $this->request->input('training_id');
        if (!$trainingId) {
            return null;
        }

        return collect($this->poolDeckTrainings())
            ->firstWhere('id', $trainingId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function trainingTypeOptions(): array
    {
        return $this->cacheSection('desportivo:treinos:type-options', function (): array {
            return TrainingTypeConfig::query()
                ->where('ativo', true)
                ->orderBy('ordem')
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'nome' => $item->nome,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function trainingZoneOptions(): array
    {
        return $this->cacheSection('desportivo:treinos:zone-options', function (): array {
            return TrainingZoneConfig::query()
                ->where('ativo', true)
                ->orderBy('ordem')
                ->orderBy('nome')
                ->get(['id', 'codigo', 'nome'])
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'codigo' => $item->codigo,
                    'nome' => $item->nome,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function athleteUsers(bool $includeMedical): array
    {
        $suffix = $includeMedical ? 'medical' : 'basic';

        return $this->cacheSection('desportivo:athletes:users:' . $suffix, function () use ($includeMedical): array {
            return User::query()
                ->with([
                    'athleteSportsData:id,user_id,escalao_id,num_federacao,data_atestado_medico,informacoes_medicas',
                    'dadosPessoais:id,user_id,nome_completo',
                    'dadosConfiguracao:id,user_id,afiliacao_numero,afiliacao_data,configuracao_extra,certificado_medico_ficheiro,afiliacao_ficheiro',
                ])
                ->where('estado', 'ativo')
                ->whereJsonContains('tipo_membro', 'atleta')
                ->orderBy('nome_completo')
                ->get()
                ->map(function (User $user) use ($includeMedical) {
                    $sportsPayload = $this->memberDocumentDataResolver->sportsPayload($user);

                    $payload = [
                        'id' => $user->id,
                        'nome_completo' => $this->memberIdentityDisplayResolver->displayName($user),
                        'email' => $user->email,
                        'estado' => $user->estado,
                        'tipo_membro' => is_array($user->tipo_membro) ? $user->tipo_membro : (array) $user->tipo_membro,
                        'escalao' => $user->athleteSportsData?->escalao_id ? [(string) $user->athleteSportsData->escalao_id] : [],
                        'num_federacao' => $sportsPayload['num_federacao'],
                    ];

                    if ($includeMedical) {
                        $payload['data_atestado_medico'] = $sportsPayload['data_atestado_medico'];
                        $payload['informacoes_medicas'] = $sportsPayload['informacoes_medicas'];
                    }

                    return $payload;
                })
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function volumeByAthlete(?int $limit = null): array
    {
        $seasonStart = $this->seasonStartDate();
        $cacheKey = 'desportivo:performance:volume-by-athlete:season:' . $seasonStart . ($limit ? ':limit:' . $limit : ':all');

        return $this->cacheSection($cacheKey, function () use ($seasonStart, $limit): array {
            $query = DB::table('training_athletes')
                ->join('trainings', 'training_athletes.treino_id', '=', 'trainings.id')
                ->join('users', 'training_athletes.user_id', '=', 'users.id')
                ->where('trainings.data', '>=', $seasonStart)
                ->where(function ($q) {
                    $q->where('training_athletes.estado', 'presente')
                        ->orWhere('training_athletes.presente', true);
                })
                ->select('users.nome_completo', DB::raw('SUM(COALESCE(trainings.volume_planeado_m, 0)) as total_m'))
                ->groupBy('users.id', 'users.nome_completo')
                ->orderByDesc('total_m');

            if ($limit !== null) {
                $query->limit($limit);
            }

            return $query->get()
                ->map(fn ($row) => [
                    'nome_completo' => $row->nome_completo,
                    'total_m' => (float) $row->total_m,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function athleteOperationalRows(): array
    {
        $seasonStart = $this->seasonStartDate();

        return $this->cacheSection('desportivo:performance:athlete-operational:season:' . $seasonStart, function () use ($seasonStart): array {
            $attendanceSummaryByUser = DB::table('training_athletes')
                ->join('trainings', 'training_athletes.treino_id', '=', 'trainings.id')
                ->where('trainings.data', '>=', $seasonStart)
                ->select(
                    'training_athletes.user_id',
                    DB::raw('COUNT(*) as total_registos'),
                    DB::raw("COUNT(CASE WHEN training_athletes.estado = 'presente' THEN 1 END) as total_presentes"),
                    DB::raw("COUNT(CASE WHEN training_athletes.estado = 'ausente' THEN 1 END) as total_ausentes")
                )
                ->groupBy('training_athletes.user_id')
                ->get()
                ->keyBy('user_id');

            $bestResultsByUser = DB::table('results')
                ->whereNotNull('user_id')
                ->select(
                    'user_id',
                    DB::raw('MIN(posicao) as melhor_classificacao'),
                    DB::raw('COUNT(*) as total_resultados')
                )
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            return collect($this->athleteUsers(includeMedical: false))
                ->map(function (array $user) use ($attendanceSummaryByUser, $bestResultsByUser) {
                    $attendance = $attendanceSummaryByUser->get($user['id']);
                    $results = $bestResultsByUser->get($user['id']);

                    $totalRegistos = (int) ($attendance->total_registos ?? 0);
                    $presentes = (int) ($attendance->total_presentes ?? 0);
                    $ausentes = (int) ($attendance->total_ausentes ?? 0);

                    $assiduidadePercent = $totalRegistos > 0
                        ? (int) round(($presentes / $totalRegistos) * 100)
                        : null;

                    $disciplinaStatus = 'ok';
                    if ($totalRegistos > 0) {
                        $ausenciaPercent = ($ausentes / $totalRegistos) * 100;
                        if ($ausenciaPercent >= 45) {
                            $disciplinaStatus = 'critico';
                        } elseif ($ausenciaPercent >= 30) {
                            $disciplinaStatus = 'atencao';
                        }
                    }

                    return [
                        'user_id' => $user['id'],
                        'assiduidade_percent' => $assiduidadePercent,
                        'disciplina_status' => $disciplinaStatus,
                        'pb_label' => ($results && $results->melhor_classificacao)
                            ? '#' . (int) $results->melhor_classificacao
                            : null,
                        'total_resultados' => (int) ($results->total_resultados ?? 0),
                    ];
                })
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attendanceByGroup(bool $last30Days): array
    {
        $key = $last30Days
            ? 'desportivo:dashboard:attendance-by-group:last30'
            : 'desportivo:performance:attendance-by-group:season:' . $this->seasonStartDate();

        return $this->cacheSection($key, function () use ($last30Days): array {
            $query = DB::table('training_athletes')
                ->join('trainings', 'training_athletes.treino_id', '=', 'trainings.id')
                ->join('athlete_sports_data', 'training_athletes.user_id', '=', 'athlete_sports_data.user_id')
                ->leftJoin('age_groups', 'athlete_sports_data.escalao_id', '=', 'age_groups.id');

            if ($last30Days) {
                $query->where('trainings.data', '>=', Carbon::now()->subDays(30)->toDateString());
            } else {
                $query->where('trainings.data', '>=', $this->seasonStartDate());
            }

            return $query
                ->select(
                    'age_groups.nome',
                    DB::raw("COUNT(CASE WHEN training_athletes.estado = 'presente' THEN 1 END) as presentes"),
                    DB::raw("COUNT(CASE WHEN training_athletes.estado = 'ausente' THEN 1 END) as ausentes"),
                    DB::raw('COUNT(*) as total'),
                    DB::raw("ROUND(COUNT(CASE WHEN training_athletes.estado = 'presente' THEN 1 END) * 100.0 / NULLIF(COUNT(*),0), 2) as percentagem")
                )
                ->groupBy('age_groups.id', 'age_groups.nome')
                ->get()
                ->map(fn ($row) => [
                    'nome' => $row->nome,
                    'presentes' => (int) $row->presentes,
                    'ausentes' => (int) $row->ausentes,
                    'total' => (int) $row->total,
                    'percentagem' => $row->percentagem !== null ? (float) $row->percentagem : null,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function competitionList(): array
    {
        return $this->cacheSection('desportivo:competicoes:list', function (): array {
            $persistedCompetitions = Competition::query()
                ->orderByDesc('data_inicio')
                ->get(['id', 'nome', 'data_inicio', 'local', 'tipo', 'evento_id'])
                ->map(fn (Competition $row) => [
                    'id' => $row->id,
                    'titulo' => $row->nome,
                    'data_inicio' => $this->formatDate($row->data_inicio),
                    'local' => $row->local,
                    'tipo' => $row->tipo,
                    'evento_id' => $row->evento_id,
                ]);

            $linkedEventIds = $persistedCompetitions->pluck('evento_id')->filter()->values();

            $orphanEventCompetitions = Event::query()
                ->where('tipo', 'prova')
                ->when($linkedEventIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $linkedEventIds))
                ->get(['id', 'titulo', 'data_inicio', 'local', 'tipo'])
                ->map(fn (Event $event) => [
                    'id' => $event->id,
                    'titulo' => $event->titulo,
                    'data_inicio' => $this->formatDate($event->data_inicio),
                    'local' => $event->local,
                    'tipo' => $event->tipo,
                    'evento_id' => $event->id,
                ]);

            return $persistedCompetitions
                ->concat($orphanEventCompetitions)
                ->sortByDesc(fn (array $item) => (string) ($item['data_inicio'] ?? ''))
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function competitionEvents(): array
    {
        return $this->cacheSection('desportivo:competicoes:events', function (): array {
            $competitionEventIds = collect($this->competitionList())
                ->pluck('evento_id')
                ->filter()
                ->unique()
                ->values();

            if ($competitionEventIds->isEmpty()) {
                return [];
            }

            return Event::query()
                ->with('ageGroups:id,nome')
                ->whereIn('id', $competitionEventIds)
                ->orderByDesc('data_inicio')
                ->get([
                    'id',
                    'titulo',
                    'data_inicio',
                    'data_fim',
                    'hora_inicio',
                    'hora_fim',
                    'tipo',
                    'estado',
                    'local',
                    'descricao',
                    'centro_custo_id',
                    'local_detalhes',
                    'tipo_piscina',
                    'transporte_necessario',
                    'hora_partida',
                    'local_partida',
                    'transporte_detalhes',
                    'taxa_inscricao',
                    'custo_inscricao_por_prova',
                    'custo_inscricao_por_salto',
                    'custo_inscricao_estafeta',
                    'observacoes',
                    'visibilidade',
                    'recorrente',
                    'recorrencia_data_inicio',
                    'recorrencia_data_fim',
                    'recorrencia_dias_semana',
                ])
                ->map(function (Event $event) {
                    $ageGroupIds = $event->ageGroups
                        ->pluck('id')
                        ->map(fn ($id) => (string) $id)
                        ->values()
                        ->all();

                    return [
                        'id' => $event->id,
                        'titulo' => $event->titulo,
                        'data_inicio' => $this->formatDate($event->data_inicio),
                        'data_fim' => $this->formatDate($event->data_fim),
                        'hora_inicio' => $event->hora_inicio,
                        'hora_fim' => $event->hora_fim,
                        'tipo' => $event->tipo,
                        'estado' => $event->estado,
                        'local' => $event->local,
                        'descricao' => $event->descricao,
                        'centro_custo_id' => $event->centro_custo_id,
                        'local_detalhes' => $event->local_detalhes,
                        'tipo_piscina' => $event->tipo_piscina,
                        'escaloes_elegiveis' => $ageGroupIds,
                        'transporte_necessario' => $event->transporte_necessario,
                        'hora_partida' => $event->hora_partida,
                        'local_partida' => $event->local_partida,
                        'transporte_detalhes' => $event->transporte_detalhes,
                        'taxa_inscricao' => $event->taxa_inscricao,
                        'custo_inscricao_por_prova' => $event->custo_inscricao_por_prova,
                        'custo_inscricao_por_salto' => $event->custo_inscricao_por_salto,
                        'custo_inscricao_estafeta' => $event->custo_inscricao_estafeta,
                        'observacoes' => $event->observacoes,
                        'visibilidade' => $event->visibilidade,
                        'recorrente' => (bool) $event->recorrente,
                        'recorrencia_data_inicio' => $this->formatDate($event->recorrencia_data_inicio),
                        'recorrencia_data_fim' => $this->formatDate($event->recorrencia_data_fim),
                        'recorrencia_dias_semana' => $event->recorrencia_dias_semana ?? [],
                        'age_groups' => $event->ageGroups
                            ->map(fn ($group) => [
                                'id' => $group->id,
                                'nome' => $group->nome,
                            ])
                            ->values(),
                    ];
                })
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function competitionResults(): array
    {
        return $this->cacheSection('desportivo:competicoes:results', function (): array {
            return Result::query()
                ->with(['prova.competition', 'athlete'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn ($result) => [
                    'id' => $result->id,
                    'prova' => trim(($result->prova?->distancia_m ?? 0) . 'm ' . ($result->prova?->estilo ?? '')),
                    'tempo' => $result->tempo_oficial,
                    'classificacao' => $result->posicao,
                    'event' => $result->prova?->competition
                        ? [
                            'id' => $result->prova->competition->id,
                            'titulo' => $result->prova->competition->nome,
                        ]
                        : null,
                    'athlete' => $result->athlete
                        ? ['nome_completo' => $result->athlete->nome_completo]
                        : null,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamResults(): array
    {
        return $this->cacheSection('desportivo:competicoes:team-results', function (): array {
            return TeamResult::query()
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(['id', 'competicao_id', 'equipa', 'classificacao', 'pontos', 'observacoes'])
                ->map(fn (TeamResult $row) => [
                    'id' => $row->id,
                    'competicao_id' => $row->competicao_id,
                    'equipa' => $row->equipa,
                    'classificacao' => $row->classificacao,
                    'pontos' => $row->pontos,
                    'observacoes' => $row->observacoes,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function provaTipos(): array
    {
        return $this->cacheSection('desportivo:competicoes:prova-tipos', function (): array {
            return ProvaTipo::query()
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(fn (ProvaTipo $provaTipo) => [
                    'id' => $provaTipo->id,
                    'nome' => $provaTipo->nome,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function costCenters(): array
    {
        return $this->cacheSection('desportivo:competicoes:cost-centers', function (): array {
            return CostCenter::query()
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(['id', 'codigo', 'nome', 'ativo'])
                ->map(fn (CostCenter $center) => [
                    'id' => $center->id,
                    'codigo' => $center->codigo,
                    'nome' => $center->nome,
                    'ativo' => (bool) $center->ativo,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eventTypes(): array
    {
        return $this->cacheSection('desportivo:competicoes:event-types', function (): array {
            return EventType::query()
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(['id', 'nome', 'visibilidade_default', 'ativo'])
                ->map(fn (EventType $type) => [
                    'id' => $type->id,
                    'nome' => $type->nome,
                    'visibilidade_default' => $type->visibilidade_default,
                    'ativo' => (bool) $type->ativo,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function convocations(): array
    {
        return $this->cacheSection('desportivo:competicoes:convocations', function (): array {
            return ConvocationGroup::query()
                ->get(['id', 'evento_id'])
                ->map(fn (ConvocationGroup $group) => [
                    'id' => $group->id,
                    'evento_id' => $group->evento_id,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function convocationGroups(): array
    {
        return $this->cacheSection('desportivo:competicoes:convocation-groups', function (): array {
            $competitionEventIds = collect($this->competitionList())
                ->pluck('evento_id')
                ->filter()
                ->unique()
                ->values();

            if ($competitionEventIds->isEmpty()) {
                return [];
            }

            return ConvocationGroup::query()
                ->with([
                    'evento:id,titulo,data_inicio,tipo',
                    'convocationAthletes.atleta:id,nome_completo',
                ])
                ->whereIn('evento_id', $competitionEventIds)
                ->orderByDesc('data_criacao')
                ->get()
                ->map(function (ConvocationGroup $group) {
                    return [
                        'id' => $group->id,
                        'evento_id' => $group->evento_id,
                        'evento_titulo' => $group->evento?->titulo,
                        'evento_data' => $this->formatDate($group->evento?->data_inicio),
                        'atletas_ids' => $group->atletas_ids ?? [],
                        'hora_encontro' => $group->hora_encontro,
                        'local_encontro' => $group->local_encontro,
                        'observacoes' => $group->observacoes,
                        'athletes' => $group->convocationAthletes
                            ->map(function (ConvocationAthlete $athlete) {
                                return [
                                    'atleta_id' => $athlete->atleta_id,
                                    'atleta_nome' => $athlete->atleta?->nome_completo,
                                    'provas' => $athlete->provas ?? [],
                                    'estafetas' => $athlete->estafetas,
                                    'presente' => $athlete->presente,
                                    'confirmado' => $athlete->confirmado,
                                ];
                            })
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, string>
     */
    private function statusOptions(): array
    {
        return $this->cacheSection('desportivo:cais:status-options', function (): array {
            return AthleteStatusConfig::query()
                ->ativo()
                ->ordenado()
                ->pluck('codigo')
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function competitionStats(): array
    {
        $seasonStart = $this->seasonStartDate();

        return $this->cacheSection('desportivo:performance:competition-stats:season:' . $seasonStart, function () use ($seasonStart): array {
            return Competition::query()
                ->where('data_inicio', '>=', $seasonStart)
                ->withCount('results')
                ->orderByDesc('data_inicio')
                ->get(['id', 'nome', 'data_inicio', 'local', 'tipo'])
                ->map(fn (Competition $competition) => [
                    'id' => $competition->id,
                    'nome' => $competition->nome,
                    'data_inicio' => $this->formatDate($competition->data_inicio),
                    'local' => $competition->local,
                    'tipo' => $competition->tipo,
                    'results_count' => (int) $competition->results_count,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<string, float|null>
     */
    private function financeVsSport(): array
    {
        $seasonStart = $this->seasonStartDate();

        return $this->cacheSection('desportivo:performance:finance-vs-sport:season:' . $seasonStart, function () use ($seasonStart): array {
            $sportsFinancialTotal = (float) DB::table('movements')
                ->where('data_emissao', '>=', $seasonStart)
                ->where(function ($query) {
                    $query->where('origem_tipo', 'evento')
                        ->orWhere('tipo', 'inscricao');
                })
                ->sum(DB::raw('ABS(valor_total)'));

            $totalDistanceMeters = (float) DB::table('training_athletes')
                ->join('trainings', 'training_athletes.treino_id', '=', 'trainings.id')
                ->where('trainings.data', '>=', $seasonStart)
                ->sum(DB::raw('COALESCE(training_athletes.volume_real_m, 0)'));

            $totalDistanceKm = $totalDistanceMeters / 1000;
            $costPerKm = $totalDistanceKm > 0 ? $sportsFinancialTotal / $totalDistanceKm : null;

            return [
                'totalFinancialWeight' => round($sportsFinancialTotal, 2),
                'totalSportDistanceKm' => round($totalDistanceKm, 2),
                'costPerKm' => $costPerKm !== null ? round($costPerKm, 2) : null,
            ];
        });
    }

    private function seasonStartDate(): string
    {
        return $this->once('season-start-date', function (): string {
            return $this->selectedSeason()['data_inicio']
                ?? $this->activeSeason()['data_inicio']
                ?? Carbon::now()->startOfYear()->toDateString();
        });
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function calendarRange(): array
    {
        return $this->once('calendar-range', function (): array {
            $center = Carbon::now()->startOfMonth();
            $start = $center->copy()->subMonth()->startOfMonth()->toDateString();
            $end = $center->copy()->addMonth()->endOfMonth()->toDateString();

            return [$start, $end, $start . ':' . $end];
        });
    }

    private function hasTrainingAgeGroupPivot(): bool
    {
        return $this->once('schema:training-age-group', fn (): bool => Schema::hasTable('training_age_group'));
    }

    private function hasMesocycleObjectiveColumns(): bool
    {
        return $this->once(
            'schema:mesocycle-objectives',
            fn (): bool => Schema::hasColumn('mesocycles', 'objetivo_principal')
                && Schema::hasColumn('mesocycles', 'objetivo_secundario')
        );
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function once(string $key, callable $callback)
    {
        if (!array_key_exists($key, $this->runtime)) {
            $this->runtime[$key] = $callback();
        }

        return $this->runtime[$key];
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function cacheSection(string $key, callable $callback)
    {
        return $this->cache->remember($key, now()->addSeconds(self::CACHE_TTL_SECONDS), $callback);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSeason(Season $season): array
    {
        return [
            'id' => $season->id,
            'nome' => $season->nome,
            'ano_temporada' => $season->ano_temporada,
            'estado' => $season->estado,
            'tipo' => $season->tipo,
            'data_inicio' => $this->formatDate($season->data_inicio),
            'data_fim' => $this->formatDate($season->data_fim),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMacrocycle(Macrocycle $macrocycle): array
    {
        return [
            'id' => $macrocycle->id,
            'epoca_id' => $macrocycle->epoca_id,
            'nome' => $macrocycle->nome,
            'tipo' => $macrocycle->tipo,
            'data_inicio' => $this->formatDate($macrocycle->data_inicio),
            'data_fim' => $this->formatDate($macrocycle->data_fim),
            'objetivo_principal' => $macrocycle->objetivo_principal,
            'objetivo_secundario' => $macrocycle->objetivo_secundario,
            'escalao' => $macrocycle->escalao,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTraining(Training $training, bool $includePresencesGroup = false, bool $includeSeries = false): array
    {
        $escaloes = $this->resolveTrainingAgeGroups($training);

        $payload = [
            'id' => $training->id,
            'numero_treino' => $training->numero_treino,
            'data' => $this->formatDate($training->data),
            'hora_inicio' => $training->hora_inicio,
            'hora_fim' => $training->hora_fim,
            'local' => $training->local,
            'created_at' => $training->created_at?->toIso8601String(),
            'epoca_id' => $training->epoca_id,
            'macrocycle_id' => $training->macrocycle_id,
            'mesociclo_id' => $training->mesociclo_id,
            'microciclo_id' => $training->microciclo_id,
            'tipo_treino' => $training->tipo_treino,
            'volume_planeado_m' => $training->volume_planeado_m,
            'descricao_treino' => $training->descricao_treino,
            'notas_gerais' => $training->notas_gerais,
            'escaloes' => $escaloes,
        ];

        if ($includePresencesGroup) {
            $payload['presencas_grupo'] = $training->data !== null
                ? $training->athleteRecords
                    ->map(fn (TrainingAthlete $record) => [
                        'id' => $record->id,
                        'user_id' => $record->user_id,
                        'nome_atleta' => $record->atleta?->nome_completo ?? 'Desconhecido',
                        'estado' => $record->estado ?? 'ausente',
                    ])
                    ->sortBy('nome_atleta')
                    ->values()
                    ->all()
                : [];
        }

        if ($includeSeries) {
            $payload['series'] = $training->relationLoaded('series')
                ? $training->series
                    ->map(fn ($series) => [
                        'id' => $series->id,
                        'ordem' => $series->ordem,
                        'descricao_texto' => $series->descricao_texto,
                        'distancia_total_m' => $series->distancia_total_m,
                        'zona_intensidade' => $series->zona_intensidade,
                        'estilo' => $series->estilo,
                        'repeticoes' => $series->repeticoes,
                        'intervalo' => $series->intervalo,
                        'observacoes' => $series->observacoes,
                    ])
                    ->values()
                    ->all()
                : [];
        }

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    private function resolveTrainingAgeGroups(Training $training): array
    {
        if ($this->hasTrainingAgeGroupPivot() && $training->relationLoaded('ageGroups')) {
            return $training->ageGroups->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        }

        return collect($training->escaloes ?? [])->map(fn ($value) => (string) $value)->values()->all();
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        if (is_object($value) && method_exists($value, 'toDateString')) {
            return $value->toDateString();
        }

        return substr((string) $value, 0, 10) ?: null;
    }
}