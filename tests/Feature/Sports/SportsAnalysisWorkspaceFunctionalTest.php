<?php

namespace Tests\Feature\Sports;

use App\Models\Competition;
use App\Models\Prova;
use App\Models\Result;
use App\Models\ResultSplit;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsModality;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingAthleteCaisMetric;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMembership;
use App\Models\User;
use App\Services\Desportivo\SportsAnalysisWorkspaceService;
use App\Support\LegacySportsGuard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SportsAnalysisWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_analysis_derives_training_cais_splits_and_result_facts_without_legacy_performance_kv(): void
    {
        $athlete = User::factory()->create(['name' => 'Ana Analise']);
        $modality = SportsModality::query()
            ->where('club_id', 'bscn')
            ->where('code', 'swimming')
            ->firstOrFail();

        SportsAthleteParticipation::query()->create([
            'club_id' => 'bscn',
            'user_id' => $athlete->id,
            'sports_modality_id' => $modality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => now()->subYear()->toDateString(),
            'source' => 'test',
        ]);

        $training = Training::query()->create([
            'club_id' => 'bscn',
            'numero_treino' => 1,
            'data' => now()->subDays(3)->toDateString(),
            'session_status' => 'completed',
            'tipo_treino' => 'Técnico',
            'volume_planeado_m' => 3000,
        ]);
        TrainingAthlete::query()->create([
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'presente' => true,
            'estado' => 'presente',
            'volume_real_m' => 2800,
            'rpe' => 7,
        ]);
        TrainingAthleteCaisMetric::query()->create([
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'ordem' => 1,
            'metrica' => 'Frequência cardíaca',
            'valor' => '152',
        ]);

        $competition = Competition::query()->create([
            'club_id' => 'bscn',
            'nome' => 'Regional',
            'local' => 'Leiria',
            'data_inicio' => now()->subDays(2)->toDateString(),
            'tipo' => 'piscina',
            'status' => 'completed',
        ]);
        $race = Prova::query()->create([
            'competicao_id' => $competition->id,
            'estilo' => 'LIVRE',
            'distancia_m' => 100,
            'genero' => 'F',
            'ordem_prova' => 1,
        ]);
        $result = Result::query()->create([
            'prova_id' => $race->id,
            'user_id' => $athlete->id,
            'tempo_oficial' => 61.42,
            'posicao' => 2,
            'status' => 'ok',
        ]);
        ResultSplit::query()->create([
            'resultado_id' => $result->id,
            'distancia_parcial_m' => 50,
            'tempo_parcial' => 29.72,
        ]);

        $writeQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$writeQueries): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
                $writeQueries[] = $query->sql;
            }
        });

        $payload = app(SportsAnalysisWorkspaceService::class)->athlete($athlete, 12);

        $this->assertSame([], $writeQueries, 'Analysis must not write while building its reporting read model.');
        $this->assertSame(100.0, $payload['kpis']['attendance_rate']);
        $this->assertSame(2800, $payload['kpis']['volume_m']);
        $this->assertSame(7.0, $payload['kpis']['avg_rpe']);
        $this->assertNull($payload['kpis']['evaluation_average']);
        $this->assertSame(1, $payload['kpis']['podiums']);
        $this->assertSame('Regional', $payload['results'][0]['competition']);
        $this->assertSame(29.72, $payload['results'][0]['splits'][0]['time']);
        $this->assertSame(1, $payload['coverage']['cais_metrics']);
        $this->assertSame('152', $payload['training']['cais_metrics'][0]['latest']);
        $this->assertSame(152.0, $payload['training']['cais_metrics'][0]['average']);
        $this->assertStringContainsString('Não', $payload['disclaimer']);
    }

    public function test_group_analysis_aggregates_active_athletes_without_replaying_full_athlete_workspace(): void
    {
        $first = User::factory()->create(['name' => 'Atleta Um']);
        $second = User::factory()->create(['name' => 'Atleta Dois']);
        $modality = SportsModality::query()
            ->where('club_id', 'bscn')
            ->where('code', 'swimming')
            ->firstOrFail();

        foreach ([$first, $second] as $athlete) {
            SportsAthleteParticipation::query()->create([
                'club_id' => 'bscn',
                'user_id' => $athlete->id,
                'sports_modality_id' => $modality->id,
                'active' => true,
                'current_slot' => 'current',
                'starts_at' => now()->subYear()->toDateString(),
                'source' => 'test',
            ]);
        }

        $group = TrainingGroup::query()->create([
            'club_id' => 'bscn',
            'code' => 'analysis-test',
            'name' => 'Grupo Análise',
            'modality' => 'swimming',
            'sports_modality_id' => $modality->id,
            'active' => true,
        ]);

        foreach ([$first, $second] as $athlete) {
            TrainingGroupMembership::query()->create([
                'club_id' => 'bscn',
                'training_group_id' => $group->id,
                'user_id' => $athlete->id,
                'is_primary' => true,
                'starts_at' => now()->subMonth()->toDateString(),
            ]);
        }

        $training = Training::query()->create([
            'club_id' => 'bscn',
            'numero_treino' => 2,
            'data' => now()->subDay()->toDateString(),
            'session_status' => 'completed',
            'tipo_treino' => 'Aeróbio',
            'volume_planeado_m' => 2500,
        ]);
        TrainingAthlete::query()->create([
            'treino_id' => $training->id,
            'user_id' => $first->id,
            'presente' => true,
            'estado' => 'presente',
            'volume_real_m' => 2000,
            'rpe' => 5,
        ]);
        TrainingAthlete::query()->create([
            'treino_id' => $training->id,
            'user_id' => $second->id,
            'presente' => false,
            'estado' => 'ausente',
            'volume_real_m' => 0,
            'rpe' => null,
        ]);

        $payload = app(SportsAnalysisWorkspaceService::class)->group($group, 4);

        $this->assertSame(2, $payload['athlete_count']);
        $this->assertSame(50.0, $payload['attendance_average']);
        $this->assertSame(2000, $payload['volume_total_m']);
        $this->assertSame(5.0, $payload['rpe_average']);
        $this->assertCount(2, $payload['athletes']);
    }

    public function test_workspace_is_club_scoped_and_declares_read_only_analysis(): void
    {
        $local = User::factory()->create(['name' => 'Local']);
        $foreign = User::factory()->create(['name' => 'Foreign']);
        $localModality = SportsModality::query()
            ->where('club_id', 'bscn')
            ->where('code', 'swimming')
            ->firstOrFail();
        $foreignModality = SportsModality::query()->create([
            'club_id' => 'other-club',
            'code' => 'swimming-other-test',
            'name' => 'Natação',
            'active' => true,
        ]);

        SportsAthleteParticipation::query()->create([
            'club_id' => 'bscn',
            'user_id' => $local->id,
            'sports_modality_id' => $localModality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => now()->subYear(),
            'source' => 'test',
        ]);
        SportsAthleteParticipation::query()->create([
            'club_id' => 'other-club',
            'user_id' => $foreign->id,
            'sports_modality_id' => $foreignModality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => now()->subYear(),
            'source' => 'test',
        ]);

        $payload = app(SportsAnalysisWorkspaceService::class)->workspace();
        $routeUris = collect(Route::getRoutes())
            ->map(fn ($route) => $route->uri())
            ->all();

        $this->assertSame([(string) $local->id], collect($payload['athletes'])->pluck('id')->all());
        $this->assertTrue($payload['principles']['read_only']);
        $this->assertFalse($payload['principles']['legacy_performance_kv_active']);
        $this->assertContains('cais_metrics', collect($payload['indicators'])->pluck('code')->all());
        $this->assertTrue(Route::has('desportivo.analise.athlete.export'));
        $this->assertNotContains('api/desportivo/performance', $routeUris);
        $this->assertNotContains('api/desportivo/performance-metrics', $routeUris);
        app(LegacySportsGuard::class)->assertServiceSourceIsLegacyFree(SportsAnalysisWorkspaceService::class);
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/PerformanceController.php'));
        $this->assertFileDoesNotExist(resource_path('js/Components/Desportivo/DesportivoPerformanceTab.tsx'));
        $this->assertFileDoesNotExist(resource_path('js/hooks/sports/usePerformance.ts'));
        $this->assertFileDoesNotExist(resource_path('js/services/sports/performanceService.ts'));
        $this->assertFileDoesNotExist(resource_path('js/data/sportsMock.ts'));
    }
}
