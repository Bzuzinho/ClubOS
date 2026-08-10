<?php

namespace Tests\Feature\Sports;

use App\Models\AgeGroup;
use App\Models\AthleteIndicatorDefinition;
use App\Models\AthleteIndicatorRecord;
use App\Models\SportsObjectiveVersion;
use App\Models\TrainingGroupMembership;
use App\Models\User;
use App\Services\Desportivo\AthleteIndicatorService;
use App\Services\Desportivo\SportsObjectiveService;
use App\Services\Desportivo\TrainingGroupMembershipService;
use App\Services\Desportivo\TrainingGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SportsGroupsObjectivesIndicatorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sports.club_id' => 'bscn']);
    }

    public function test_pr3_schema_is_additive_and_tenant_aware(): void
    {
        foreach ([
            'training_groups',
            'training_group_memberships',
            'training_group_coaches',
            'training_group_age_groups',
            'sports_objectives',
            'sports_objective_versions',
            'athlete_indicator_definitions',
            'athlete_indicator_records',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table . ' should exist');
        }

        $this->assertTrue(Schema::hasColumns('training_groups', ['club_id', 'name', 'modality', 'active']));
        $this->assertTrue(Schema::hasColumns('training_group_memberships', ['club_id', 'user_id', 'is_primary', 'starts_at', 'ends_at']));
        $this->assertTrue(Schema::hasColumns('sports_objectives', ['club_id', 'target_type', 'target_id', 'current_version']));
        $this->assertTrue(Schema::hasColumns('sports_objective_versions', ['club_id', 'sports_objective_id', 'version']));
        $this->assertTrue(Schema::hasColumns('athlete_indicator_records', ['club_id', 'definition_version', 'recorded_at']));
    }

    public function test_training_groups_mix_age_groups_and_preserve_membership_history(): void
    {
        $juvenis = AgeGroup::query()->create(['nome' => 'Juvenis', 'ativo' => true]);
        $juniores = AgeGroup::query()->create(['nome' => 'Juniores', 'ativo' => true]);
        $athlete = User::factory()->athlete()->create();

        $groupService = app(TrainingGroupService::class);
        $membershipService = app(TrainingGroupMembershipService::class);

        $mainGroup = $groupService->create([
            'code' => 'COMP-A',
            'name' => 'Competição A',
            'age_group_ids' => [$juvenis->id, $juniores->id],
        ]);
        $complementaryGroup = $groupService->create([
            'code' => 'TECNICA',
            'name' => 'Técnica complementar',
            'age_group_ids' => [$juvenis->id],
        ]);

        $this->assertSame('bscn', $mainGroup->club_id);
        $this->assertCount(2, $mainGroup->ageGroups);

        $primary = $membershipService->assign($mainGroup, $athlete, true, '2026-09-01');
        $membershipService->assign($complementaryGroup, $athlete, false, '2026-09-01');

        try {
            $membershipService->assign($complementaryGroup, $athlete, true, '2026-10-01');
            $this->fail('Overlapping primary membership should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_primary', $exception->errors());
        }

        $membershipService->close($primary, '2026-09-30');
        $secondPrimary = $membershipService->assign($complementaryGroup, $athlete, true, '2026-10-01');

        $this->assertSame(3, TrainingGroupMembership::query()->where('user_id', $athlete->id)->count());
        $this->assertSame('2026-09-30', $primary->fresh()->ends_at?->format('Y-m-d'));
        $this->assertTrue($secondPrimary->is_primary);
    }

    public function test_objective_revisions_are_versioned_without_overwriting_history(): void
    {
        $group = app(TrainingGroupService::class)->create([
            'code' => 'SPRINT',
            'name' => 'Sprint',
        ]);

        $service = app(SportsObjectiveService::class);
        $objective = $service->create([
            'target_type' => 'training_group',
            'target_id' => $group->id,
            'due_at' => '2026-12-31',
        ], [
            'title' => 'Melhorar 100L',
            'objective_type' => 'measurable',
            'indicator_key' => '100_free_time',
            'target_value' => 60.0000,
            'target_unit' => 's',
            'visibility' => ['staff', 'athlete'],
        ]);

        $objective = $service->revise($objective, [
            'title' => 'Melhorar 100L',
            'objective_type' => 'measurable',
            'indicator_key' => '100_free_time',
            'target_value' => 59.5000,
            'target_unit' => 's',
            'visibility' => ['staff', 'athlete', 'guardian'],
            'notes' => 'Meta revista após avaliação intermédia.',
        ]);

        $this->assertSame(2, $objective->current_version);
        $this->assertSame(2, SportsObjectiveVersion::query()->where('sports_objective_id', $objective->id)->count());
        $this->assertSame('60.0000', (string) $objective->versions()->where('version', 1)->firstOrFail()->target_value);
        $this->assertSame('59.5000', (string) $objective->versions()->where('version', 2)->firstOrFail()->target_value);
        $this->assertSame(['staff', 'athlete', 'guardian'], $objective->latestVersion->visibility);
    }

    public function test_indicator_definition_changes_keep_historical_record_semantics(): void
    {
        $athlete = User::factory()->athlete()->create();
        $service = app(AthleteIndicatorService::class);

        $definition = $service->createDefinition([
            'code' => 'body_mass',
            'name' => 'Peso',
            'data_type' => 'number',
            'unit' => 'kg',
            'category' => 'anthropometry',
            'shareable_by_default' => true,
        ]);

        $first = $service->record($definition, $athlete, 70.2, '2026-09-01 08:00:00');

        $definition = $service->updateDefinition($definition, [
            'name' => 'Massa corporal',
            'unit' => 'kg',
        ]);

        $second = $service->record($definition, $athlete, 69.8, '2026-10-01 08:00:00');

        $this->assertSame(2, $definition->version);
        $this->assertSame(1, $first->definition_version);
        $this->assertSame('body_mass', $first->indicator_code);
        $this->assertSame('Peso', $first->indicator_name);
        $this->assertSame('kg', $first->indicator_unit);
        $this->assertSame('70.200000', (string) $first->value_numeric);
        $this->assertSame(2, $second->definition_version);
        $this->assertSame('body_mass', $second->indicator_code);
        $this->assertSame('Massa corporal', $second->indicator_name);
        $this->assertSame('69.800000', (string) $second->value_numeric);
        $this->assertTrue($first->shareable);
    }

    public function test_archiving_indicator_definition_preserves_records_for_future_statistics(): void
    {
        $athlete = User::factory()->athlete()->create();
        $service = app(AthleteIndicatorService::class);
        $definition = $service->createDefinition([
            'code' => 'rpe',
            'name' => 'RPE',
            'data_type' => 'number',
        ]);
        $record = $service->record($definition, $athlete, 7, '2026-09-15 18:00:00');

        $service->archiveDefinition($definition);

        $this->assertNull(AthleteIndicatorDefinition::query()->find($definition->id));
        $this->assertNotNull(AthleteIndicatorDefinition::withTrashed()->find($definition->id));
        $this->assertSame(1, AthleteIndicatorRecord::query()->where('user_id', $athlete->id)->count());
        $this->assertSame($definition->id, $record->fresh()->definition?->id);
    }

    public function test_services_write_into_the_configured_sports_tenant(): void
    {
        config(['sports.club_id' => 'club-secondary']);

        $group = app(TrainingGroupService::class)->create([
            'code' => 'MASTER',
            'name' => 'Master',
        ]);
        $definition = app(AthleteIndicatorService::class)->createDefinition([
            'code' => 'sleep_quality',
            'name' => 'Qualidade do sono',
            'data_type' => 'number',
        ]);
        $objective = app(SportsObjectiveService::class)->create([
            'target_type' => 'club',
        ], [
            'title' => 'Aumentar consistência de treino',
            'objective_type' => 'text',
        ]);

        $this->assertSame('club-secondary', $group->club_id);
        $this->assertSame('club-secondary', $definition->club_id);
        $this->assertSame('club-secondary', $objective->club_id);
    }
}
