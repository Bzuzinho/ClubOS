<?php

namespace Tests\Feature\Sports;

use App\Models\Competition;
use App\Models\CompetitionEventProjection;
use App\Models\CompetitionRegistration;
use App\Models\Event;
use App\Models\Prova;
use App\Models\Result;
use App\Models\User;
use App\Services\Desportivo\CompetitionLifecycleService;
use App\Services\Desportivo\Queries\GetCompetitionListSummary;
use App\Services\Desportivo\Queries\GetCompetitionResultsView;
use App\Services\Eventos\EventLifecycleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsDesportivoAccess;
use Tests\TestCase;

class CompetitionEventProjectionFoundationTest extends TestCase
{
    use RefreshDatabase;
    use GrantsDesportivoAccess;

    public function test_desportivo_creation_creates_exactly_one_event_projection(): void
    {
        $actor = User::factory()->create();
        $this->grantDesportivoAccess($actor);

        $response = $this->actingAs($actor)->postJson('/api/desportivo/competitions', [
            'nome' => 'Open F4',
            'data_inicio' => '2026-09-12',
            'data_fim' => '2026-09-13',
            'local' => 'Piscina Municipal',
            'tipo_prova' => 'piscina',
        ])->assertCreated();

        $competition = Competition::query()->findOrFail($response->json('id'));
        $projection = CompetitionEventProjection::query()
            ->where('competition_id', $competition->id)
            ->firstOrFail();

        $this->assertSame(config('sports.club_id', 'bscn'), $competition->club_id);
        $this->assertSame('linked', $projection->status);
        $this->assertNotNull($projection->event_id);
        $this->assertSame((string) $projection->event_id, (string) $competition->evento_id);
        $this->assertSame(1, CompetitionEventProjection::query()->where('competition_id', $competition->id)->count());

        $this->assertDatabaseHas('events', [
            'id' => $projection->event_id,
            'titulo' => 'Open F4',
            'tipo' => 'competicao',
            'recorrente' => false,
        ]);
    }

    public function test_competition_updates_reuse_the_same_projection_event(): void
    {
        $actor = User::factory()->create();
        $competition = app(CompetitionLifecycleService::class)->create([
            'nome' => 'Regional F4',
            'data_inicio' => '2026-09-20',
            'data_fim' => '2026-09-20',
            'local' => 'Leiria',
            'tipo' => 'piscina',
        ], $actor);

        $eventId = $competition->eventProjection->event_id;

        app(CompetitionLifecycleService::class)->update($competition, [
            'nome' => 'Regional F4 Atualizado',
            'local' => 'Marinha Grande',
        ], $actor);
        app(CompetitionLifecycleService::class)->update($competition->fresh(), [
            'data_fim' => '2026-09-21',
        ], $actor);

        $projection = CompetitionEventProjection::query()
            ->where('competition_id', $competition->id)
            ->firstOrFail();

        $this->assertSame((string) $eventId, (string) $projection->event_id);
        $this->assertSame(1, CompetitionEventProjection::query()->where('competition_id', $competition->id)->count());
        $this->assertSame(1, Event::query()->whereKey($eventId)->count());
        $this->assertDatabaseHas('events', [
            'id' => $eventId,
            'titulo' => 'Regional F4 Atualizado',
            'local' => 'Marinha Grande',
        ]);
    }

    public function test_events_never_create_competition_masters_even_when_recurring_competition_category(): void
    {
        $actor = User::factory()->create();

        app(EventLifecycleService::class)->create([
            'titulo' => 'Calendário externo',
            'descricao' => 'Evento informativo, sem master desportivo.',
            'data_inicio' => '2026-09-07',
            'data_fim' => '2026-09-07',
            'local' => 'Leiria',
            'tipo' => 'competicao',
            'visibilidade' => 'publico',
            'estado' => 'agendado',
            'criado_por' => $actor->id,
            'recorrente' => true,
            'recorrencia_data_inicio' => '2026-09-07',
            'recorrencia_data_fim' => '2026-09-21',
            'recorrencia_dias_semana' => ['1'],
        ], []);

        $this->assertSame(0, Competition::query()->count());
        $this->assertSame(3, Event::query()->count());
    }

    public function test_event_edit_cannot_change_competition_owned_projection_fields(): void
    {
        $actor = User::factory()->create();
        $competition = app(CompetitionLifecycleService::class)->create([
            'nome' => 'Nacional F4',
            'data_inicio' => '2026-10-01',
            'data_fim' => '2026-10-02',
            'local' => 'Coimbra',
            'tipo' => 'piscina',
        ], $actor);

        $event = Event::query()->findOrFail($competition->eventProjection->event_id);

        app(EventLifecycleService::class)->update($event, [
            'titulo' => 'Tentativa de alterar master',
            'data_inicio' => '2026-12-31',
            'local' => 'Outro local',
            'tipo' => 'outro',
            'descricao' => 'Logística atualizada em Eventos.',
        ], []);

        $event->refresh();
        $competition->refresh();

        $this->assertSame('Nacional F4', $competition->nome);
        $this->assertSame('Nacional F4', $event->titulo);
        $this->assertSame('Coimbra', $event->local);
        $this->assertSame('competicao', $event->tipo);
        $this->assertSame('Logística atualizada em Eventos.', $event->descricao);
    }

    public function test_deleting_projected_event_never_deletes_competition_technical_history(): void
    {
        $actor = User::factory()->create();
        $athlete = User::factory()->create();
        $competition = app(CompetitionLifecycleService::class)->create([
            'nome' => 'Histórico F4',
            'data_inicio' => '2026-10-10',
            'data_fim' => '2026-10-10',
            'local' => 'Caldas da Rainha',
            'tipo' => 'piscina',
        ], $actor);

        $prova = Prova::query()->create([
            'competicao_id' => $competition->id,
            'estilo' => 'LIVRE',
            'distancia_m' => 100,
            'genero' => 'M',
        ]);
        $registration = CompetitionRegistration::query()->create([
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
        ]);
        $result = Result::query()->create([
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'tempo_oficial' => 59.10,
            'posicao' => 1,
        ]);

        $event = Event::query()->findOrFail($competition->eventProjection->event_id);
        app(EventLifecycleService::class)->delete($event);

        $this->assertDatabaseHas('competitions', ['id' => $competition->id]);
        $this->assertDatabaseHas('provas', ['id' => $prova->id, 'competicao_id' => $competition->id]);
        $this->assertDatabaseHas('competition_registrations', ['id' => $registration->id]);
        $this->assertDatabaseHas('results', ['id' => $result->id]);
        $this->assertDatabaseHas('competition_event_projections', [
            'competition_id' => $competition->id,
            'event_id' => null,
            'status' => 'detached',
        ]);
    }

    public function test_archiving_competition_preserves_master_and_cancels_projection(): void
    {
        $actor = User::factory()->create();
        $competition = app(CompetitionLifecycleService::class)->create([
            'nome' => 'Arquivo F4',
            'data_inicio' => '2026-11-01',
            'local' => 'Lisboa',
            'tipo' => 'piscina',
        ], $actor);

        $archived = app(CompetitionLifecycleService::class)->archive($competition, $actor);
        $event = Event::query()->findOrFail($archived->eventProjection->event_id);

        $this->assertSame('archived', $archived->status);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame('cancelado', $event->getRawOriginal('estado'));
        $this->assertDatabaseHas('competitions', ['id' => $competition->id]);
    }

    public function test_competition_queries_are_scoped_to_current_club(): void
    {
        Competition::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'nome' => 'Clube atual',
            'local' => 'Leiria',
            'data_inicio' => '2026-12-01',
            'tipo' => 'piscina',
        ]);
        $foreign = Competition::query()->create([
            'club_id' => 'other-club',
            'nome' => 'Outro clube',
            'local' => 'Porto',
            'data_inicio' => '2026-12-02',
            'tipo' => 'piscina',
        ]);

        $summary = app(GetCompetitionListSummary::class)(-1);
        $this->assertSame(['Clube atual'], $summary->pluck('nome')->values()->all());

        $this->expectException(ModelNotFoundException::class);
        app(GetCompetitionResultsView::class)($foreign->id);
    }
}
