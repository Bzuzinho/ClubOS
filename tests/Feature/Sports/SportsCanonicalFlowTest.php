<?php

namespace Tests\Feature\Sports;

use App\Models\Competition;
use App\Models\Prova;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingTypeConfig;
use App\Models\User;
use App\Services\Desportivo\PrepareTrainingAthletesAction;
use App\Services\Desportivo\Queries\GetCompetitionListSummary;
use App\Services\Desportivo\Queries\GetTrainingDashboardSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsDesportivoAccess;
use Tests\TestCase;

class SportsCanonicalFlowTest extends TestCase
{
    use RefreshDatabase;
    use GrantsDesportivoAccess;

    public function test_training_creation_flow(): void
    {
        $user = User::factory()->create();
        $this->grantDesportivoAccess($user);
        $trainingType = TrainingTypeConfig::create([
            'codigo' => 'tecnico',
            'nome' => 'Treino Técnico',
            'nome_en' => 'Technical Training',
            'descricao' => 'Foco técnico',
            'cor' => '#3B82F6',
            'ativo' => true,
            'ordem' => 1,
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/desportivo/trainings', [
            'data' => '2026-03-10',
            'tipo_treino' => $trainingType->nome,
            'descricao_treino' => 'Treino tecnico',
            'volume_planeado_m' => 4200,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('trainings', [
            'tipo_treino' => $trainingType->nome,
            'volume_planeado_m' => 4200,
        ]);
    }

    public function test_athlete_assignment_to_training_flow(): void
    {
        $coach = User::factory()->create();
        $athlete = User::factory()->create([
            'perfil' => 'atleta',
            'tipo_membro' => ['atleta'],
            'estado' => 'ativo',
            'ativo_desportivo' => true,
        ]);

        $training = Training::create([
            'numero_treino' => 'T-001',
            'data' => '2026-03-11',
            'tipo_treino' => 'cais',
            'descricao_treino' => 'Cais',
            'criado_por' => $coach->id,
        ]);

        app(PrepareTrainingAthletesAction::class)->execute($training, []);

        $this->assertDatabaseHas('training_athletes', [
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
        ]);
    }

    public function test_attendance_update_flow(): void
    {
        $coach = User::factory()->create();
        $this->grantDesportivoAccess($coach);
        $athlete = User::factory()->create();
        $training = Training::create([
            'numero_treino' => 'T-002',
            'data' => '2026-03-12',
            'tipo_treino' => 'agua',
            'descricao_treino' => 'Agua',
            'criado_por' => $coach->id,
        ]);

        TrainingAthlete::create([
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'estado' => 'ausente',
            'presente' => false,
        ]);

        $this->actingAs($coach)
            ->putJson("/api/desportivo/trainings/{$training->id}/attendance/{$athlete->id}", [
                'estado' => 'presente',
                'presente' => true,
                'volume_real_m' => 3900,
            ])
            ->assertOk();

        $this->assertDatabaseHas('training_athletes', [
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'estado' => 'presente',
            'presente' => 1,
            'volume_real_m' => 3900,
        ]);
    }

    public function test_training_metric_creation_flow(): void
    {
        $coach = User::factory()->create();
        $this->grantDesportivoAccess($coach);
        $athlete = User::factory()->create();
        $training = Training::create([
            'numero_treino' => 'T-003',
            'data' => '2026-03-13',
            'tipo_treino' => 'cais',
            'descricao_treino' => 'Cais',
            'criado_por' => $coach->id,
        ]);

        TrainingAthlete::create([
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'estado' => 'presente',
            'presente' => true,
        ]);

        $this->actingAs($coach)
            ->postJson('/desportivo/cais/metricas', [
                'treino_id' => $training->id,
                'user_id' => $athlete->id,
                'rows' => [
                    ['metrica' => '25m', 'valor' => '16.7', 'tempo' => '00:16.7', 'observacao' => 'ok'],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('training_metrics', [
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'metrica' => '25m',
        ]);
    }

    public function test_competition_prova_registration_result_and_team_result_flows(): void
    {
        $user = User::factory()->create();
        $this->grantDesportivoAccess($user);
        $athlete = User::factory()->create();
        $this->actingAs($user);

        $competitionResponse = $this->postJson('/api/desportivo/competitions', [
            'nome' => 'Open ClubOS',
            'data_inicio' => '2026-03-20',
            'data_fim' => '2026-03-21',
            'local' => 'Piscina Municipal',
            'tipo_prova' => 'piscina',
        ])->assertCreated();

        $competitionId = $competitionResponse->json('id');

        $provaResponse = $this->postJson('/api/provas', [
            'competition_id' => $competitionId,
            'estilo' => 'LIVRE',
            'distancia_m' => 100,
            'genero' => 'M',
            'ordem_prova' => 1,
        ])->assertCreated();

        $provaId = $provaResponse->json('id');

        $this->postJson('/api/desportivo/competition-registrations', [
            'prova_id' => $provaId,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
        ])->assertCreated();

        $this->postJson('/api/desportivo/competition-results', [
            'prova_id' => $provaId,
            'user_id' => $athlete->id,
            'tempo_oficial' => 58.40,
            'posicao' => 1,
        ])->assertCreated();

        $this->postJson('/api/desportivo/team-results', [
            'competition_id' => $competitionId,
            'equipa' => 'ClubOS A',
            'classificacao' => 1,
            'pontos' => 50,
        ])->assertCreated();

        $this->assertDatabaseHas('competition_registrations', [
            'prova_id' => $provaId,
            'user_id' => $athlete->id,
        ]);

        $this->assertDatabaseHas('results', [
            'prova_id' => $provaId,
            'user_id' => $athlete->id,
            'posicao' => 1,
        ]);

        $this->assertDatabaseHas('team_results', [
            'competicao_id' => $competitionId,
            'equipa' => 'ClubOS A',
        ]);
    }

    public function test_dashboard_summary_query(): void
    {
        $coach = User::factory()->create();
        $athlete = User::factory()->create();

        $training = Training::create([
            'numero_treino' => 'T-004',
            'data' => '2026-03-14',
            'tipo_treino' => 'aerobio',
            'descricao_treino' => 'Aerobio',
            'criado_por' => $coach->id,
        ]);

        TrainingAthlete::create([
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'estado' => 'presente',
            'presente' => true,
            'volume_real_m' => 3000,
        ]);

        $summary = app(GetTrainingDashboardSummary::class)($training->id);

        $this->assertSame(1, $summary['presentes']);
        $this->assertSame(0, $summary['ausentes']);
        $this->assertSame(3000, $summary['volume_total']);
    }

    public function test_competition_summary_query(): void
    {
        $competition = Competition::create([
            'nome' => 'Regional',
            'local' => 'Porto',
            'data_inicio' => '2026-04-01',
            'data_fim' => '2026-04-02',
            'tipo' => 'piscina',
        ]);

        $prova = Prova::create([
            'competicao_id' => $competition->id,
            'estilo' => 'COSTAS',
            'distancia_m' => 200,
            'genero' => 'F',
        ]);

        $athlete = User::factory()->create();

        \App\Models\CompetitionRegistration::create([
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
        ]);

        \App\Models\Result::create([
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'tempo_oficial' => 130.10,
        ]);

        $summary = app(GetCompetitionListSummary::class)(10);

        $this->assertGreaterThanOrEqual(1, $summary->count());
        $this->assertSame(1, (int) $summary->first()->total_provas);
        $this->assertSame(1, (int) $summary->first()->total_resultados);
        $this->assertSame(1, (int) $summary->first()->total_inscritos);
    }
}
