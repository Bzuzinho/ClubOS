<?php

namespace Tests\Feature\Eventos;

use App\Models\Event;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingEventAttendanceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_participant_is_blocked_for_training_event(): void
    {
        $admin = User::factory()->admin()->create();
        $athlete = User::factory()->athlete()->create();

        $trainingEvent = Event::create([
            'titulo' => 'Treino de teste',
            'descricao' => 'Evento treino',
            'data_inicio' => now()->toDateString(),
            'tipo' => 'treino',
            'criado_por' => $admin->id,
        ]);

        Training::create([
            'data' => now()->toDateString(),
            'tipo_treino' => 'tecnico',
            'evento_id' => $trainingEvent->id,
            'criado_por' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->postJson(
            route('eventos.participantes.add', ['event' => $trainingEvent->id]),
            ['user_id' => $athlete->id]
        );

        $response
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'As presencas deste treino sao geridas no modulo Desportivo.',
            ]);
    }

    public function test_update_participant_status_is_blocked_for_training_event(): void
    {
        $admin = User::factory()->admin()->create();
        $athlete = User::factory()->athlete()->create();

        $trainingEvent = Event::create([
            'titulo' => 'Treino de teste',
            'descricao' => 'Evento treino',
            'data_inicio' => now()->toDateString(),
            'tipo' => 'treino',
            'criado_por' => $admin->id,
        ]);

        Training::create([
            'data' => now()->toDateString(),
            'tipo_treino' => 'tecnico',
            'evento_id' => $trainingEvent->id,
            'criado_por' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->putJson(
            route('eventos.participantes.update', ['event' => $trainingEvent->id, 'user' => $athlete->id]),
            ['status' => 'confirmado']
        );

        $response
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'As presencas deste treino sao geridas no modulo Desportivo.',
            ]);
    }

    public function test_add_participant_is_allowed_for_non_training_event(): void
    {
        $admin = User::factory()->admin()->create();
        $athlete = User::factory()->athlete()->create();

        $event = Event::create([
            'titulo' => 'Prova de teste',
            'descricao' => 'Evento normal',
            'data_inicio' => now()->toDateString(),
            'tipo' => 'prova',
            'criado_por' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->postJson(
            route('eventos.participantes.add', ['event' => $event->id]),
            ['user_id' => $athlete->id]
        );

        $response->assertOk();

        $this->assertDatabaseHas('event_convocations', [
            'evento_id' => $event->id,
            'user_id' => $athlete->id,
            'estado_confirmacao' => 'pendente',
        ]);
    }
}
