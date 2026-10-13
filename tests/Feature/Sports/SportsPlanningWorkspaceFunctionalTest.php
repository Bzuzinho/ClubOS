<?php

namespace Tests\Feature\Sports;

use App\Http\Controllers\Desportivo\SportsPlanningWorkspaceController;
use App\Http\Middleware\EnforceSportsLegacyCutover;
use App\Models\Season;
use App\Models\SportsModality;
use App\Models\SportsPool;
use App\Models\SportsPoolLane;
use App\Models\SportsVenue;
use App\Models\User;
use App\Services\Desportivo\SportsPlanningWorkspaceService;
use App\Services\Desportivo\TrainingGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SportsPlanningWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_canonical_planning_route_replaces_legacy_get_handler(): void
    {
        $route = Route::getRoutes()->getByName('desportivo.planeamento');
        $this->assertNotNull($route);
        $this->assertSame(SportsPlanningWorkspaceController::class . '@index', ltrim($route->getActionName(), '\\'));

        foreach ([
            'desportivo.planeamento.macros.store',
            'desportivo.planeamento.mesos.store',
            'desportivo.planeamento.micros.store',
            'desportivo.planeamento.sessions.store',
            'desportivo.planeamento.recurrences.store',
        ] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
    }

    public function test_legacy_planning_mutations_are_closed_by_cutover_middleware(): void
    {
        $request = Request::create('/desportivo/epocas', 'POST');

        try {
            app(EnforceSportsLegacyCutover::class)->handle($request, fn () => response('legacy write reached'));
            $this->fail('Legacy planning mutation should have been closed.');
        } catch (HttpException $exception) {
            $this->assertSame(410, $exception->getStatusCode());
        }
    }

    public function test_full_periodisation_hierarchy_enforces_parent_dates_and_archives_used_cycles(): void
    {
        $actor = User::factory()->create();
        $season = $this->season();
        $service = app(SportsPlanningWorkspaceService::class);

        $macro = $service->createMacrocycle([
            'epoca_id' => $season->id,
            'nome' => 'Preparação',
            'tipo' => 'Preparação geral',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-02-28',
            'objetivo_principal' => 'Base aeróbia',
        ], $actor);
        $meso = $service->createMesocycle([
            'macrociclo_id' => $macro->id,
            'nome' => 'Base 1',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-10-31',
            'objetivo_principal' => 'Volume',
        ], $actor);
        $micro = $service->createMicrocycle([
            'mesociclo_id' => $meso->id,
            'semana' => 'Semana 1',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-09-07',
            'volume_previsto' => 18000,
            'objetivo_principal' => 'Adaptação',
            'is_recovery_week' => false,
        ], $actor);

        $this->assertSame('bscn', $macro->club_id);
        $this->assertSame($macro->id, $meso->macrociclo_id);
        $this->assertSame($meso->id, $micro->mesociclo_id);
        $this->assertDatabaseHas('microcycles', [
            'id' => $micro->id,
            'club_id' => 'bscn',
        ]);
        $this->assertSame('2026-09-01', $micro->fresh()->data_inicio?->toDateString());
        $this->assertFalse((bool) $micro->fresh()->is_recovery_week);

        $pool = $this->pool();
        $athlete = User::factory()->athlete()->create();
        $session = $service->createSession([
            'microciclo_id' => $micro->id,
            'data' => '2026-09-03',
            'hora_inicio' => '18:00',
            'hora_fim' => '19:00',
            'sports_pool_id' => $pool->id,
            'tipo_treino' => 'Técnico',
            'instrucao' => 'Sessão planeada',
            'athlete_ids' => [$athlete->id],
        ], $actor);

        $this->assertSame($season->id, $session->epoca_id);
        $this->assertSame($macro->id, $session->macrocycle_id);
        $this->assertSame($meso->id, $session->mesociclo_id);
        $this->assertDatabaseHas('training_athletes', [
            'treino_id' => $session->id,
            'user_id' => $athlete->id,
        ]);

        $service->archiveMicrocycle($micro->fresh(), $actor);
        $this->assertFalse((bool) $micro->fresh()->active);
        $this->assertNotNull($micro->fresh()->archived_at);
    }

    public function test_session_can_be_created_without_periodisation_but_keeps_its_season(): void
    {
        $actor = User::factory()->create();
        $season = $this->season();
        $service = app(SportsPlanningWorkspaceService::class);

        $session = $service->createSession([
            'season_id' => $season->id,
            'data' => '2026-09-10',
            'hora_inicio' => '18:00',
            'hora_fim' => '19:00',
            'tipo_treino' => 'Técnico',
            'instrucao' => 'Trabalho de técnica',
        ], $actor);

        $this->assertSame($season->id, $session->epoca_id);
        $this->assertNull($session->microciclo_id);
        $this->assertNull($session->mesociclo_id);
        $this->assertNull($session->macrocycle_id);
        $this->assertDatabaseHas('trainings', ['id' => $session->id, 'epoca_id' => $season->id]);
    }

    public function test_recurrence_can_generate_sessions_without_a_microcycle(): void
    {
        $actor = User::factory()->create();
        $season = $this->season();
        $service = app(SportsPlanningWorkspaceService::class);

        $recurrence = $service->createRecurrence([
            'season_id' => $season->id,
            'name' => 'Sessão semanal',
            'starts_on' => '2026-09-14',
            'ends_on' => '2026-09-14',
            'frequency' => 'weekly',
            'weekdays' => [1],
            'start_time' => '18:00',
            'end_time' => '19:00',
            'training_type' => 'Técnico',
            'instruction' => 'Exercícios técnicos',
        ], $actor);

        $this->assertNull($recurrence->microcycle_id);
        $this->assertSame($season->id, $recurrence->season_id);
        $result = $service->generateRecurrence($recurrence, '2026-09-14', $actor);
        $this->assertCount(1, $result['created']);
        $this->assertSame($season->id, $result['created'][0]->epoca_id);
        $this->assertNull($result['created'][0]->microciclo_id);
    }

    public function test_recurrence_generates_group_microcycle_without_changing_the_recurrence_rule(): void
    {
        $actor = User::factory()->create();
        $season = $this->season();
        $planning = app(SportsPlanningWorkspaceService::class);
        $macro = $planning->createMacrocycle([
            'epoca_id' => $season->id, 'nome' => 'Macro grupo',
            'tipo' => 'Preparação geral', 'data_inicio' => '2026-09-01', 'data_fim' => '2026-10-31',
        ], $actor);
        $meso = $planning->createMesocycle([
            'macrociclo_id' => $macro->id, 'nome' => 'Meso grupo',
            'data_inicio' => '2026-09-01', 'data_fim' => '2026-10-31',
            'objetivo_principal' => 'Base',
        ], $actor);
        $micro = $planning->createMicrocycle([
            'mesociclo_id' => $meso->id, 'semana' => 'Semana grupo',
            'data_inicio' => '2026-09-14', 'data_fim' => '2026-09-20',
        ], $actor);
        $group = app(TrainingGroupService::class)->create([
            'code' => 'GROUP-' . substr((string) \Illuminate\Support\Str::uuid(), 0, 6),
            'name' => 'Grupo recorrente',
        ], $actor);
        \App\Models\TrainingGroupSeason::query()->create([
            'club_id' => 'bscn', 'training_group_id' => $group->id,
            'season_id' => $season->id, 'active' => true,
        ]);
        app(\App\Services\Desportivo\SportsCycleAssignmentService::class)->replace(
            'micro', (string) $micro->id, 'group', [(string) $group->id], (string) $actor->id
        );
        $rule = $planning->createRecurrence([
            'season_id' => $season->id, 'name' => 'Recorrente',
            'starts_on' => '2026-09-14', 'ends_on' => '2026-09-14',
            'frequency' => 'weekly', 'weekdays' => [1],
            'start_time' => '18:00', 'end_time' => '19:00',
            'training_type' => 'Técnico',
            'groups' => [['training_group_id' => $group->id]],
        ], $actor);
        $result = $planning->generateRecurrence($rule, '2026-09-14', $actor);
        $this->assertCount(1, $result['created']);
        $this->assertSame((string) $micro->id, (string) $result['created'][0]->microciclo_id);
        $this->assertNull($rule->fresh()->microcycle_id);
    }

    public function test_recurrence_blocks_group_cycle_when_athlete_has_incompatible_individual_cycle(): void
    {
        $actor = User::factory()->create();
        $athlete = User::factory()->athlete()->create();
        $season = $this->season();
        $planning = app(SportsPlanningWorkspaceService::class);
        $macro = $planning->createMacrocycle([
            'epoca_id' => $season->id, 'nome' => 'Macro',
            'tipo' => 'Preparação geral', 'data_inicio' => '2026-09-01', 'data_fim' => '2026-10-31',
        ], $actor);
        $meso = $planning->createMesocycle([
            'macrociclo_id' => $macro->id, 'nome' => 'Meso',
            'data_inicio' => '2026-09-01', 'data_fim' => '2026-10-31',
            'objetivo_principal' => 'Base',
        ], $actor);
        $groupMicro = $planning->createMicrocycle([
            'mesociclo_id' => $meso->id, 'semana' => 'Grupo',
            'data_inicio' => '2026-09-14', 'data_fim' => '2026-09-20',
        ], $actor);
        $individualMicro = $planning->createMicrocycle([
            'mesociclo_id' => $meso->id, 'semana' => 'Individual',
            'data_inicio' => '2026-09-14', 'data_fim' => '2026-09-20',
        ], $actor);
        $group = app(TrainingGroupService::class)->create([
            'code' => 'EXCEPTION-' . substr((string) \Illuminate\Support\Str::uuid(), 0, 6),
            'name' => 'Grupo exceção',
        ], $actor);
        $groupSeason = \App\Models\TrainingGroupSeason::query()->create([
            'club_id' => 'bscn', 'training_group_id' => $group->id,
            'season_id' => $season->id, 'active' => true,
        ]);
        \App\Models\TrainingGroupMembership::query()->create([
            'club_id' => 'bscn', 'training_group_id' => $group->id,
            'training_group_season_id' => $groupSeason->id, 'user_id' => $athlete->id,
            'starts_at' => '2026-09-01', 'created_by' => $actor->id,
        ]);
        $assignments = app(\App\Services\Desportivo\SportsCycleAssignmentService::class);
        $assignments->replace('micro', (string) $groupMicro->id, 'group', [(string) $group->id], (string) $actor->id);
        \App\Models\SportsAthleteParticipation::query()->create([
            'club_id' => 'bscn', 'user_id' => $athlete->id,
            'sports_modality_id' => $season->sports_modality_id,
            'active' => true, 'current_slot' => 'current',
        ]);
        $assignments->replace('micro', (string) $individualMicro->id, 'athlete', [(string) $athlete->id], (string) $actor->id);
        $rule = $planning->createRecurrence([
            'season_id' => $season->id, 'name' => 'Recorrência protegida',
            'starts_on' => '2026-09-14', 'ends_on' => '2026-09-14',
            'frequency' => 'weekly', 'weekdays' => [1],
            'start_time' => '18:00', 'end_time' => '19:00',
            'training_type' => 'Técnico',
            'groups' => [['training_group_id' => $group->id]],
        ], $actor);
        $result = $planning->generateRecurrence($rule, '2026-09-14', $actor);
        $this->assertCount(0, $result['created']);
        $this->assertCount(1, $result['blocked']);
        $this->assertDatabaseMissing('trainings', ['training_recurrence_id' => $rule->id]);
    }

    public function test_recurrence_uses_canonical_pool_lane_and_does_not_rewrite_generated_session(): void
    {
        $actor = User::factory()->create();
        $season = $this->season();
        $service = app(SportsPlanningWorkspaceService::class);

        $macro = $service->createMacrocycle([
            'epoca_id' => $season->id,
            'nome' => 'Macro',
            'tipo' => 'Preparação geral',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-31',
        ], $actor);
        $meso = $service->createMesocycle([
            'macrociclo_id' => $macro->id,
            'nome' => 'Meso',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-10-31',
            'objetivo_principal' => 'Base',
        ], $actor);
        $micro = $service->createMicrocycle([
            'mesociclo_id' => $meso->id,
            'semana' => 'S1',
            'data_inicio' => '2026-09-14',
            'data_fim' => '2026-09-20',
        ], $actor);

        $pool = $this->pool();
        $lane = $pool->lanes()->firstOrFail();
        $group = app(TrainingGroupService::class)->create([
            'code' => 'PLAN-' . substr((string) \Illuminate\Support\Str::uuid(), 0, 6),
            'name' => 'Grupo Planeamento',
        ], $actor);

        $recurrence = $service->createRecurrence([
            'microcycle_id' => $micro->id,
            'name' => 'Seg/Qua',
            'starts_on' => '2026-09-14',
            'ends_on' => '2026-09-20',
            'frequency' => 'weekly',
            'interval' => 1,
            'weekdays' => [1, 3],
            'start_time' => '18:00',
            'end_time' => '19:00',
            'sports_pool_id' => $pool->id,
            'session_status_template' => 'draft',
            'groups' => [[
                'training_group_id' => $group->id,
                'instruction' => 'Técnico',
                'lanes' => [['lane_id' => $lane->id]],
            ]],
        ], $actor);

        $result = $service->generateRecurrence($recurrence, '2026-09-20', $actor);
        $this->assertCount(2, $result['created']);
        $first = $result['created'][0];
        $originalTime = $first->hora_inicio;

        $service->updateRecurrence($recurrence->fresh(), [
            'microcycle_id' => $micro->id,
            'name' => 'Seg/Qua alterado',
            'starts_on' => '2026-09-14',
            'ends_on' => '2026-09-20',
            'frequency' => 'weekly',
            'interval' => 1,
            'weekdays' => [1, 3],
            'start_time' => '19:00',
            'end_time' => '20:00',
            'sports_pool_id' => $pool->id,
            'session_status_template' => 'draft',
            'groups' => [[
                'training_group_id' => $group->id,
                'instruction' => 'Técnico',
                'lanes' => [['lane_id' => $lane->id]],
            ]],
        ], $actor);

        $this->assertSame($originalTime, $first->fresh()->hora_inicio);
        $this->assertDatabaseHas('training_recurrence_group_lanes', [
            'sports_pool_lane_id' => $lane->id,
        ]);
    }

    public function test_season_objectives_append_versions(): void
    {
        $actor = User::factory()->create();
        $season = $this->season();
        $service = app(SportsPlanningWorkspaceService::class);

        $objective = $service->createObjective([
            'target_type' => 'season',
            'target_id' => $season->id,
            'title' => 'Melhorar consistência',
            'description' => 'Objetivo inicial',
            'objective_type' => 'text',
        ], $actor);
        $service->reviseObjective($objective, [
            'title' => 'Melhorar consistência',
            'description' => 'Objetivo revisto',
            'objective_type' => 'text',
        ], $actor);

        $this->assertSame(2, $objective->fresh()->current_version);
        $this->assertSame(2, $objective->versions()->count());
    }

    public function test_cycle_assignments_persist_for_active_athletes_without_touching_trainings(): void
    {
        $actor = User::factory()->create();
        $athlete = User::factory()->athlete()->create();
        $season = $this->season();
        \App\Models\SportsAthleteParticipation::query()->create([
            'club_id' => 'bscn', 'user_id' => $athlete->id,
            'sports_modality_id' => $season->sports_modality_id,
            'active' => true, 'current_slot' => 'current',
        ]);
        $macro = app(SportsPlanningWorkspaceService::class)->createMacrocycle([
            'epoca_id' => $season->id, 'nome' => 'Base', 'tipo' => 'Preparação geral',
            'data_inicio' => '2026-09-01', 'data_fim' => '2026-10-31',
        ], $actor);
        $service = app(\App\Services\Desportivo\SportsCycleAssignmentService::class);
        $service->replace('macro', (string) $macro->id, 'athlete', [(string) $athlete->id], (string) $actor->id);
        $this->assertDatabaseHas('sports_cycle_assignments', [
            'club_id' => 'bscn', 'season_id' => $season->id, 'cycle_id' => $macro->id,
            'target_type' => 'athlete', 'target_id' => $athlete->id,
        ]);
        $this->assertDatabaseCount('trainings', 0);
        $service->replace('macro', (string) $macro->id, 'athlete', [], (string) $actor->id);
        $this->assertDatabaseMissing('sports_cycle_assignments', [
            'cycle_id' => $macro->id, 'target_id' => $athlete->id,
        ]);
    }

    public function test_cycle_assignments_are_persisted_and_reject_outside_targets(): void
    {
        $actor = User::factory()->create();
        $season = $this->season();
        $macro = app(SportsPlanningWorkspaceService::class)->createMacrocycle([
            'epoca_id' => $season->id, 'nome' => 'Base', 'tipo' => 'Preparação geral',
            'data_inicio' => '2026-09-01', 'data_fim' => '2026-10-31',
        ], $actor);
        $assignments = app(\App\Services\Desportivo\SportsCycleAssignmentService::class);
        $assignments->replace('macro', (string) $macro->id, 'group', [], (string) $actor->id);
        $this->assertDatabaseMissing('sports_cycle_assignments', [
            'cycle_type' => 'macro', 'cycle_id' => $macro->id,
        ]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $assignments->replace('macro', (string) $macro->id, 'athlete', [(string) $actor->id], (string) $actor->id);
    }

    public function test_new_session_uses_individual_microcycle_assignment_without_rewriting_existing_sessions(): void
    {
        $actor = User::factory()->create();
        $athlete = User::factory()->athlete()->create();
        $season = $this->season();
        \App\Models\SportsAthleteParticipation::query()->create([
            'club_id' => 'bscn', 'user_id' => $athlete->id,
            'sports_modality_id' => $season->sports_modality_id, 'active' => true,
            'current_slot' => 'current',
        ]);
        $planning = app(SportsPlanningWorkspaceService::class);
        $macro = $planning->createMacrocycle([
            'epoca_id' => $season->id, 'nome' => 'Base', 'tipo' => 'Preparação geral',
            'data_inicio' => '2026-09-01', 'data_fim' => '2026-10-31',
        ], $actor);
        $meso = $planning->createMesocycle([
            'macrociclo_id' => $macro->id, 'nome' => 'Base 1',
            'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30',
            'objetivo_principal' => 'Técnica',
        ], $actor);
        $micro = $planning->createMicrocycle([
            'mesociclo_id' => $meso->id, 'semana' => 'Semana 1',
            'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-07',
        ], $actor);
        app(\App\Services\Desportivo\SportsCycleAssignmentService::class)->replace(
            'micro', (string) $micro->id, 'athlete', [(string) $athlete->id], (string) $actor->id
        );
        $training = $planning->createSession([
            'season_id' => $season->id, 'data' => '2026-09-03',
            'hora_inicio' => '18:00', 'hora_fim' => '19:00',
            'tipo_treino' => 'Técnico', 'athlete_ids' => [(string) $athlete->id],
        ], $actor);
        $this->assertSame((string) $micro->id, (string) $training->microciclo_id);
        $this->assertSame((string) $season->id, (string) $training->epoca_id);
    }

    private function season(): Season
    {
        $modality = SportsModality::query()
            ->where('club_id', 'bscn')
            ->where('code', 'swimming')
            ->firstOrFail();

        return Season::query()->create([
            'club_id' => 'bscn',
            'sports_modality_id' => $modality->id,
            'nome' => '2026/27',
            'ano_temporada' => '2026/27',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'tipo' => 'Principal',
            'estado' => 'Em curso',
            'status' => 'active',
        ]);
    }

    private function pool(): SportsPool
    {
        $venue = SportsVenue::query()->create([
            'club_id' => 'bscn',
            'code' => 'PLAN-' . substr((string) \Illuminate\Support\Str::uuid(), 0, 6),
            'name' => 'Piscina Planeamento',
            'venue_type' => 'pool',
            'active' => true,
        ]);
        $pool = SportsPool::query()->create([
            'club_id' => 'bscn',
            'sports_venue_id' => $venue->id,
            'code' => 'p25',
            'name' => 'Tanque 25m',
            'length_m' => 25,
            'active' => true,
        ]);
        SportsPoolLane::query()->create([
            'club_id' => 'bscn',
            'sports_pool_id' => $pool->id,
            'lane_number' => 1,
            'name' => 'Pista 1',
            'capacity' => 8,
            'active' => true,
        ]);
        return $pool;
    }
}
