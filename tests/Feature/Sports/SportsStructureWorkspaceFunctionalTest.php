<?php

namespace Tests\Feature\Sports;

use App\Models\DadosPessoais;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsModality;
use App\Models\TrainingGroupCoach;
use App\Models\User;
use App\Services\Desportivo\SportsStructureService;
use App\Services\Desportivo\SportsStructureWorkspaceQueryService;
use App\Services\Desportivo\SportsStructureWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SportsStructureWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_exposes_complete_structure_routes(): void
    {
        foreach ([
            'desportivo.estrutura.epocas.store',
            'desportivo.estrutura.epocas.update',
            'desportivo.estrutura.escaloes.store',
            'desportivo.estrutura.grupos.store',
            'desportivo.estrutura.grupos.memberships.update',
            'desportivo.estrutura.treinadores.atribuicoes.store',
            'desportivo.estrutura.locais.store',
            'desportivo.estrutura.piscinas.update',
            'desportivo.estrutura.pistas.update',
        ] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
    }

    public function test_season_age_group_and_group_lifecycles_preserve_history(): void
    {
        $workspace = app(SportsStructureWorkspaceService::class);
        $foundation = app(SportsStructureService::class);
        $actor = User::factory()->create();
        $modality = SportsModality::query()->where('club_id', 'bscn')->where('code', 'swimming')->firstOrFail();

        $season = $workspace->createSeason([
            'sports_modality_id' => $modality->id,
            'nome' => 'Época 2027/28',
            'ano_temporada' => '2027/28',
            'data_inicio' => '2027-09-01',
            'data_fim' => '2028-07-31',
            'tipo' => 'Principal',
            'status' => 'active',
        ], $actor->id);

        $this->assertSame('Em curso', $season->estado);

        $ageGroup = $workspace->createAgeGroup([
            'code' => 'cadete-functional',
            'nome' => 'Cadete Functional',
            'idade_minima' => 13,
            'idade_maxima' => 14,
            'ativo' => true,
        ]);

        $group = $workspace->createGroup([
            'sports_modality_id' => $modality->id,
            'code' => 'g-functional',
            'name' => 'Grupo Functional',
            'age_group_ids' => [$ageGroup->id],
        ], $actor->id);

        $this->assertSame([$ageGroup->id], $group->ageGroups()->pluck('age_groups.id')->all());

        $program = $foundation->createProgram([
            'sports_modality_id' => $modality->id,
            'code' => 'competition-functional',
            'name' => 'Competição Functional',
        ], $actor->id);
        $foundation->syncSeasonProgram([
            'season_id' => $season->id,
            'sports_program_id' => $program->id,
            'active' => true,
        ], $actor->id);
        $context = $foundation->syncGroupSeason([
            'training_group_id' => $group->id,
            'season_id' => $season->id,
            'sports_program_id' => $program->id,
            'active' => true,
        ], $actor->id);

        $athlete = User::factory()->create();
        $membership = $foundation->assignMembershipWithSeasonContext([
            'training_group_season_id' => $context->id,
            'user_id' => $athlete->id,
            'is_primary' => true,
            'starts_at' => '2027-09-01',
        ], $actor->id);

        $workspace->endMembership($membership, '2027-12-31');
        $this->assertSame('2027-12-31', $membership->fresh()->ends_at->toDateString());

        $workspace->retireGroup($group->fresh(), $actor->id);
        $this->assertFalse((bool) $group->fresh()->active);
        $this->assertNotNull($group->fresh()->archived_at);

        $workspace->retireAgeGroup($ageGroup->fresh());
        $this->assertFalse((bool) $ageGroup->fresh()->ativo);
        $this->assertNotNull($ageGroup->fresh()->archived_at);

        $workspace->retireSeason($season->fresh(), $actor->id);
        $this->assertSame('archived', $season->fresh()->status);
        $this->assertDatabaseHas('training_group_memberships', ['id' => $membership->id]);
    }

    public function test_technical_team_and_locations_are_period_aware_and_archivable(): void
    {
        $workspace = app(SportsStructureWorkspaceService::class);
        $foundation = app(SportsStructureService::class);
        $actor = User::factory()->create();
        $coach = User::factory()->create();
        $modality = SportsModality::query()->where('club_id', 'bscn')->where('code', 'swimming')->firstOrFail();

        $season = $workspace->createSeason([
            'sports_modality_id' => $modality->id,
            'nome' => 'Época Technical',
            'ano_temporada' => '2028/29',
            'data_inicio' => '2028-09-01',
            'data_fim' => '2029-07-31',
            'status' => 'active',
        ], $actor->id);
        $group = $workspace->createGroup([
            'sports_modality_id' => $modality->id,
            'code' => 'technical-group',
            'name' => 'Technical Group',
        ], $actor->id);
        $context = $foundation->syncGroupSeason([
            'training_group_id' => $group->id,
            'season_id' => $season->id,
            'active' => true,
        ], $actor->id);
        $role = $foundation->createCoachRole([
            'code' => 'head-functional',
            'name' => 'Treinador Principal Functional',
        ], $actor->id);

        $assignment = $workspace->assignCoach([
            'training_group_season_id' => $context->id,
            'user_id' => $coach->id,
            'sports_coach_role_id' => $role->id,
            'starts_at' => '2028-09-01',
        ], $actor->id);

        $workspace->endCoach($assignment, '2029-01-31');
        $this->assertSame('2029-01-31', TrainingGroupCoach::findOrFail($assignment->id)->ends_at->toDateString());

        $venue = $workspace->createVenue([
            'code' => 'municipal-functional',
            'name' => 'Piscina Municipal Functional',
            'venue_type' => 'pool',
            'address' => 'Rua da Piscina',
            'active' => true,
        ], $actor->id);
        $pool = $foundation->createPool([
            'sports_venue_id' => $venue->id,
            'code' => 'p25',
            'name' => 'Tanque 25m',
            'length_m' => 25,
            'capacity' => 40,
            'active' => true,
        ], $actor->id);
        $lane = $foundation->addLane($pool, [
            'lane_number' => 1,
            'capacity' => 8,
            'active' => true,
        ], $actor->id);

        $workspace->updateLane($lane, ['lane_number' => 1, 'capacity' => 10, 'active' => true], $actor->id);
        $this->assertSame(10, $lane->fresh()->capacity);

        $workspace->retireVenue($venue, $actor->id);
        $this->assertFalse((bool) $venue->fresh()->active);
        $this->assertNotNull($venue->fresh()->archived_at);
    }

    public function test_workspace_query_uses_canonical_member_display_and_sports_participation(): void
    {
        $workspace = app(SportsStructureWorkspaceService::class);
        $foundation = app(SportsStructureService::class);
        $actor = User::factory()->create();
        $athlete = User::factory()->create(['nome_completo' => 'Nome Legacy']);
        DadosPessoais::query()->create([
            'user_id' => $athlete->id,
            'nome_completo' => 'Nome Canónico Estrutura',
        ]);
        $modality = SportsModality::query()->where('club_id', 'bscn')->where('code', 'swimming')->firstOrFail();

        SportsAthleteParticipation::query()->create([
            'club_id' => 'bscn',
            'user_id' => $athlete->id,
            'sports_modality_id' => $modality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => '2026-09-01',
            'source' => 'test',
        ]);

        $season = $workspace->createSeason([
            'sports_modality_id' => $modality->id,
            'nome' => 'Época Query',
            'ano_temporada' => '2026/27',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'status' => 'active',
        ], $actor->id);
        $group = $workspace->createGroup([
            'sports_modality_id' => $modality->id,
            'code' => 'query-group',
            'name' => 'Query Group',
        ], $actor->id);
        $context = $foundation->syncGroupSeason([
            'training_group_id' => $group->id,
            'season_id' => $season->id,
        ], $actor->id);
        $foundation->assignMembershipWithSeasonContext([
            'training_group_season_id' => $context->id,
            'user_id' => $athlete->id,
            'is_primary' => true,
            'starts_at' => '2026-09-01',
        ], $actor->id);

        $payload = app(SportsStructureWorkspaceQueryService::class)->payload();

        $this->assertSame('Nome Canónico Estrutura', collect($payload['athletes'])->firstWhere('id', $athlete->id)['name']);
        $this->assertSame('Nome Canónico Estrutura', collect($payload['memberships'])->firstWhere('user_id', $athlete->id)['athlete_name']);
    }
}
