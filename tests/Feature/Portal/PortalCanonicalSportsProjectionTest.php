<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AgeGroup;
use App\Models\Competition;
use App\Models\Prova;
use App\Models\Result;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PortalCanonicalSportsProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_opening_training_portal_projects_future_training_without_creating_attendance(): void
    {
        [$athlete, $training] = $this->eligibleFutureTraining('bscn');

        $this->assertDatabaseCount('training_athletes', 0);

        $response = $this->inertiaGetAs($athlete, route('portal.trainings'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Trainings');
        $response->assertJsonPath('props.upcoming_trainings.0.id', (string) $training->id);
        $response->assertJsonPath('props.upcoming_trainings.0.training_id', (string) $training->id);
        $response->assertJsonPath('props.upcoming_trainings.0.status.key', 'pending');
        $this->assertDatabaseCount('training_athletes', 0);
    }

    public function test_explicit_presence_confirmation_materializes_one_canonical_attendance_row(): void
    {
        [$athlete, $training] = $this->eligibleFutureTraining('bscn');

        $this->actingAs($athlete)
            ->patch(route('portal.trainings.update', $training->id), [
                'action' => 'confirm_presence',
            ])
            ->assertRedirect(route('portal.trainings'));

        $this->assertDatabaseCount('training_athletes', 1);
        $this->assertDatabaseHas('training_athletes', [
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'estado' => 'presente',
            'presente' => true,
        ]);

        $recordId = (string) $athlete->trainingAthletes()->where('treino_id', $training->id)->firstOrFail()->id;
        $response = $this->inertiaGetAs($athlete, route('portal.trainings'));
        $response->assertJsonPath('props.upcoming_trainings.0.id', $recordId);
        $this->assertDatabaseCount('training_athletes', 1);
    }

    public function test_portal_does_not_project_or_materialize_training_from_another_club(): void
    {
        [$athlete, $foreignTraining] = $this->eligibleFutureTraining('other-club');

        $response = $this->inertiaGetAs($athlete, route('portal.trainings'));

        $response->assertOk();
        $response->assertJsonCount(0, 'props.upcoming_trainings');
        $this->assertDatabaseCount('training_athletes', 0);

        $this->actingAs($athlete)
            ->patch(route('portal.trainings.update', $foreignTraining->id), [
                'action' => 'confirm_presence',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('training_athletes', 0);
    }

    public function test_results_portal_projects_only_canonical_results_from_current_club(): void
    {
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);

        $localCompetition = $this->competition('bscn', 'Regional BSCN');
        $foreignCompetition = $this->competition('other-club', 'Regional Externo');
        $localRace = $this->race($localCompetition, 100, 'Livres');
        $foreignRace = $this->race($foreignCompetition, 200, 'Costas');

        Result::query()->create([
            'prova_id' => $localRace->id,
            'user_id' => $athlete->id,
            'tempo_oficial' => 62.30,
            'posicao' => 2,
            'status' => 'ok',
        ]);
        Result::query()->create([
            'prova_id' => $foreignRace->id,
            'user_id' => $athlete->id,
            'tempo_oficial' => 130.20,
            'posicao' => 1,
            'status' => 'ok',
        ]);

        $response = $this->inertiaGetAs($athlete, route('portal.results'));

        $response->assertOk();
        $response->assertJsonCount(1, 'props.latest_results');
        $response->assertJsonPath('props.latest_results.0.event', 'Regional BSCN');
        $response->assertJsonMissing(['event' => 'Regional Externo']);
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

    /** @return array{0:User,1:Training} */
    private function eligibleFutureTraining(string $clubId): array
    {
        $ageGroup = AgeGroup::query()->create([
            'club_id' => 'bscn',
            'code' => 'H3F-'.strtoupper(substr(md5($clubId.microtime()), 0, 6)),
            'nome' => 'Grupo H3f',
            'ativo' => true,
        ]);
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'escalao' => [(string) $ageGroup->id],
            'ativo_desportivo' => true,
        ]);
        $training = Training::query()->create([
            'numero_treino' => '#H3F01',
            'data' => now()->addDay()->toDateString(),
            'hora_inicio' => '18:00',
            'hora_fim' => '19:30',
            'tipo_treino' => 'Técnico',
            'club_id' => $clubId,
            'session_status' => 'published',
            'criado_por' => $athlete->id,
        ]);
        $training->syncAgeGroupsWithPivot([(string) $ageGroup->id]);

        return [$athlete, $training];
    }

    private function competition(string $clubId, string $name): Competition
    {
        return Competition::query()->create([
            'club_id' => $clubId,
            'nome' => $name,
            'local' => 'Leiria',
            'data_inicio' => now()->subDay()->toDateString(),
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
