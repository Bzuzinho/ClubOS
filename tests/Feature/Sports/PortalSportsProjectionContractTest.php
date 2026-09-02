<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AgeGroup;
use App\Models\Competition;
use App\Models\CompetitionEventProjection;
use App\Models\Event;
use App\Models\Prova;
use App\Models\Result;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PortalSportsProjectionContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_training_page_is_a_pure_projection_and_does_not_prepare_athletes_on_get(): void
    {
        $ageGroup = AgeGroup::query()->create([
            'club_id' => 'bscn',
            'code' => 'infantis-h3f',
            'nome' => 'Infantis H3f',
            'ativo' => true,
        ]);
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'escalao' => [$ageGroup->id],
        ]);
        $training = Training::query()->create([
            'club_id' => 'bscn',
            'numero_treino' => 'H3F-READ-ONLY',
            'data' => now()->addDay()->toDateString(),
            'hora_inicio' => '18:00:00',
            'tipo_treino' => 'Técnica',
            'session_status' => 'scheduled',
        ]);
        $training->syncAgeGroupsWithPivot([$ageGroup->id]);

        $this->assertSame(0, TrainingAthlete::query()->count());

        $this->inertiaGetAs($athlete, route('portal.trainings'))
            ->assertOk()
            ->assertJsonPath('props.next_training', null)
            ->assertJsonCount(0, 'props.upcoming_trainings');

        $this->assertSame(0, TrainingAthlete::query()->count());
    }

    public function test_results_page_exposes_only_the_authenticated_athlete_results_from_the_active_club(): void
    {
        $athlete = User::factory()->athlete()->create(['tipo_membro' => ['atleta']]);
        $localCompetition = $this->competition('bscn', 'Competição local H3f');
        $foreignCompetition = $this->competition('other-club', 'Competição externa H3f');
        $localRace = $this->race($localCompetition);
        $foreignRace = $this->race($foreignCompetition);

        $this->result($localRace, $athlete, 61.25);
        $this->result($foreignRace, $athlete, 59.90);

        $this->inertiaGetAs($athlete, route('portal.results'))
            ->assertOk()
            ->assertJsonCount(1, 'props.latest_results')
            ->assertJsonPath('props.latest_results.0.event', 'Competição local H3f')
            ->assertJsonMissing(['event' => 'Competição externa H3f']);
    }

    public function test_training_projection_and_response_reject_records_from_another_club(): void
    {
        $athlete = User::factory()->athlete()->create(['tipo_membro' => ['atleta']]);
        $foreignTraining = Training::query()->create([
            'club_id' => 'other-club',
            'numero_treino' => 'H3F-FOREIGN',
            'data' => now()->addDay()->toDateString(),
            'hora_inicio' => '18:00:00',
            'tipo_treino' => 'Técnica',
            'session_status' => 'scheduled',
        ]);
        $foreignRecord = TrainingAthlete::query()->create([
            'treino_id' => $foreignTraining->id,
            'user_id' => $athlete->id,
            'presente' => false,
            'estado' => null,
        ]);

        $this->inertiaGetAs($athlete, route('portal.trainings'))
            ->assertOk()
            ->assertJsonPath('props.next_training', null)
            ->assertJsonCount(0, 'props.upcoming_trainings');

        $this->actingAs($athlete)
            ->patch(route('portal.trainings.update', $foreignRecord), [
                'action' => 'confirm_presence',
            ])
            ->assertNotFound();

        $this->assertFalse($foreignRecord->fresh()->presente);
    }

    public function test_agenda_keeps_the_canonical_competition_id_and_hides_foreign_projections(): void
    {
        $athlete = User::factory()->athlete()->create(['tipo_membro' => ['atleta']]);
        $localCompetition = $this->competition('bscn', 'Agenda local H3f');
        $foreignCompetition = $this->competition('other-club', 'Agenda externa H3f');
        $localEvent = $this->event($athlete, 'Agenda local H3f');
        $foreignEvent = $this->event($athlete, 'Agenda externa H3f');

        $this->projection($localCompetition, $localEvent);
        $this->projection($foreignCompetition, $foreignEvent);

        $this->inertiaGetAs($athlete, route('portal.events'))
            ->assertOk()
            ->assertJsonCount(1, 'props.active_items')
            ->assertJsonPath('props.active_items.0.event_id', $localEvent->id)
            ->assertJsonPath('props.active_items.0.competition_id', $localCompetition->id)
            ->assertJsonMissing(['title' => 'Agenda externa H3f']);
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

    private function competition(string $clubId, string $name): Competition
    {
        return Competition::query()->create([
            'club_id' => $clubId,
            'nome' => $name,
            'local' => 'Leiria',
            'data_inicio' => now()->addWeek()->toDateString(),
            'tipo' => 'piscina',
            'status' => 'scheduled',
        ]);
    }

    private function race(Competition $competition): Prova
    {
        return Prova::query()->create([
            'competicao_id' => $competition->id,
            'estilo' => 'LIVRE',
            'distancia_m' => 100,
            'genero' => 'M',
            'ordem_prova' => 1,
        ]);
    }

    private function result(Prova $race, User $athlete, float $time): Result
    {
        return Result::query()->create([
            'prova_id' => $race->id,
            'user_id' => $athlete->id,
            'tempo_oficial' => $time,
            'status' => 'ok',
        ]);
    }

    private function event(User $actor, string $title): Event
    {
        return Event::query()->create([
            'titulo' => $title,
            'descricao' => '',
            'data_inicio' => now()->addWeek()->toDateString(),
            'tipo' => 'competicao',
            'visibilidade' => 'publico',
            'estado' => 'agendado',
            'criado_por' => $actor->id,
        ]);
    }

    private function projection(Competition $competition, Event $event): CompetitionEventProjection
    {
        return CompetitionEventProjection::query()->create([
            'club_id' => $competition->club_id,
            'competition_id' => $competition->id,
            'event_id' => $event->id,
            'status' => 'linked',
            'projected_at' => now(),
        ]);
    }
}
