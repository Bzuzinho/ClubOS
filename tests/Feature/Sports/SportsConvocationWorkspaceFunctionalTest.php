<?php

namespace Tests\Feature\Sports;

use App\Models\ConvocationAthlete;
use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\EventConvocation;
use App\Models\SportsConvocationPublication;
use App\Models\User;
use App\Services\Desportivo\SportsConvocationWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SportsConvocationWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_uses_event_convocation_as_response_source_of_truth(): void
    {
        $actor = User::factory()->create();
        $athlete = User::factory()->create(['name' => 'Atleta']);
        $event = Event::query()->create([
            'titulo' => 'Meeting', 'descricao' => '', 'data_inicio' => '2026-09-19',
            'data_fim' => '2026-09-19', 'local' => 'Leiria', 'tipo' => 'competicao',
            'visibilidade' => 'publico', 'estado' => 'agendado', 'criado_por' => $actor->id,
            'recorrente' => false,
        ]);
        $group = ConvocationGroup::query()->create([
            'evento_id' => $event->id, 'data_criacao' => now(), 'criado_por' => $actor->id,
            'atletas_ids' => [$athlete->id], 'tipo_custo' => 'sem_custo',
            'valor_por_salto' => 0, 'valor_por_estafeta' => 0, 'valor_inscricao_unitaria' => 0,
            'publication_status' => 'draft', 'publication_version' => 1,
        ]);
        ConvocationAthlete::query()->create([
            'convocatoria_grupo_id' => $group->id, 'atleta_id' => $athlete->id,
            'provas' => ['100L'], 'estafetas' => 0, 'presente' => false, 'confirmado' => false,
        ]);
        EventConvocation::query()->create([
            'evento_id' => $event->id, 'user_id' => $athlete->id,
            'data_convocatoria' => now()->toDateString(), 'estado_confirmacao' => 'confirmado',
            'data_resposta' => now(), 'transporte_clube' => true,
        ]);

        $payload = app(SportsConvocationWorkspaceService::class)->detail($group);

        $this->assertSame(1, $payload['stats']['confirmed']);
        $this->assertSame(1, $payload['stats']['club_transport']);
        $this->assertSame('confirmado', $payload['athletes'][0]['response_status']);
        $this->assertFalse((bool) $group->convocationAthletes()->first()->confirmado);
    }

    public function test_detail_exposes_append_only_publication_history(): void
    {
        $actor = User::factory()->create();
        $event = Event::query()->create([
            'titulo' => 'Estágio', 'descricao' => '', 'data_inicio' => '2026-10-01',
            'data_fim' => '2026-10-01', 'local' => 'Rio Maior', 'tipo' => 'estagio',
            'visibilidade' => 'publico', 'estado' => 'agendado', 'criado_por' => $actor->id,
            'recorrente' => false,
        ]);
        $group = ConvocationGroup::query()->create([
            'evento_id' => $event->id, 'data_criacao' => now(), 'criado_por' => $actor->id,
            'atletas_ids' => [], 'tipo_custo' => 'sem_custo', 'publication_status' => 'draft',
            'publication_version' => 2,
        ]);
        SportsConvocationPublication::query()->create([
            'convocation_group_id' => $group->id, 'version' => 1,
            'fingerprint' => str_repeat('a', 64), 'published_by' => $actor->id,
            'published_at' => now()->subDay(), 'recipient_count' => 10,
            'communication_status' => 'dispatched', 'snapshot_json' => ['event_id' => $event->id],
        ]);
        SportsConvocationPublication::query()->create([
            'convocation_group_id' => $group->id, 'version' => 2,
            'fingerprint' => str_repeat('b', 64), 'published_by' => $actor->id,
            'published_at' => now(), 'recipient_count' => 12,
            'communication_status' => 'dispatched', 'snapshot_json' => ['event_id' => $event->id],
        ]);

        $payload = app(SportsConvocationWorkspaceService::class)->detail($group);

        $this->assertCount(2, $payload['publications']);
        $this->assertSame(2, $payload['publications'][0]['version']);
        $this->assertSame(1, $payload['publications'][1]['version']);
    }

    public function test_workspace_routes_are_registered(): void
    {
        foreach ([
            'desportivo.convocatorias.index', 'desportivo.convocatorias.show',
            'desportivo.convocatorias.store', 'desportivo.convocatorias.update',
            'desportivo.convocatorias.publish',
        ] as $name) {
            $this->assertTrue(Route::has($name), $name.' route missing');
        }
    }
}
