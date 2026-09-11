<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\{Competition, Prova, Result, ResultSplit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsDesportivoAccess;
use Tests\TestCase;

final class AthleteCompetitionHistoryTest extends TestCase
{
    use RefreshDatabase;
    use GrantsDesportivoAccess;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
        $actor = User::factory()->create(['perfil' => 'user', 'tipo_membro' => ['Socio']]);
        $this->grantDesportivoAccess($actor);
        $this->actingAs($actor);
    }

    private function result(User $athlete, string $club = 'bscn', string $date = '2025-01-01'): Result
    {
        $competition = Competition::query()->create([
            'club_id' => $club, 'nome' => 'Campeonato', 'local' => 'Leiria',
            'data_inicio' => $date, 'tipo' => 'piscina', 'status' => 'completed',
        ]);
        $race = Prova::query()->create([
            'competicao_id' => $competition->id, 'estilo' => 'LIVRE', 'distancia_m' => 100, 'genero' => 'M',
        ]);
        return Result::query()->create([
            'prova_id' => $race->id, 'user_id' => $athlete->id, 'tempo_oficial' => 61.42,
            'posicao' => 2, 'pontos_fina' => 450, 'status' => 'ok',
        ]);
    }

    public function test_individual_results_include_official_facts_and_sorted_splits_after_age_group_removal(): void
    {
        $athlete = User::factory()->create();
        $result = $this->result($athlete);
        foreach ([100 => 61.42, 50 => 29.72] as $distance => $time) {
            ResultSplit::query()->create(['resultado_id' => $result->id, 'distancia_parcial_m' => $distance, 'tempo_parcial' => $time]);
        }
        $athlete->update(['escalao' => []]);
        $this->getJson('/api/desportivo/competition-results?athlete_id='.$athlete->id)
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $result->id)
            ->assertJsonPath('data.0.competition_date', '2025-01-01')
            ->assertJsonPath('data.0.tempo_oficial', '61.42')
            ->assertJsonPath('data.0.posicao', 2)
            ->assertJsonPath('data.0.pontos_fina', 450)
            ->assertJsonPath('data.0.splits.0.distance_m', 50);
        $result->update(['status' => 'dns', 'tempo_oficial' => null]);
        $this->getJson('/api/desportivo/competition-results?athlete_id='.$athlete->id)
            ->assertJsonPath('data.0.status', 'dns')->assertJsonPath('data.0.tempo_oficial', null);
        $this->assertDatabaseCount('results', 1);
    }

    public function test_pagination_is_individual_club_scoped_and_ordered_by_competition_date(): void
    {
        $athlete = User::factory()->create();
        for ($i = 0; $i < 25; $i++) $this->result($athlete);
        $latest = $this->result($athlete, 'bscn', '2026-01-01');
        $this->result($athlete, 'other-club', '2027-01-01');
        $this->result(User::factory()->create(), 'bscn', '2027-01-01');
        $first = $this->getJson('/api/desportivo/competition-results?athlete_id='.$athlete->id)
            ->assertOk()->assertJsonPath('total', 26)->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0.id', $latest->id);
        $second = $this->getJson('/api/desportivo/competition-results?athlete_id='.$athlete->id.'&page=2')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotContains($second->json('data.0.id'), array_column($first->json('data'), 'id'));
        // Existing consumers retain the unpaginated array contract.
        $this->getJson('/api/desportivo/competition-results')->assertOk()->assertJsonCount(27);
    }

    public function test_history_validates_input_and_preserves_results_permission(): void
    {
        $this->getJson('/api/desportivo/competition-results?athlete_id=invalid')->assertUnprocessable();
        $athlete = User::factory()->create(['perfil' => 'user', 'tipo_membro' => ['Socio']]);
        $this->getJson('/api/desportivo/competition-results?athlete_id='.$athlete->id.'&page=0')->assertUnprocessable();
        $this->grantDesportivoAccess($athlete, ['desportivo.treinos']);
        $this->actingAs($athlete)->getJson('/api/desportivo/competition-results?athlete_id='.$athlete->id)->assertForbidden();
    }
}
