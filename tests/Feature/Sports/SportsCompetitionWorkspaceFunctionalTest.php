<?php

namespace Tests\Feature\Sports;

use App\Models\Competition;
use App\Models\CompetitionRegistration;
use App\Models\ConvocationAthlete;
use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\Prova;
use App\Models\Result;
use App\Models\User;
use App\Services\Desportivo\CompetitionLifecycleService;
use App\Services\Desportivo\SportsCompetitionWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SportsCompetitionWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_is_scoped_to_current_club_and_uses_persisted_status(): void
    {
        Competition::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'nome' => 'Regional',
            'local' => 'Leiria',
            'data_inicio' => '2026-09-20',
            'tipo' => 'piscina',
            'status' => 'scheduled',
        ]);
        Competition::query()->create([
            'club_id' => 'other-club',
            'nome' => 'Outra competição',
            'local' => 'Porto',
            'data_inicio' => '2026-09-20',
            'tipo' => 'piscina',
            'status' => 'completed',
        ]);

        $payload = app(SportsCompetitionWorkspaceService::class)->workspace(Request::create('/desportivo/competicoes'));

        $this->assertCount(1, $payload['competitions']);
        $this->assertSame('Regional', $payload['competitions'][0]['name']);
        $this->assertSame('scheduled', $payload['competitions'][0]['status']);
    }

    public function test_convocations_are_resolved_only_by_projected_event_id_not_title_or_date(): void
    {
        $actor = User::factory()->create();
        $rightAthlete = User::factory()->create(['name' => 'Atleta Certo']);
        $wrongAthlete = User::factory()->create(['name' => 'Atleta Errado']);

        $competition = app(CompetitionLifecycleService::class)->create([
            'nome' => 'Meeting Igual',
            'data_inicio' => '2026-10-10',
            'local' => 'Leiria',
            'tipo' => 'piscina',
        ], $actor);
        $projectedEventId = $competition->eventProjection->event_id;

        $wrongEvent = Event::query()->create([
            'titulo' => 'Meeting Igual',
            'descricao' => '',
            'data_inicio' => '2026-10-10',
            'data_fim' => '2026-10-10',
            'local' => 'Leiria',
            'tipo' => 'competicao',
            'visibilidade' => 'publico',
            'estado' => 'agendado',
            'criado_por' => $actor->id,
            'recorrente' => false,
        ]);

        $wrongGroup = ConvocationGroup::query()->create([
            'evento_id' => $wrongEvent->id,
            'data_criacao' => now(),
            'criado_por' => $actor->id,
            'atletas_ids' => [$wrongAthlete->id],
            'tipo_custo' => 'por_salto',
            'valor_por_salto' => 0,
            'valor_por_estafeta' => 0,
            'valor_inscricao_unitaria' => 0,
            'publication_status' => 'draft',
        ]);
        ConvocationAthlete::query()->create([
            'convocatoria_grupo_id' => $wrongGroup->id,
            'atleta_id' => $wrongAthlete->id,
            'provas' => [],
            'estafetas' => 0,
            'presente' => false,
            'confirmado' => false,
        ]);

        $rightGroup = ConvocationGroup::query()->create([
            'evento_id' => $projectedEventId,
            'data_criacao' => now(),
            'criado_por' => $actor->id,
            'atletas_ids' => [$rightAthlete->id],
            'tipo_custo' => 'por_salto',
            'valor_por_salto' => 0,
            'valor_por_estafeta' => 0,
            'valor_inscricao_unitaria' => 0,
            'publication_status' => 'published',
        ]);
        ConvocationAthlete::query()->create([
            'convocatoria_grupo_id' => $rightGroup->id,
            'atleta_id' => $rightAthlete->id,
            'provas' => ['100L'],
            'estafetas' => 0,
            'presente' => true,
            'confirmado' => true,
        ]);

        $payload = app(SportsCompetitionWorkspaceService::class)->detail($competition);

        $this->assertSame((string) $projectedEventId, (string) $payload['projection']['event_id']);
        $this->assertCount(1, $payload['convocations']);
        $this->assertSame((string) $rightGroup->id, $payload['convocations'][0]['id']);
        $this->assertSame((string) $rightAthlete->id, $payload['convocations'][0]['athletes'][0]['user_id']);
    }

    public function test_detail_combines_program_registrations_and_results_without_copying_domains(): void
    {
        $actor = User::factory()->create();
        $athlete = User::factory()->create(['name' => 'Nadador']);
        $competition = app(CompetitionLifecycleService::class)->create([
            'nome' => 'Open',
            'data_inicio' => '2026-11-01',
            'local' => 'Coimbra',
            'tipo' => 'piscina',
        ], $actor);
        $race = Prova::query()->create([
            'competicao_id' => $competition->id,
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
        Result::query()->create([
            'prova_id' => $race->id,
            'user_id' => $athlete->id,
            'tempo_oficial' => 61.42,
            'posicao' => 3,
        ]);

        $payload = app(SportsCompetitionWorkspaceService::class)->detail($competition);

        $this->assertCount(1, $payload['program']);
        $this->assertCount(1, $payload['registrations']);
        $this->assertCount(1, $payload['results']);
        $this->assertSame(61.42, (float) $payload['results'][0]['official_time']);
        $this->assertSame(3, $payload['results'][0]['position']);
    }
}
