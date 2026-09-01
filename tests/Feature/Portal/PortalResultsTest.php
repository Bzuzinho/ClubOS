<?php

namespace Tests\Feature\Portal;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_athlete_can_view_only_own_results_in_portal(): void
    {
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Atleta Resultados',
        ]);
        $otherAthlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Outro Atleta',
        ]);

        $firstCompetition = $this->createCompetition([
            'nome' => 'Regional de Infantis',
            'local' => 'Caldas da Rainha',
            'data_inicio' => now()->subMonths(2)->toDateString(),
        ]);
        $secondCompetition = $this->createCompetition([
            'nome' => 'Torneio de Primavera',
            'local' => 'Leiria',
            'data_inicio' => now()->subWeeks(2)->toDateString(),
        ]);

        $firstProva = $this->createProva($firstCompetition, [
            'estilo' => 'Livres',
            'distancia_m' => 100,
            'genero' => 'F',
        ]);
        $secondProva = $this->createProva($secondCompetition, [
            'estilo' => 'Livres',
            'distancia_m' => 100,
            'genero' => 'F',
        ]);
        $otherProva = $this->createProva($secondCompetition, [
            'estilo' => 'Costas',
            'distancia_m' => 200,
            'genero' => 'F',
        ]);

        $this->createResult($athlete->id, $firstProva, [
            'tempo_oficial' => 74.80,
            'posicao' => 4,
        ]);
        $this->createResult($athlete->id, $secondProva, [
            'tempo_oficial' => 72.34,
            'posicao' => 2,
            'observacoes' => 'Novo recorde pessoal.',
        ]);
        $this->createResult($otherAthlete->id, $otherProva, [
            'tempo_oficial' => 140.10,
            'posicao' => 1,
        ]);

        $response = $this->inertiaGetAs($athlete, route('portal.results'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Results');
        $response->assertJsonPath('props.is_athlete', true);
        $response->assertJsonPath('props.hero.last_result.prova', '100m Livres');
        $response->assertJsonPath('props.hero.last_result.time', '01:12.34');
        $response->assertJsonPath('props.kpis.events_this_season', 2);
        $response->assertJsonPath('props.kpis.latest_time', '01:12.34');
        $response->assertJsonCount(2, 'props.latest_results');
        $response->assertJsonPath('props.latest_results.0.event', 'Torneio de Primavera');
        $response->assertJsonPath('props.latest_results.0.badges.0.label', 'Recorde pessoal');
        $response->assertJsonPath('props.evolution.has_data', true);
        $response->assertJsonPath('props.evolution.prova', '100m Livres');
        $response->assertJsonPath('props.evolution.improvement_label', 'Melhorou 2.46 segundos desde o início da época.');
        $response->assertJsonMissing(['event' => 'Outro Atleta']);
        $response->assertJsonMissing(['event' => '200m Costas']);
    }

    public function test_athlete_without_results_gets_empty_portal_state(): void
    {
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $response = $this->inertiaGetAs($athlete, route('portal.results'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Results');
        $response->assertJsonPath('props.is_athlete', true);
        $response->assertJsonPath('props.hero.last_result', null);
        $response->assertJsonCount(0, 'props.latest_results');
        $response->assertJsonCount(0, 'props.best_times');
        $response->assertJsonPath('props.evolution.has_data', false);
    }

    public function test_non_athlete_sees_empty_results_state(): void
    {
        $user = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['socio'],
        ]);

        $response = $this->inertiaGetAs($user, route('portal.results'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Results');
        $response->assertJsonPath('props.is_athlete', false);
        $response->assertJsonPath('props.hero.last_result', null);
        $response->assertJsonCount(0, 'props.latest_results');
        $response->assertJsonPath('props.evolution.has_data', false);
    }

    private function inertiaGetAs(User $user, string $uri)
    {
        $inertiaVersion = app(HandleInertiaRequests::class)->version(request());

        return $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) $inertiaVersion,
        ])->get($uri);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCompetition(array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('competitions')->insert(array_merge([
            'id' => $id,
            'nome' => 'Competição Portal',
            'local' => 'Piscina Municipal',
            'data_inicio' => now()->toDateString(),
            'data_fim' => now()->toDateString(),
            'tipo' => 'natacao',
            'evento_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProva(string $competitionId, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('provas')->insert(array_merge([
            'id' => $id,
            'competicao_id' => $competitionId,
            'estilo' => 'Livres',
            'distancia_m' => 100,
            'genero' => 'F',
            'escalao_id' => null,
            'ordem_prova' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createResult(string $userId, string $provaId, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('results')->insert(array_merge([
            'id' => $id,
            'prova_id' => $provaId,
            'user_id' => $userId,
            'tempo_oficial' => 75.00,
            'posicao' => 5,
            'pontos_fina' => null,
            'desclassificado' => false,
            'observacoes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }
}
