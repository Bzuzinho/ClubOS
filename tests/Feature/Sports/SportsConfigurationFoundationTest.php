<?php

namespace Tests\Feature\Sports;

use App\Models\AthleteStatusConfig;
use App\Models\SportsLimitationType;
use App\Models\Training;
use App\Models\TrainingTypeConfig;
use App\Models\User;
use App\Services\Desportivo\SportsConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SportsConfigurationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sports.club_id' => 'bscn']);
    }

    public function test_f1_schema_adds_tenant_lifecycle_and_behavior_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('athlete_status_configs', [
            'club_id', 'archived_at', 'created_by', 'updated_by',
            'counts_as_present', 'requires_reason', 'allows_training', 'allows_competition',
        ]));
        $this->assertTrue(Schema::hasColumns('training_type_configs', [
            'club_id', 'archived_at', 'is_recovery', 'is_high_intensity',
        ]));
        $this->assertTrue(Schema::hasColumns('training_zone_configs', [
            'club_id', 'archived_at', 'is_recovery', 'is_high_intensity',
        ]));
        $this->assertTrue(Schema::hasColumns('absence_reason_configs', ['club_id', 'archived_at', 'health_related']));
        $this->assertTrue(Schema::hasColumns('pool_type_configs', ['club_id', 'archived_at', 'is_open_water']));
        $this->assertTrue(Schema::hasColumns('prova_tipos', ['club_id', 'codigo', 'ordem', 'archived_at']));
        $this->assertTrue(Schema::hasTable('sports_limitation_types'));
    }

    public function test_status_behavior_is_data_driven_instead_of_code_driven(): void
    {
        $status = AthleteStatusConfig::query()->create([
            'club_id' => 'bscn',
            'codigo' => 'qualquer_codigo',
            'nome' => 'Participação condicionada',
            'ativo' => true,
            'ordem' => 1,
            'counts_as_present' => true,
            'requires_reason' => true,
            'allows_training' => true,
            'allows_competition' => false,
        ]);

        $this->assertTrue($status->isPresente());
        $this->assertTrue($status->requerJustificacao());
        $this->assertFalse($status->allows_competition);
    }

    public function test_configuration_creation_is_scoped_to_active_sports_club(): void
    {
        config(['sports.club_id' => 'club-secondary']);
        $actor = User::factory()->create();

        $created = app(SportsConfigurationService::class)->create('limitation_types', [
            'codigo' => 'sem_bloco',
            'nome' => 'Sem partida do bloco',
            'allows_training' => true,
            'allows_competition' => true,
            'requires_end_date' => true,
        ], $actor);

        $this->assertInstanceOf(SportsLimitationType::class, $created);
        $this->assertSame('club-secondary', $created->club_id);
        $this->assertSame($actor->id, $created->created_by);
    }

    public function test_code_becomes_immutable_after_historical_reference(): void
    {
        $actor = User::factory()->create();
        $type = TrainingTypeConfig::query()->create([
            'club_id' => 'bscn',
            'codigo' => 'tecnico',
            'nome' => 'Técnico',
            'ativo' => true,
            'ordem' => 1,
        ]);

        Training::query()->create([
            'numero_treino' => '#CFG-1',
            'data' => '2026-09-01',
            'hora_inicio' => '18:00',
            'hora_fim' => '19:00',
            'local' => 'Piscina',
            'tipo_treino' => 'tecnico',
            'club_id' => 'bscn',
            'criado_por' => $actor->id,
        ]);

        $this->expectException(ValidationException::class);

        app(SportsConfigurationService::class)->update('training_types', (string) $type->id, [
            'codigo' => 'tecnico_novo',
            'nome' => 'Técnico',
        ], $actor);
    }

    public function test_used_configuration_is_archived_instead_of_deleted(): void
    {
        $actor = User::factory()->create();
        $type = TrainingTypeConfig::query()->create([
            'club_id' => 'bscn',
            'codigo' => 'resistencia',
            'nome' => 'Resistência',
            'ativo' => true,
            'ordem' => 1,
        ]);

        Training::query()->create([
            'numero_treino' => '#CFG-2',
            'data' => '2026-09-02',
            'hora_inicio' => '18:00',
            'hora_fim' => '19:00',
            'local' => 'Piscina',
            'tipo_treino' => 'resistencia',
            'club_id' => 'bscn',
            'criado_por' => $actor->id,
        ]);

        $result = app(SportsConfigurationService::class)->remove('training_types', (string) $type->id, $actor);

        $this->assertSame('archived', $result['action']);
        $this->assertNotNull($type->fresh()->archived_at);
        $this->assertFalse($type->fresh()->ativo);
    }

    public function test_unused_configuration_can_be_physically_deleted(): void
    {
        $type = TrainingTypeConfig::query()->create([
            'club_id' => 'bscn',
            'codigo' => 'temporario',
            'nome' => 'Temporário',
            'ativo' => true,
            'ordem' => 999,
        ]);

        $result = app(SportsConfigurationService::class)->remove('training_types', (string) $type->id);

        $this->assertSame('deleted', $result['action']);
        $this->assertNull(TrainingTypeConfig::query()->find($type->id));
    }

    public function test_legacy_injury_catalog_is_exposed_read_only_and_not_used_as_operational_limitations(): void
    {
        $payload = app(SportsConfigurationService::class)->pagePayload();

        $this->assertArrayHasKey('legacy_injury_reasons', $payload);
        $this->assertArrayHasKey('limitation_types', $payload);
        $this->assertSame(0, SportsLimitationType::query()->count());
    }

    public function test_old_global_sports_configuration_url_redirects_to_sports_owned_workspace(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/configuracoes/desportivo')
            ->assertRedirect(route('desportivo.configuracao.index'));
    }
}
