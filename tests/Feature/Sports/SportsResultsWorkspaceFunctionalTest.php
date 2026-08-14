<?php

namespace Tests\Feature\Sports;

use App\Models\Competition;
use App\Models\CompetitionRegistration;
use App\Models\Prova;
use App\Models\Result;
use App\Models\User;
use App\Services\Desportivo\SportsResultsWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SportsResultsWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_counts_expected_results_only_from_canonical_registrations_and_is_club_scoped(): void
    {
        $athlete = User::factory()->create(['name' => 'Nadador']);
        $local = Competition::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'nome' => 'Regional',
            'local' => 'Leiria',
            'data_inicio' => '2026-09-20',
            'tipo' => 'piscina',
            'status' => 'completed',
        ]);
        Competition::query()->create([
            'club_id' => 'other-club',
            'nome' => 'Outra',
            'local' => 'Porto',
            'data_inicio' => '2026-09-20',
            'tipo' => 'piscina',
            'status' => 'completed',
        ]);
        $race = Prova::query()->create([
            'competicao_id' => $local->id,
            'estilo' => 'LIVRE',
            'distancia_m' => 100,
            'genero' => 'M',
            'ordem_prova' => 1,
        ]);
        CompetitionRegistration::query()->create([
            'prova_id' => $race->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 4,
        ]);

        $payload = app(SportsResultsWorkspaceService::class)->workspace();

        $this->assertCount(1, $payload['competitions']);
        $this->assertSame('Regional', $payload['competitions'][0]['name']);
        $this->assertSame(1, $payload['competitions'][0]['expected_count']);
        $this->assertSame(1, $payload['competitions'][0]['pending_count']);
    }

    public function test_bulk_save_requires_existing_registration_and_never_creates_program_entities(): void
    {
        $registered = User::factory()->create();
        $unregistered = User::factory()->create();
        $competition = Competition::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'nome' => 'Open',
            'local' => 'Coimbra',
            'data_inicio' => '2026-10-10',
            'tipo' => 'piscina',
            'status' => 'completed',
        ]);
        $race = Prova::query()->create([
            'competicao_id' => $competition->id,
            'estilo' => 'COSTAS',
            'distancia_m' => 200,
            'genero' => 'M',
            'ordem_prova' => 1,
        ]);
        CompetitionRegistration::query()->create([
            'prova_id' => $race->id,
            'user_id' => $registered->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 4,
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(SportsResultsWorkspaceService::class)->saveBulk($competition, [[
                'prova_id' => $race->id,
                'user_id' => $unregistered->id,
                'tempo_oficial' => 130.12,
                'status' => 'ok',
            ]]);
        } finally {
            $this->assertSame(1, Prova::query()->where('competicao_id', $competition->id)->count());
            $this->assertSame(0, Result::query()->count());
        }
    }

    public function test_bulk_save_persists_status_and_splits_for_selected_canonical_race(): void
    {
        $athlete = User::factory()->create(['name' => 'Atleta']);
        $competition = Competition::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'nome' => 'Meeting',
            'local' => 'Leiria',
            'data_inicio' => '2026-11-01',
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
        CompetitionRegistration::query()->create([
            'prova_id' => $race->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 4,
        ]);

        app(SportsResultsWorkspaceService::class)->saveBulk($competition, [[
            'prova_id' => $race->id,
            'user_id' => $athlete->id,
            'tempo_oficial' => 61.42,
            'posicao' => 2,
            'pontos_fina' => 624,
            'status' => 'ok',
            'splits' => [
                ['distance_m' => 50, 'time' => 29.72],
                ['distance_m' => 100, 'time' => 61.42],
            ],
        ]]);

        $result = Result::query()->with('splits')->firstOrFail();
        $this->assertSame('ok', $result->status);
        $this->assertSame(61.42, (float) $result->tempo_oficial);
        $this->assertSame(2, $result->posicao);
        $this->assertCount(2, $result->splits);
        $this->assertSame([50, 100], $result->splits->sortBy('distancia_parcial_m')->pluck('distancia_parcial_m')->values()->all());

        $detail = app(SportsResultsWorkspaceService::class)->detail($competition);
        $this->assertSame(0, $detail['stats']['pending']);
        $this->assertSame(1, $detail['stats']['results']);
        $this->assertSame(61.42, $detail['expected_rows'][0]['result']['official_time']);
    }
}
