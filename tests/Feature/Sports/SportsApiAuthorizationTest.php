<?php

namespace Tests\Feature\Sports;

use App\Models\TrainingTypeConfig;
use App\Models\User;
use App\Models\UserType;
use App\Models\UserTypeMenuModule;
use App\Models\UserTypePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsDesportivoAccess;
use Tests\TestCase;

class SportsApiAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use GrantsDesportivoAccess;

    public function test_authenticated_user_without_desportivo_module_cannot_access_sports_api(): void
    {
        $user = User::factory()->create();
        $userType = UserType::query()->create([
            'codigo' => 'sem_desportivo',
            'nome' => 'Sem Desportivo',
            'ativo' => true,
            'menu_visibility_configured' => true,
        ]);

        $user->userTypes()->attach($userType->id);
        UserTypeMenuModule::query()->create([
            'user_type_id' => $userType->id,
            'module_key' => 'membros',
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->getJson('/api/desportivo/trainings')
            ->assertForbidden();
    }

    public function test_user_with_view_only_training_permission_cannot_create_training(): void
    {
        $user = User::factory()->create();
        $userType = UserType::query()->create([
            'codigo' => 'treinos_consulta',
            'nome' => 'Treinos Consulta',
            'ativo' => true,
            'menu_visibility_configured' => true,
        ]);
        $user->userTypes()->attach($userType->id);

        UserTypeMenuModule::query()->create([
            'user_type_id' => $userType->id,
            'module_key' => 'desportivo',
            'sort_order' => 1,
        ]);

        $permissionNode = $this->desportivoPermissionNode('desportivo.treinos', 0);

        UserTypePermission::query()->create([
            'user_type_id' => $userType->id,
            'permission_node_id' => $permissionNode->id,
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'modulo' => 'desportivo',
            'submodulo' => 'treinos',
            'pode_ver' => true,
            'pode_criar' => false,
            'pode_editar' => false,
            'pode_eliminar' => false,
        ]);

        $trainingType = $this->createTrainingType();

        $this->actingAs($user)
            ->getJson('/api/desportivo/trainings')
            ->assertOk();

        $this->actingAs($user)
            ->postJson('/api/desportivo/trainings', [
                'numero_treino' => 'API-ACL-001',
                'tipo_treino' => $trainingType->nome,
                'descricao_treino' => 'Não deve ser criado',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('trainings', [
            'numero_treino' => 'API-ACL-001',
        ]);
    }

    public function test_api_training_creation_uses_canonical_action_with_athletes_and_series(): void
    {
        $coach = User::factory()->create();
        $this->grantDesportivoAccess($coach);
        $athlete = User::factory()->create([
            'perfil' => 'atleta',
            'tipo_membro' => ['atleta'],
            'estado' => 'ativo',
            'ativo_desportivo' => true,
        ]);
        $trainingType = $this->createTrainingType();

        $response = $this->actingAs($coach)
            ->postJson('/api/desportivo/trainings', [
                'numero_treino' => 'API-CANON-001',
                'tipo_treino' => $trainingType->nome,
                'descricao_treino' => 'Treino criado pelo fluxo canónico',
                'volume_planeado_m' => 400,
                'series_linhas' => [
                    [
                        'repeticoes' => 4,
                        'exercicio' => '100 m Livres',
                        'metros' => 100,
                    ],
                ],
            ])
            ->assertCreated();

        $trainingId = $response->json('id');

        $this->assertNotEmpty($trainingId);
        $this->assertDatabaseHas('training_athletes', [
            'treino_id' => $trainingId,
            'user_id' => $athlete->id,
        ]);
        $this->assertDatabaseHas('training_series', [
            'treino_id' => $trainingId,
            'repeticoes' => 4,
            'distancia_total_m' => 400,
        ]);
        $this->assertDatabaseHas('trainings', [
            'id' => $trainingId,
            'criado_por' => $coach->id,
        ]);
    }

    private function createTrainingType(): TrainingTypeConfig
    {
        return TrainingTypeConfig::query()->create([
            'codigo' => 'tecnico_api',
            'nome' => 'Treino Técnico API',
            'nome_en' => 'API Technical Training',
            'descricao' => 'Tipo de treino para testes de API',
            'cor' => '#3B82F6',
            'ativo' => true,
            'ordem' => 1,
        ]);
    }
}
