<?php

namespace Tests\Feature\Sports;

use App\Models\Competition;
use App\Models\Prova;
use App\Models\Result;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsModality;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\User;
use App\Services\Desportivo\SportsAnalysisWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SportsAnalysisWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_analysis_derives_training_and_result_facts_without_legacy_performance_kv(): void
    {
        $athlete = User::factory()->create(['name' => 'Ana Analise']);
        $modality = SportsModality::query()
            ->where('club_id', 'bscn')
            ->where('code', 'swimming')
            ->firstOrFail();

        SportsAthleteParticipation::query()->create([
            'club_id' => 'bscn', 'user_id' => $athlete->id, 'sports_modality_id' => $modality->id,
            'active' => true, 'current_slot' => 'current',
            'starts_at' => now()->subYear()->toDateString(), 'source' => 'test',
        ]);

        $training = Training::query()->create([
            'club_id' => 'bscn', 'numero_treino' => 1,
            'data' => now()->subDays(3)->toDateString(), 'session_status' => 'completed',
            'tipo_treino' => 'Técnico', 'volume_planeado_m' => 3000,
        ]);
        TrainingAthlete::query()->create([
            'treino_id' => $training->id, 'user_id' => $athlete->id,
            'presente' => true, 'estado' => 'presente', 'volume_real_m' => 2800, 'rpe' => 7,
        ]);

        $competition = Competition::query()->create([
            'club_id' => 'bscn', 'nome' => 'Regional', 'local' => 'Leiria',
            'data_inicio' => now()->subDays(2)->toDateString(), 'tipo' => 'piscina', 'status' => 'completed',
        ]);
        $race = Prova::query()->create([
            'competicao_id' => $competition->id, 'estilo' => 'LIVRE',
            'distancia_m' => 100, 'genero' => 'F', 'ordem_prova' => 1,
        ]);
        Result::query()->create([
            'prova_id' => $race->id, 'user_id' => $athlete->id,
            'tempo_oficial' => 61.42, 'posicao' => 2, 'status' => 'ok',
        ]);

        $payload = app(SportsAnalysisWorkspaceService::class)->athlete($athlete, 12);

        $this->assertSame(100.0, $payload['kpis']['attendance_rate']);
        $this->assertSame(2800, $payload['kpis']['volume_m']);
        $this->assertSame(7.0, $payload['kpis']['avg_rpe']);
        $this->assertNull($payload['kpis']['evaluation_average']);
        $this->assertSame(1, $payload['kpis']['podiums']);
        $this->assertSame('Regional', $payload['results'][0]['competition']);
        $this->assertStringContainsString('Não', $payload['disclaimer']);
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
            'club_id' => 'bscn', 'user_id' => $local->id, 'sports_modality_id' => $localModality->id,
            'active' => true, 'current_slot' => 'current', 'starts_at' => now()->subYear(), 'source' => 'test',
        ]);
        SportsAthleteParticipation::query()->create([
            'club_id' => 'other-club', 'user_id' => $foreign->id, 'sports_modality_id' => $foreignModality->id,
            'active' => true, 'current_slot' => 'current', 'starts_at' => now()->subYear(), 'source' => 'test',
        ]);

        $payload = app(SportsAnalysisWorkspaceService::class)->workspace();

        $this->assertSame([(string)$local->id], collect($payload['athletes'])->pluck('id')->all());
        $this->assertTrue($payload['principles']['read_only']);
        $this->assertFalse($payload['principles']['legacy_performance_kv_active']);
    }
}
