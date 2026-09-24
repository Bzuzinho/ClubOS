<?php

namespace Tests\Feature\Eventos;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AgeGroup;
use App\Models\AthleteSportsData;
use App\Models\Competition;
use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\EventConvocation;
use App\Models\EventType;
use App\Models\FinancialEntry;
use App\Models\Movement;
use App\Models\ResultProva;
use App\Models\User;
use App\Services\Eventos\SyncConvocationGroupFinancialMovementAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventosLifecycleAndMemberSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_crud_creates_recurring_events_and_age_groups_without_competition_masters(): void
    {
        $admin = User::factory()->admin()->create();
        $ageGroup = AgeGroup::query()->create([
            'nome' => 'Master',
            'idade_minima' => 25,
            'ativo' => true,
        ]);
        $firstDate = now()->addDays(10)->startOfDay();
        $lastDate = $firstDate->copy()->addWeek();

        $response = $this->actingAs($admin)->post(route('eventos.store'), [
            'titulo' => 'Circuito recorrente',
            'descricao' => 'Prova de teste',
            'data_inicio' => $firstDate->toDateString(),
            'data_fim' => $firstDate->toDateString(),
            'tipo' => 'prova',
            'visibilidade' => 'restrito',
            'estado' => 'agendado',
            'escaloes_elegiveis' => [$ageGroup->id],
            'transporte_necessario' => true,
            'transporte_detalhes' => 'Autocarro do clube',
            'hora_partida' => '07:30',
            'local_partida' => 'Piscina',
            'taxa_inscricao' => 5,
            'centro_custo_id' => null,
            'observacoes' => 'Levar equipamento oficial',
            'recorrente' => true,
            'recorrencia_data_inicio' => $firstDate->toDateString(),
            'recorrencia_data_fim' => $lastDate->toDateString(),
            'recorrencia_dias_semana' => [(string) $firstDate->dayOfWeek],
        ]);

        $response->assertRedirect(route('eventos.index'))->assertSessionHasNoErrors();

        $parent = Event::query()->whereNull('evento_pai_id')->firstOrFail();
        $child = Event::query()->where('evento_pai_id', $parent->id)->firstOrFail();

        $this->assertSame($lastDate->toDateString(), $child->data_inicio?->toDateString());
        $this->assertSame([$ageGroup->id], $parent->ageGroups()->pluck('age_groups.id')->all());
        $this->assertSame([$ageGroup->id], $child->ageGroups()->pluck('age_groups.id')->all());
        $this->assertSame(0, Competition::query()->count());
    }

    public function test_web_create_accepts_the_non_recurring_browser_payload_with_empty_optional_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $eventDate = now()->addDay()->toDateString();

        $response = $this->actingAs($admin)->post(route('eventos.store'), [
            'titulo' => 'Evento criado no formulário',
            'descricao' => '',
            'data_inicio' => $eventDate,
            'hora_inicio' => '',
            'data_fim' => '',
            'hora_fim' => '',
            'local' => '',
            'local_detalhes' => '',
            'tipo' => 'evento',
            'tipo_piscina' => '',
            'visibilidade' => 'privado',
            'escaloes_elegiveis' => [],
            'transporte_necessario' => false,
            'transporte_detalhes' => '',
            'hora_partida' => '',
            'local_partida' => '',
            'taxa_inscricao' => '',
            'custo_inscricao_por_prova' => '',
            'custo_inscricao_por_salto' => '',
            'custo_inscricao_estafeta' => '',
            'centro_custo_id' => '',
            'observacoes' => '',
            'estado' => 'rascunho',
            'recorrente' => false,
            'recorrencia_data_inicio' => null,
            'recorrencia_data_fim' => null,
            'recorrencia_dias_semana' => [],
        ]);

        $response->assertRedirect(route('eventos.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('events', [
            'titulo' => 'Evento criado no formulário',
            'tipo' => 'evento',
            'visibilidade' => 'privado',
            'estado' => 'rascunho',
            'criado_por' => $admin->id,
        ]);
        $event = Event::query()->where('titulo', 'Evento criado no formulário')->sole();

        $this->assertSame($eventDate, $event->data_inicio?->toDateString());
        $this->assertNull($event->recorrencia_dias_semana);
    }

    public function test_eventos_index_returns_every_editable_field_and_canonical_age_groups(): void
    {
        $admin = User::factory()->admin()->create();
        $ageGroup = AgeGroup::query()->create(['nome' => 'Juvenil', 'ativo' => true]);
        $event = Event::query()->create([
            'titulo' => 'Evento completo',
            'descricao' => 'Descrição',
            'data_inicio' => now()->addDay()->toDateString(),
            'data_fim' => now()->addDays(2)->toDateString(),
            'local' => 'Piscina Municipal',
            'local_detalhes' => 'Rua principal',
            'tipo' => 'prova',
            'tipo_piscina' => 'piscina_25m',
            'visibilidade' => 'restrito',
            'transporte_necessario' => true,
            'transporte_detalhes' => 'Detalhes',
            'hora_partida' => '08:00',
            'local_partida' => 'Sede',
            'taxa_inscricao' => 10,
            'custo_inscricao_por_prova' => 2,
            'observacoes' => 'Notas',
            'estado' => 'agendado',
            'criado_por' => $admin->id,
        ]);
        $event->ageGroups()->sync([$ageGroup->id]);

        $inertiaVersion = app(HandleInertiaRequests::class)->version(request());
        $response = $this->actingAs($admin)->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) $inertiaVersion,
        ])->get(route('eventos.index'));

        $response->assertOk()
            ->assertJsonPath('props.eventos.0.local_detalhes', 'Rua principal')
            ->assertJsonPath('props.eventos.0.transporte_detalhes', 'Detalhes')
            ->assertJsonPath('props.eventos.0.local_partida', 'Sede')
            ->assertJsonPath('props.eventos.0.observacoes', 'Notas')
            ->assertJsonPath('props.eventos.0.escaloes_elegiveis.0', $ageGroup->id);
    }

    public function test_configured_competition_category_remains_event_only(): void
    {
        $admin = User::factory()->admin()->create();
        EventType::query()->create([
            'nome' => 'Prova Oficial',
            'categoria' => 'prova',
            'visibilidade_default' => 'publico',
            'ativo' => true,
        ]);

        $this->actingAs($admin)->post(route('eventos.store'), [
            'titulo' => 'Open configurado',
            'descricao' => '',
            'data_inicio' => now()->addWeek()->toDateString(),
            'tipo' => 'prova_oficial',
            'visibilidade' => 'publico',
            'estado' => 'agendado',
        ])->assertRedirect(route('eventos.index'));

        $event = Event::query()->where('titulo', 'Open configurado')->firstOrFail();

        $this->assertSame(0, Competition::query()->count());
        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'tipo' => 'prova_oficial',
        ]);
    }

    public function test_member_can_answer_a_convocation_until_the_end_of_the_event_day(): void
    {
        $athlete = User::factory()->athlete()->create();
        $event = Event::query()->create([
            'titulo' => 'Evento de hoje',
            'descricao' => '',
            'data_inicio' => now()->toDateString(),
            'tipo' => 'prova',
            'estado' => 'agendado',
            'criado_por' => $athlete->id,
        ]);
        $convocation = EventConvocation::query()->create([
            'evento_id' => $event->id,
            'user_id' => $athlete->id,
            'data_convocatoria' => now()->toDateString(),
            'estado_confirmacao' => 'pendente',
        ]);

        $this->actingAs($athlete)->patch(route('portal.events.update', $convocation), [
            'action' => 'confirm_presence',
        ])->assertRedirect(route('portal.events'));

        $this->assertSame('confirmado', $convocation->fresh()->estado_confirmacao);
    }

    public function test_portal_shows_general_public_events_without_exposing_private_events(): void
    {
        $athlete = User::factory()->athlete()->create();
        $publicEvent = Event::query()->create([
            'titulo' => 'Evento público geral',
            'descricao' => '',
            'data_inicio' => now()->addWeek()->toDateString(),
            'tipo' => 'evento_interno',
            'visibilidade' => 'publico',
            'estado' => 'agendado',
            'criado_por' => $athlete->id,
        ]);
        Event::query()->create([
            'titulo' => 'Evento privado',
            'descricao' => '',
            'data_inicio' => now()->addWeek()->toDateString(),
            'tipo' => 'evento_interno',
            'visibilidade' => 'privado',
            'estado' => 'agendado',
            'criado_por' => $athlete->id,
        ]);

        $this->actingAs($athlete)
            ->get(route('portal.events'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/Events')
                ->has('active_items', 1)
                ->where('active_items.0.event_id', $publicEvent->id)
            );
    }

    public function test_deleting_an_event_cannot_bypass_a_financially_protected_convocation(): void
    {
        $admin = User::factory()->admin()->create();
        $athlete = User::factory()->athlete()->create();
        $event = Event::query()->create([
            'titulo' => 'Evento financeiramente protegido',
            'descricao' => '',
            'data_inicio' => now()->addWeek()->toDateString(),
            'tipo' => 'prova',
            'estado' => 'agendado',
            'criado_por' => $admin->id,
        ]);
        $group = ConvocationGroup::query()->create([
            'id' => (string) Str::uuid(),
            'evento_id' => $event->id,
            'data_criacao' => now(),
            'criado_por' => $admin->id,
            'atletas_ids' => [$athlete->id],
            'tipo_custo' => 'por_salto',
            'valor_por_salto' => 2,
            'valor_inscricao_unitaria' => 10,
        ]);
        app(SyncConvocationGroupFinancialMovementAction::class)->execute($group);
        $group = $group->fresh();
        $groupId = (string) $group->id;
        $movement = Movement::query()->findOrFail($group->movimento_id);
        $movement->update(['estado_pagamento' => 'parcial']);
        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Convocatoria',
            'descricao' => 'Liquidação parcial de teste',
            'valor' => (float) $movement->valor_total,
            'valor_pago' => 1,
            'valor_em_aberto' => max((float) $movement->valor_total - 1, 0),
            'estado' => 'parcial',
            'origem_tipo' => 'movement',
            'origem_id' => $movement->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('eventos.destroy', $event))
            ->assertRedirect()
            ->assertSessionHasErrors('convocation_group');

        $this->assertDatabaseHas('events', ['id' => $event->id]);
        $this->assertDatabaseHas('convocation_groups', ['id' => $groupId]);
    }

    public function test_member_result_without_an_event_normalizes_the_empty_uuid_reference(): void
    {
        $admin = User::factory()->admin()->create();
        $athlete = User::factory()->athlete()->create();
        $resultId = (string) Str::uuid();

        $this->actingAs($admin)->putJson('/api/kv/club-resultados-provas', [
            'value' => [[
                'id' => $resultId,
                'atleta_id' => $athlete->id,
                'evento_id' => '',
                'evento_nome' => 'Torneio externo',
                'prova' => '100 Livres',
                'local' => 'Piscina exterior',
                'data' => now()->toDateString(),
                'piscina' => 'piscina_25m',
                'tempo_final' => '01:02.30',
            ]],
        ])->assertOk();

        $result = ResultProva::query()->findOrFail($resultId);

        $this->assertNull($result->evento_id);
        $this->assertSame($athlete->id, $result->atleta_id);
        $this->assertTrue($athlete->resultProvas()->whereKey($resultId)->exists());
    }

    private function createAthlete(AgeGroup $ageGroup): User
    {
        $athlete = User::factory()->athlete()->create(['estado' => 'ativo']);
        AthleteSportsData::query()->create([
            'user_id' => $athlete->id,
            'escalao_id' => $ageGroup->id,
            'ativo' => true,
        ]);

        return $athlete;
    }
}
