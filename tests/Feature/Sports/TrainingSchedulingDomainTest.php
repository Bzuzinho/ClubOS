<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\ClubSetting;
use App\Models\SportsPool;
use App\Models\SportsPoolLane;
use App\Models\SportsVenue;
use App\Models\SportsVenueClosure;
use App\Models\Training;
use App\Models\TrainingScheduleException;
use App\Models\User;
use App\Services\Desportivo\CreateTrainingAction;
use App\Services\Desportivo\TrainingGroupMembershipService;
use App\Services\Desportivo\TrainingGroupService;
use App\Services\Desportivo\TrainingRecurrenceService;
use App\Services\Desportivo\TrainingScheduleExceptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TrainingSchedulingDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_planning_schema_is_additive_tenant_aware_and_has_canonical_lane_keys(): void
    {
        foreach ([
            'sports_venues',
            'sports_venue_lanes',
            'sports_pools',
            'sports_pool_lanes',
            'sports_venue_closures',
            'training_recurrences',
            'training_recurrence_groups',
            'training_recurrence_group_lanes',
            'training_session_groups',
            'training_session_group_lanes',
            'training_schedule_exceptions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table . ' should exist');
        }

        $this->assertTrue(Schema::hasColumns('trainings', [
            'sports_venue_id',
            'sports_pool_id',
            'training_recurrence_id',
            'recurrence_occurrence_key',
            'schedule_review_required',
            'schedule_conflicts_snapshot',
        ]));
        $this->assertTrue(Schema::hasColumn('training_session_group_lanes', 'sports_pool_lane_id'));
        $this->assertTrue(Schema::hasColumn('training_recurrence_group_lanes', 'sports_pool_lane_id'));

        $this->assertTrue(Schema::hasColumns('club_settings', [
            'sports_lane_overlap_policy',
            'sports_athlete_overlap_policy',
            'sports_capacity_policy',
        ]));
    }

    public function test_group_session_uses_dated_membership_and_keeps_canonical_lane_assignment(): void
    {
        $coach = User::factory()->create();
        $athlete = User::factory()->athlete()->create();
        $unrelated = User::factory()->athlete()->create();
        [$venue, $pool, $lanes] = $this->venue(8, 2);
        $group = $this->group('COMP-A');

        app(TrainingGroupMembershipService::class)->assign(
            $group,
            $athlete,
            true,
            '2026-09-01',
            null,
            null,
            $coach
        );

        $session = app(CreateTrainingAction::class)->execute([
            'data' => '2026-09-15',
            'hora_inicio' => '18:00',
            'hora_fim' => '19:30',
            'sports_pool_id' => $pool->id,
            'responsavel_id' => $coach->id,
            'tipo_treino' => 'Técnico',
            'instrucao' => 'Trabalho técnico por grupo.',
            'training_groups' => [[
                'training_group_id' => $group->id,
                'lanes' => [['lane_id' => $lanes[0]->id]],
            ]],
        ], $coach);

        $this->assertSame('bscn', $session->club_id);
        $this->assertSame((string) $venue->id, (string) $session->sports_venue_id);
        $this->assertSame((string) $pool->id, (string) $session->sports_pool_id);
        $this->assertSame(1, $session->sessionGroups()->count());
        $this->assertSame(
            (string) $lanes[0]->id,
            (string) $session->sessionGroups()->firstOrFail()->lanes()->firstOrFail()->id
        );
        $this->assertSame([$athlete->id], $session->athleteRecords()->pluck('user_id')->all());
        $this->assertFalse($session->athleteRecords()->where('user_id', $unrelated->id)->exists());
        $this->assertDatabaseHas('training_session_group_lanes', [
            'sports_pool_lane_id' => $lanes[0]->id,
        ]);
    }

    public function test_lane_overlap_warns_or_blocks_according_to_club_policy(): void
    {
        $coach = User::factory()->create();
        [, $pool, $lanes] = $this->venue(8, 1);
        $groupA = $this->group('G-A');
        $groupB = $this->group('G-B');

        app(TrainingGroupMembershipService::class)->assign(
            $groupA,
            User::factory()->athlete()->create(),
            true,
            '2026-09-01'
        );
        app(TrainingGroupMembershipService::class)->assign(
            $groupB,
            User::factory()->athlete()->create(),
            true,
            '2026-09-01'
        );

        $this->settings('warn', 'allow', 'allow');
        $first = $this->groupSession($coach, $pool, $lanes[0], $groupA, '#L001');
        $second = $this->groupSession($coach, $pool, $lanes[0], $groupB, '#L002');

        $this->assertTrue($first->fresh()->schedule_review_required === false);
        $this->assertTrue($second->fresh()->schedule_review_required);
        $this->assertSame('lane_overlap', $second->fresh()->schedule_conflicts_snapshot[0]['type']);
        $this->assertSame('warning', $second->fresh()->schedule_conflicts_snapshot[0]['severity']);
        $this->assertSame(
            (string) $lanes[0]->id,
            (string) $second->fresh()->schedule_conflicts_snapshot[0]['context']['sports_pool_lane_ids'][0]
        );

        ClubSetting::query()->update(['sports_lane_overlap_policy' => 'block']);

        try {
            $this->groupSession($coach, $pool, $lanes[0], $this->group('G-C'), '#L003');
            $this->fail('A third overlapping session should have been blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('schedule', $exception->errors());
        }

        $this->assertSame(2, Training::query()->whereDate('data', '2026-09-15')->count());
    }

    public function test_athlete_double_booking_and_capacity_can_be_blocked_independently(): void
    {
        $coach = User::factory()->create();
        [, $pool, $lanes] = $this->venue(2, 2);
        $athlete = User::factory()->athlete()->create();
        $groupA = $this->group('A');
        $groupB = $this->group('B');
        $memberships = app(TrainingGroupMembershipService::class);

        $memberships->assign($groupA, $athlete, true, '2026-09-01');
        $memberships->assign($groupB, $athlete, false, '2026-09-01');

        $this->settings('allow', 'block', 'allow');
        $this->groupSession($coach, $pool, $lanes[0], $groupA, '#A001');

        try {
            $this->groupSession($coach, $pool, $lanes[1], $groupB, '#A002');
            $this->fail('Double booked athlete should have been blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('schedule', $exception->errors());
        }

        ClubSetting::query()->update([
            'sports_athlete_overlap_policy' => 'allow',
            'sports_capacity_policy' => 'block',
        ]);

        $fullGroup = $this->group('FULL');
        foreach (range(1, 3) as $index) {
            $memberships->assign(
                $fullGroup,
                User::factory()->athlete()->create(),
                $index === 1,
                '2026-09-01'
            );
        }

        $this->expectException(ValidationException::class);
        $this->groupSession($coach, $pool, $lanes[1], $fullGroup, '#C001');
    }

    public function test_recurrence_creates_canonical_drafts_preserves_closure_and_is_idempotent(): void
    {
        $this->settings('warn', 'warn', 'warn');
        $coach = User::factory()->create();
        [$venue, $pool, $lanes] = $this->venue(8, 1);
        $group = $this->group('REC');
        $athlete = User::factory()->athlete()->create();

        app(TrainingGroupMembershipService::class)->assign($group, $athlete, true, '2026-09-01');

        SportsVenueClosure::query()->create([
            'club_id' => 'bscn',
            'sports_venue_id' => $venue->id,
            'sports_pool_id' => $pool->id,
            'sports_pool_lane_id' => $lanes[0]->id,
            'starts_at' => '2026-09-14 17:00:00',
            'ends_at' => '2026-09-14 20:00:00',
            'reason' => 'Manutenção',
            'status' => 'active',
            'created_by' => $coach->id,
        ]);

        $service = app(TrainingRecurrenceService::class);
        $recurrence = $service->create([
            'name' => 'Segundas e quartas',
            'starts_on' => '2026-09-14',
            'ends_on' => '2026-09-30',
            'frequency' => 'weekly',
            'interval' => 1,
            'weekdays' => [1, 3],
            'start_time' => '18:00',
            'end_time' => '19:00',
            'sports_pool_id' => $pool->id,
            'responsavel_id' => $coach->id,
            'session_status_template' => 'published',
            'groups' => [[
                'training_group_id' => $group->id,
                'lanes' => [['lane_id' => $lanes[0]->id]],
            ]],
        ], $coach);

        $result = $service->generateUntil($recurrence, '2026-09-20', $coach);

        $this->assertCount(2, $result['created']);
        $this->assertSame([], $result['blocked']);

        $monday = Training::query()
            ->where('training_recurrence_id', $recurrence->id)
            ->where('recurrence_occurrence_key', '2026-09-14')
            ->firstOrFail();

        $this->assertSame('draft', $monday->session_status);
        $this->assertTrue($monday->schedule_review_required);
        $this->assertSame('closure', $monday->schedule_conflicts_snapshot[0]['type']);
        $this->assertSame('decision_required', $monday->schedule_conflicts_snapshot[0]['severity']);
        $this->assertSame(
            (string) $lanes[0]->id,
            (string) $monday->schedule_conflicts_snapshot[0]['context']['sports_pool_lane_id']
        );
        $this->assertDatabaseHas('training_recurrence_group_lanes', [
            'sports_pool_lane_id' => $lanes[0]->id,
        ]);

        $secondRun = $service->generateUntil($recurrence->fresh(), '2026-09-20', $coach);
        $this->assertCount(0, $secondRun['created']);
        $this->assertCount(2, $secondRun['skipped']);
    }

    public function test_manual_athlete_can_be_added_without_parallel_roster(): void
    {
        $coach = User::factory()->create();
        $athlete = User::factory()->athlete()->create();
        [, $pool] = $this->venue(8, 1);

        $session = app(CreateTrainingAction::class)->execute([
            'data' => '2026-09-15',
            'hora_inicio' => '17:00',
            'hora_fim' => '18:00',
            'sports_pool_id' => $pool->id,
            'tipo_treino' => 'Individual',
            'instrucao' => 'Sessão individual.',
            'athlete_ids' => [$athlete->id],
        ], $coach);

        $this->assertDatabaseHas('training_athletes', [
            'treino_id' => $session->id,
            'user_id' => $athlete->id,
        ]);
    }

    public function test_operational_schedule_exception_is_audited_without_rewriting_planning(): void
    {
        $actor = User::factory()->create();
        $session = Training::query()->create([
            'numero_treino' => '#EX001',
            'data' => '2026-09-15',
            'hora_inicio' => '18:00',
            'hora_fim' => '19:00',
            'local' => 'Piscina',
            'tipo_treino' => 'Técnico',
            'club_id' => 'bscn',
            'session_status' => 'draft',
            'criado_por' => $actor->id,
        ]);

        $exception = app(TrainingScheduleExceptionService::class)->record(
            $session,
            'lane_change',
            ['lane_id' => 'planned-lane'],
            ['lane_id' => 'operational-lane'],
            'Alteração operacional no cais.',
            $actor
        );

        $this->assertSame(1, TrainingScheduleException::query()->where('training_id', $session->id)->count());
        $this->assertSame('lane_change', $exception->exception_type);
        $this->assertSame('planned-lane', $exception->before_state['lane_id']);
        $this->assertSame('operational-lane', $exception->after_state['lane_id']);
    }

    /** @return array{0:SportsVenue,1:SportsPool,2:array<int,SportsPoolLane>} */
    private function venue(int $laneCapacity, int $laneCount): array
    {
        $venue = SportsVenue::query()->create([
            'club_id' => 'bscn',
            'code' => 'POOL-' . substr((string) \Illuminate\Support\Str::uuid(), 0, 8),
            'name' => 'Piscina Municipal',
            'venue_type' => 'pool',
            'active' => true,
        ]);

        $pool = SportsPool::query()->create([
            'club_id' => 'bscn',
            'sports_venue_id' => $venue->id,
            'code' => 'MAIN',
            'name' => 'Tanque principal',
            'length_m' => 25,
            'active' => true,
        ]);

        $lanes = [];
        foreach (range(1, $laneCount) as $number) {
            $lanes[] = SportsPoolLane::query()->create([
                'club_id' => 'bscn',
                'sports_pool_id' => $pool->id,
                'lane_number' => $number,
                'name' => 'Pista ' . $number,
                'capacity' => $laneCapacity,
                'active' => true,
            ]);
        }

        return [$venue, $pool, $lanes];
    }

    private function group(string $code)
    {
        return app(TrainingGroupService::class)->create([
            'code' => $code . '-' . substr((string) \Illuminate\Support\Str::uuid(), 0, 6),
            'name' => $code,
        ]);
    }

    private function groupSession(
        User $coach,
        SportsPool $pool,
        SportsPoolLane $lane,
        $group,
        string $number,
    ): Training {
        return app(CreateTrainingAction::class)->execute([
            'numero_treino' => $number,
            'data' => '2026-09-15',
            'hora_inicio' => '18:00',
            'hora_fim' => '19:00',
            'sports_pool_id' => $pool->id,
            'responsavel_id' => $coach->id,
            'tipo_treino' => 'Técnico',
            'instrucao' => 'Sessão planeada.',
            'training_groups' => [[
                'training_group_id' => $group->id,
                'lanes' => [['lane_id' => $lane->id]],
            ]],
        ], $coach);
    }

    private function settings(string $lane, string $athlete, string $capacity): ClubSetting
    {
        return ClubSetting::query()->create([
            'nome_clube' => 'BSCN',
            'sports_lane_overlap_policy' => $lane,
            'sports_athlete_overlap_policy' => $athlete,
            'sports_capacity_policy' => $capacity,
        ]);
    }
}
