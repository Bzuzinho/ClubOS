<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Http\Controllers\Api\CompetitionResultController;
use App\Http\Controllers\Api\TeamResultController;
use App\Models\Competition;
use App\Models\CompetitionRegistration;
use App\Models\Prova;
use App\Models\Result;
use App\Models\TeamResult;
use App\Models\User;
use App\Services\Desportivo\CreateCompetitionRegistrationAction;
use App\Services\Desportivo\SportsCompetitionWorkspaceService;
use App\Services\Desportivo\SportsResultsWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CompetitionToResultsContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_registration_becomes_result_on_the_same_canonical_race_and_athlete_pair(): void
    {
        $athlete = User::factory()->create(['name' => 'Atleta H3e']);
        $competition = $this->competition('bscn', 'Regional H3e');
        $race = $this->race($competition, 100, 'LIVRE');
        $registration = CompetitionRegistration::query()->create([
            'prova_id' => $race->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 5,
        ]);

        $before = app(SportsResultsWorkspaceService::class)->detail($competition);
        $this->assertSame((string) $race->id, $before['expected_rows'][0]['prova_id']);
        $this->assertSame((string) $athlete->id, $before['expected_rows'][0]['user_id']);
        $this->assertNull($before['expected_rows'][0]['result']);
        $this->assertSame(1, $before['stats']['pending']);

        $first = app(SportsResultsWorkspaceService::class)->saveBulk($competition, [[
            'prova_id' => (string) $race->id,
            'user_id' => (string) $athlete->id,
            'tempo_oficial' => 61.42,
            'posicao' => 2,
            'pontos_fina' => 624,
            'status' => 'ok',
        ]]);
        $second = app(SportsResultsWorkspaceService::class)->saveBulk($competition, [[
            'prova_id' => (string) $race->id,
            'user_id' => (string) $athlete->id,
            'tempo_oficial' => 60.98,
            'posicao' => 1,
            'pontos_fina' => 640,
            'status' => 'ok',
        ]]);

        $this->assertSame($first, $second);
        $this->assertSame(1, Result::query()->where('prova_id', $race->id)->where('user_id', $athlete->id)->count());

        $result = Result::query()->where('prova_id', $race->id)->where('user_id', $athlete->id)->firstOrFail();
        $this->assertSame((string) $race->id, (string) $result->prova_id);
        $this->assertSame((string) $registration->user_id, (string) $result->user_id);
        $this->assertSame(60.98, (float) $result->tempo_oficial);

        $after = app(SportsResultsWorkspaceService::class)->detail($competition);
        $this->assertSame(0, $after['stats']['pending']);
        $this->assertSame(1, $after['stats']['results']);
        $this->assertSame(60.98, $after['expected_rows'][0]['result']['official_time']);

        $competitionDetail = app(SportsCompetitionWorkspaceService::class)->detail($competition);
        $this->assertSame((string) $registration->id, $competitionDetail['registrations'][0]['id']);
        $this->assertSame((string) $result->id, $competitionDetail['results'][0]['id']);
    }

    public function test_registration_write_rejects_a_race_owned_by_another_club(): void
    {
        $athlete = User::factory()->create();
        $foreignCompetition = $this->competition('other-club', 'Foreign');
        $foreignRace = $this->race($foreignCompetition, 50, 'COSTAS');

        $this->expectException(ValidationException::class);

        try {
            app(CreateCompetitionRegistrationAction::class)->execute([
                'prova_id' => (string) $foreignRace->id,
                'user_id' => (string) $athlete->id,
                'estado' => 'inscrito',
            ]);
        } finally {
            $this->assertSame(0, CompetitionRegistration::query()->where('user_id', $athlete->id)->count());
        }
    }

    public function test_result_and_team_result_indexes_do_not_leak_other_clubs(): void
    {
        $athlete = User::factory()->create();
        $localCompetition = $this->competition('bscn', 'Local');
        $foreignCompetition = $this->competition('other-club', 'Foreign');
        $localRace = $this->race($localCompetition, 100, 'LIVRE');
        $foreignRace = $this->race($foreignCompetition, 100, 'LIVRE');

        Result::query()->create([
            'prova_id' => $localRace->id,
            'user_id' => $athlete->id,
            'tempo_oficial' => 60,
            'status' => 'ok',
        ]);
        Result::query()->create([
            'prova_id' => $foreignRace->id,
            'user_id' => $athlete->id,
            'tempo_oficial' => 59,
            'status' => 'ok',
        ]);
        TeamResult::query()->create([
            'competicao_id' => $localCompetition->id,
            'equipa' => 'BSCN',
            'classificacao' => 1,
        ]);
        TeamResult::query()->create([
            'competicao_id' => $foreignCompetition->id,
            'equipa' => 'Outro Clube',
            'classificacao' => 2,
        ]);

        $resultResponse = app(CompetitionResultController::class)->index(Request::create('/api/desportivo/competition-results', 'GET'));
        $resultRows = $resultResponse->getData(true);
        $this->assertCount(1, $resultRows);
        $this->assertSame((string) $localCompetition->id, (string) $resultRows[0]['competition_id']);

        $teamResponse = app(TeamResultController::class)->index();
        $teamRows = $teamResponse->getData(true);
        $this->assertCount(1, $teamRows);
        $this->assertSame((string) $localCompetition->id, (string) $teamRows[0]['competicao_id']);
    }

    private function competition(string $clubId, string $name): Competition
    {
        return Competition::query()->create([
            'club_id' => $clubId,
            'nome' => $name,
            'local' => 'Leiria',
            'data_inicio' => '2026-10-10',
            'tipo' => 'piscina',
            'status' => 'completed',
        ]);
    }

    private function race(Competition $competition, int $distance, string $stroke): Prova
    {
        return Prova::query()->create([
            'competicao_id' => $competition->id,
            'estilo' => $stroke,
            'distancia_m' => $distance,
            'genero' => 'M',
            'ordem_prova' => 1,
        ]);
    }
}
